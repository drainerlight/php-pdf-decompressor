<?php

declare(strict_types=1);

namespace PdfDecompressor\ObjectStream;

use PdfDecompressor\Exception\ParserException;
use PdfDecompressor\Filter\StreamDecoder;
use PdfDecompressor\Lexer\Token;
use PdfDecompressor\Lexer\Tokenizer;
use PdfDecompressor\Parser\ObjectParser;
use PdfDecompressor\Reader\ByteReader;
use PdfDecompressor\Type\PdfNumeric;
use PdfDecompressor\Type\PdfObject;
use PdfDecompressor\Type\PdfStream;

/**
 * A parsed object stream (ISO 32000-1, 7.5.7).
 *
 * The decoded body is a header of N "objectNumber offset" integer pairs followed
 * by the object bodies (each offset is relative to /First). Contained objects are
 * plain values without the "N G obj … endobj" wrapper and are never streams.
 */
final class ObjectStream
{
    /** @var string decoded stream body */
    private $data;

    /** @var int /First: byte offset of the first object within $data */
    private $first;

    /** @var array<int,array{0:int,1:int}> index => [objectNumber, relativeOffset] */
    private $pairs;

    /** @var array<int,int> objectNumber => index */
    private $indexByNumber;

    /** @var int[] sorted, unique absolute start offsets of the contained objects */
    private $sortedStarts;

    /**
     * @param array<int,array{0:int,1:int}> $pairs
     */
    private function __construct(string $data, int $first, array $pairs)
    {
        $this->data          = $data;
        $this->first         = $first;
        $this->pairs         = $pairs;
        $this->indexByNumber = [];
        $starts              = [];
        foreach ($pairs as $index => $pair) {
            $this->indexByNumber[$pair[0]] = $index;
            $starts[]                      = $first + $pair[1];
        }
        sort($starts);
        $this->sortedStarts = array_values(array_unique($starts));
    }

    public static function fromStream(PdfStream $stream): self
    {
        $dictionary = $stream->getDictionary();
        $n          = self::intValue($dictionary->get('N'));
        $first      = self::intValue($dictionary->get('First'));
        if ($n === null || $first === null) {
            throw new ParserException('Object stream is missing /N or /First.');
        }

        $data      = StreamDecoder::decode($stream);
        $tokenizer = new Tokenizer(new ByteReader($data));

        $pairs = [];
        for ($i = 0; $i < $n; $i++) {
            $numberToken = $tokenizer->nextToken();
            $offsetToken = $tokenizer->nextToken();
            if (!$numberToken->is(Token::NUMBER) || !$offsetToken->is(Token::NUMBER)) {
                throw new ParserException('Malformed object stream header.');
            }
            $pairs[] = [(int) $numberToken->getValue(), (int) $offsetToken->getValue()];
        }

        return new self($data, $first, $pairs);
    }

    public function count(): int
    {
        return count($this->pairs);
    }

    /**
     * @return int[] the object numbers contained, in stream order
     */
    public function getObjectNumbers(): array
    {
        return array_map(static function (array $pair): int {
            return $pair[0];
        }, $this->pairs);
    }

    public function getObjectByIndex(int $index): ?PdfObject
    {
        if (!isset($this->pairs[$index])) {
            return null;
        }
        return $this->parseObjectAt($this->first + $this->pairs[$index][1]);
    }

    /**
     * Resolve by the actual object number recorded in the header, which is
     * authoritative and independent of a possibly-stale cross-reference index.
     */
    public function getObjectByNumber(int $objectNumber): ?PdfObject
    {
        if (!isset($this->indexByNumber[$objectNumber])) {
            return null;
        }
        return $this->getObjectByIndex($this->indexByNumber[$objectNumber]);
    }

    private function parseObjectAt(int $start): PdfObject
    {
        // Bound the parse to this object's extent (up to the next object's start)
        // so look-ahead (e.g. "N G R" reference detection) can never bleed into
        // the following object in the stream.
        $end   = $this->endOffset($start);
        $slice = substr($this->data, $start, max(0, $end - $start));
        return (new ObjectParser(new Tokenizer(new ByteReader($slice))))->parseObject();
    }

    private function endOffset(int $start): int
    {
        foreach ($this->sortedStarts as $offset) {
            if ($offset > $start) {
                return $offset;
            }
        }
        return strlen($this->data);
    }

    private static function intValue(?PdfObject $object): ?int
    {
        return ($object instanceof PdfNumeric && $object->isInteger()) ? (int) $object->getValue() : null;
    }
}
