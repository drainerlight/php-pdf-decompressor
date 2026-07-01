<?php

declare(strict_types=1);

namespace PdfDecompressor\CrossReference;

use PdfDecompressor\Exception\CrossReferenceException;
use PdfDecompressor\Filter\StreamDecoder;
use PdfDecompressor\Lexer\Token;
use PdfDecompressor\Lexer\Tokenizer;
use PdfDecompressor\ObjectStream\ObjectStream;
use PdfDecompressor\Parser\ObjectParser;
use PdfDecompressor\Reader\ByteReader;
use PdfDecompressor\Type\PdfArray;
use PdfDecompressor\Type\PdfDictionary;
use PdfDecompressor\Type\PdfName;
use PdfDecompressor\Type\PdfNumeric;
use PdfDecompressor\Type\PdfObject;
use PdfDecompressor\Type\PdfReference;
use PdfDecompressor\Type\PdfStream;

/**
 * Builds a complete {@see CrossReferenceTable} for a PDF, supporting both the
 * classic cross-reference table with trailer (ISO 32000-1, 7.5.4) and the
 * cross-reference stream (7.5.8), including /Prev chains and hybrid-reference
 * files (7.5.8.4, /XRefStm).
 *
 * Sections are processed newest-first; the first entry seen for an object wins,
 * which is exactly the incremental-update precedence the spec requires.
 *
 * When the regular startxref-driven parse is impossible (missing/garbage
 * startxref, corrupt xref), {@see rebuild()} reconstructs the table by scanning
 * the file for object definitions.
 */
final class CrossReferenceReader
{
    public function read(string $bytes): CrossReferenceTable
    {
        $queue   = [$this->findStartxref($bytes)];
        $visited = [];
        $entries = [];
        $trailer = null;

        while ($queue !== []) {
            $offset = (int) array_shift($queue);
            if ($offset < 0 || $offset >= strlen($bytes) || isset($visited[$offset])) {
                continue;
            }
            $visited[$offset] = true;

            $section = $this->readSection($bytes, $offset);

            foreach ($section['entries'] as $objectNumber => $entry) {
                if (!array_key_exists($objectNumber, $entries)) {
                    $entries[$objectNumber] = $entry;
                }
            }

            $dictionary = $section['dict'];
            if ($trailer === null) {
                $trailer = $dictionary;
            }

            // Hybrid: the /XRefStm supplements this table (older than it, newer
            // than /Prev), so enqueue it before /Prev.
            $xrefStm = $this->intValue($dictionary->get('XRefStm'));
            if ($xrefStm !== null) {
                $queue[] = $xrefStm;
            }
            $prev = $this->intValue($dictionary->get('Prev'));
            if ($prev !== null) {
                $queue[] = $prev;
            }
        }

        if ($trailer === null) {
            throw new CrossReferenceException('No trailer / cross-reference dictionary found.');
        }

        return new CrossReferenceTable($entries, $trailer);
    }

    private function findStartxref(string $bytes): int
    {
        $pos = strrpos($bytes, 'startxref');
        if ($pos === false) {
            throw new CrossReferenceException("'startxref' keyword not found.");
        }

        $reader = new ByteReader($bytes);
        $reader->setPosition($pos + strlen('startxref'));
        $token = (new Tokenizer($reader))->nextToken();
        if (!$token->is(Token::NUMBER)) {
            throw new CrossReferenceException("'startxref' is not followed by an offset.");
        }

        return (int) $token->getValue();
    }

    /**
     * @return array{entries: array<int,CrossReferenceEntry>, dict: PdfDictionary}
     */
    private function readSection(string $bytes, int $offset): array
    {
        $reader = new ByteReader($bytes);
        $reader->setPosition($offset);
        $tokenizer = new Tokenizer($reader);

        if ($tokenizer->nextToken()->isKeyword('xref')) {
            return $this->readClassicSection($tokenizer);
        }

        // Otherwise this must be an "N G obj" cross-reference stream.
        $reader->setPosition($offset);
        $indirect = (new ObjectParser(new Tokenizer($reader)))->parseIndirectObject();
        $value    = $indirect->getValue();
        if (!$value instanceof PdfStream) {
            throw new CrossReferenceException("Expected xref table or stream at offset {$offset}.");
        }

        return $this->readStreamSection($value);
    }

    /**
     * @return array{entries: array<int,CrossReferenceEntry>, dict: PdfDictionary}
     */
    private function readClassicSection(Tokenizer $tokenizer): array
    {
        $entries = [];

        while (true) {
            $token = $tokenizer->nextToken();
            if ($token->isKeyword('trailer')) {
                break;
            }
            if (!$token->is(Token::NUMBER)) {
                throw new CrossReferenceException('Malformed xref subsection header.');
            }

            $start = (int) $token->getValue();
            $count = $this->expectNumber($tokenizer, 'xref subsection count');

            for ($i = 0; $i < $count; $i++) {
                $offset     = $this->expectNumber($tokenizer, 'xref entry offset');
                $generation = $this->expectNumber($tokenizer, 'xref entry generation');
                $typeToken  = $tokenizer->nextToken();
                if (!$typeToken->is(Token::KEYWORD)) {
                    throw new CrossReferenceException('Malformed xref entry type.');
                }

                $objectNumber = $start + $i;
                if ($typeToken->getValue() === 'n') {
                    $entries[$objectNumber] = CrossReferenceEntry::uncompressed($offset, $generation);
                } elseif ($typeToken->getValue() === 'f') {
                    $entries[$objectNumber] = CrossReferenceEntry::free();
                } else {
                    throw new CrossReferenceException(
                        "Invalid xref entry type '{$typeToken->getValue()}'."
                    );
                }
            }
        }

        $trailer = (new ObjectParser($tokenizer))->parseObject();
        if (!$trailer instanceof PdfDictionary) {
            throw new CrossReferenceException('Trailer is not a dictionary.');
        }

        return ['entries' => $entries, 'dict' => $trailer];
    }

    /**
     * @return array{entries: array<int,CrossReferenceEntry>, dict: PdfDictionary}
     */
    private function readStreamSection(PdfStream $stream): array
    {
        $dictionary = $stream->getDictionary();

        $widths = $this->intArray($dictionary->get('W'));
        if ($widths === null || count($widths) < 3) {
            throw new CrossReferenceException('Cross-reference stream has an invalid /W array.');
        }
        [$w0, $w1, $w2] = $widths;
        $entryLength    = $w0 + $w1 + $w2;
        if ($entryLength <= 0) {
            throw new CrossReferenceException('Cross-reference stream /W widths sum to zero.');
        }

        $size  = $this->intValue($dictionary->get('Size'));
        $index = $this->intArray($dictionary->get('Index'));
        if ($index === null) {
            $index = [0, $size ?? 0];
        }

        $data    = StreamDecoder::decode($stream);
        $entries = [];
        $pos     = 0;
        $length  = strlen($data);

        for ($s = 0; $s + 1 < count($index); $s += 2) {
            $start = $index[$s];
            $count = $index[$s + 1];
            for ($i = 0; $i < $count; $i++) {
                if ($pos + $entryLength > $length) {
                    break 2; // tolerate a truncated stream rather than reading past it
                }
                // A zero-width type field defaults to type 1 (7.5.8.3).
                $type = $w0 === 0 ? 1 : $this->readBigEndian($data, $pos, $w0);
                $pos += $w0;
                $field2 = $this->readBigEndian($data, $pos, $w1);
                $pos   += $w1;
                $field3 = $this->readBigEndian($data, $pos, $w2);
                $pos   += $w2;

                $objectNumber = $start + $i;
                if ($type === 1) {
                    $entries[$objectNumber] = CrossReferenceEntry::uncompressed($field2, $field3);
                } elseif ($type === 2) {
                    $entries[$objectNumber] = CrossReferenceEntry::compressed($field2, $field3);
                } else {
                    $entries[$objectNumber] = CrossReferenceEntry::free();
                }
            }
        }

        return ['entries' => $entries, 'dict' => $dictionary];
    }

    private function readBigEndian(string $data, int $pos, int $length): int
    {
        $value = 0;
        for ($i = 0; $i < $length; $i++) {
            $value = ($value << 8) | ord($data[$pos + $i]);
        }
        return $value;
    }

    private function expectNumber(Tokenizer $tokenizer, string $what): int
    {
        $token = $tokenizer->nextToken();
        if (!$token->is(Token::NUMBER)) {
            throw new CrossReferenceException("Expected a number for {$what}.");
        }
        return (int) $token->getValue();
    }

    private function intValue(?PdfObject $object): ?int
    {
        return ($object instanceof PdfNumeric && $object->isInteger()) ? (int) $object->getValue() : null;
    }

    /**
     * @return int[]|null
     */
    private function intArray(?PdfObject $object): ?array
    {
        if (!$object instanceof PdfArray) {
            return null;
        }
        $result = [];
        foreach ($object->getItems() as $item) {
            if (!$item instanceof PdfNumeric) {
                return null;
            }
            $result[] = (int) $item->getValue();
        }
        return $result;
    }

    /**
     * Reconstruct the cross-reference information by scanning the whole file for
     * object definitions ("N G obj"), unpacking any object streams found, and
     * recovering /Root (and /Info, /Encrypt) from the raw bytes. Used when the
     * regular startxref-driven parse is not possible.
     */
    public function rebuild(string $bytes): CrossReferenceTable
    {
        $entries = $this->scanObjectDefinitions($bytes);
        $this->addObjectStreamEntries($bytes, $entries);
        return new CrossReferenceTable($entries, $this->recoverTrailer($bytes));
    }

    /**
     * @return array<int,CrossReferenceEntry>
     */
    private function scanObjectDefinitions(string $bytes): array
    {
        $entries = [];
        // Object definitions start a line; anchoring on the preceding EOL avoids
        // matching "N G obj"-like byte sequences inside binary stream data.
        if (preg_match_all(
            '~[\r\n][ \t]*(\d{1,10})[ \t]+(\d{1,5})[ \t]+obj\b~',
            $bytes,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            foreach ($matches[1] as $i => $numberMatch) {
                // Later definitions override earlier ones (incremental updates).
                $entries[(int) $numberMatch[0]] = CrossReferenceEntry::uncompressed(
                    (int) $numberMatch[1],
                    (int) $matches[2][$i][0]
                );
            }
        }
        return $entries;
    }

    /**
     * @param array<int,CrossReferenceEntry> $entries
     */
    private function addObjectStreamEntries(string $bytes, array &$entries): void
    {
        foreach (array_keys($entries) as $number) {
            $entry = $entries[$number];
            if (!$entry->isUncompressed()) {
                continue;
            }
            try {
                $value = (new ObjectParser(new Tokenizer($this->readerAt($bytes, $entry->getOffset()))))
                    ->parseIndirectObject()
                    ->getValue();
            } catch (\Exception $e) {
                continue;
            }
            if (!$value instanceof PdfStream) {
                continue;
            }
            $type = $value->getDictionary()->get('Type');
            if (!$type instanceof PdfName || $type->getValue() !== 'ObjStm') {
                continue;
            }
            try {
                $objectStream = ObjectStream::fromStream($value);
            } catch (\Exception $e) {
                continue;
            }
            $index = 0;
            foreach ($objectStream->getObjectNumbers() as $memberNumber) {
                if (!isset($entries[$memberNumber])) {
                    $entries[$memberNumber] = CrossReferenceEntry::compressed($number, $index);
                }
                $index++;
            }
        }
    }

    private function recoverTrailer(string $bytes): PdfDictionary
    {
        $root = $this->lastReferenceInBytes($bytes, 'Root');
        if ($root === null) {
            throw new CrossReferenceException('Cross-reference rebuild failed: no /Root reference found.');
        }

        $entries = ['Root' => $root];
        foreach (['Info', 'Encrypt'] as $key) {
            $reference = $this->lastReferenceInBytes($bytes, $key);
            if ($reference !== null) {
                $entries[$key] = $reference;
            }
        }
        return new PdfDictionary($entries);
    }

    private function lastReferenceInBytes(string $bytes, string $key): ?PdfReference
    {
        if (!preg_match_all('~/' . $key . '\s+(\d+)\s+(\d+)\s+R\b~', $bytes, $matches)) {
            return null;
        }
        $last = count($matches[1]) - 1;
        return new PdfReference((int) $matches[1][$last], (int) $matches[2][$last]);
    }

    private function readerAt(string $bytes, int $offset): ByteReader
    {
        $reader = new ByteReader($bytes);
        $reader->setPosition($offset);
        return $reader;
    }
}
