<?php

declare(strict_types=1);

namespace PdfDecompressor\Parser;

use PdfDecompressor\Exception\ParserException;
use PdfDecompressor\Lexer\Token;
use PdfDecompressor\Lexer\Tokenizer;
use PdfDecompressor\Reader\ByteReader;
use PdfDecompressor\Type\PdfArray;
use PdfDecompressor\Type\PdfBoolean;
use PdfDecompressor\Type\PdfDictionary;
use PdfDecompressor\Type\PdfIndirectObject;
use PdfDecompressor\Type\PdfName;
use PdfDecompressor\Type\PdfNull;
use PdfDecompressor\Type\PdfNumeric;
use PdfDecompressor\Type\PdfObject;
use PdfDecompressor\Type\PdfReference;
use PdfDecompressor\Type\PdfStream;
use PdfDecompressor\Type\PdfString;

/**
 * Assembles tokens into typed PDF objects (ISO 32000-1, 7.3).
 *
 * Scope (phase 1): parse a single object value and parse a complete indirect
 * object "N G obj ... endobj", including stream bodies whose /Length is a direct
 * integer (with a scan fallback for indirect lengths). Resolving indirect
 * references and object streams is layered on top in later phases.
 */
final class ObjectParser
{
    /** @var Tokenizer */
    private $tokenizer;

    /** @var ObjectResolver|null */
    private $resolver;

    public function __construct(Tokenizer $tokenizer, ?ObjectResolver $resolver = null)
    {
        $this->tokenizer = $tokenizer;
        $this->resolver  = $resolver;
    }

    public function getTokenizer(): Tokenizer
    {
        return $this->tokenizer;
    }

    /**
     * Parse one object value at the current position.
     */
    public function parseObject(): PdfObject
    {
        return $this->parseFromToken($this->tokenizer->nextToken());
    }

    /**
     * Parse a full indirect object definition: "N G obj <value> endobj".
     */
    public function parseIndirectObject(): PdfIndirectObject
    {
        $numberToken     = $this->tokenizer->nextToken();
        $generationToken = $this->tokenizer->nextToken();
        $objToken        = $this->tokenizer->nextToken();

        if (
            !$numberToken->is(Token::NUMBER)
            || !$generationToken->is(Token::NUMBER)
            || !$objToken->isKeyword('obj')
        ) {
            throw new ParserException(
                'Expected "N G obj" header at offset ' . $numberToken->getOffset() . '.'
            );
        }

        $value    = $this->parseObject();
        $endToken = $this->tokenizer->nextToken();
        if (!$endToken->isKeyword('endobj')) {
            throw new ParserException(
                "Expected 'endobj' at offset " . $endToken->getOffset() . '.'
            );
        }

        return new PdfIndirectObject(
            (int) $numberToken->getValue(),
            (int) $generationToken->getValue(),
            $value
        );
    }

    private function parseFromToken(Token $token): PdfObject
    {
        switch ($token->getType()) {
            case Token::NUMBER:
                return $this->parseNumberOrReference($token);
            case Token::NAME:
                return new PdfName((string) $token->getValue());
            case Token::STRING:
                return new PdfString((string) $token->getValue());
            case Token::ARRAY_OPEN:
                return $this->parseArray();
            case Token::DICT_OPEN:
                return $this->parseDictionaryOrStream();
            case Token::KEYWORD:
                return $this->parseKeyword($token);
            case Token::EOF:
                throw new ParserException('Unexpected end of input while parsing an object.');
            default:
                throw new ParserException(
                    "Unexpected token '{$token->getType()}' at offset " . $token->getOffset() . '.'
                );
        }
    }

    private function parseKeyword(Token $token): PdfObject
    {
        switch ($token->getValue()) {
            case 'true':
                return new PdfBoolean(true);
            case 'false':
                return new PdfBoolean(false);
            case 'null':
                return new PdfNull();
            default:
                throw new ParserException(
                    "Unexpected keyword '{$token->getValue()}' at offset " . $token->getOffset() . '.'
                );
        }
    }

    /**
     * A number token may be a plain number or the first component of an indirect
     * reference "N G R". Disambiguate with bounded look-ahead and rewind if it
     * turns out to be just a number.
     */
    private function parseNumberOrReference(Token $first): PdfObject
    {
        if (!is_int($first->getValue())) {
            return new PdfNumeric($first->getValue());
        }

        $reader   = $this->tokenizer->getReader();
        $rewindTo = $reader->getPosition();

        $second = $this->tokenizer->nextToken();
        if ($second->is(Token::NUMBER) && is_int($second->getValue())) {
            $third = $this->tokenizer->nextToken();
            if ($third->isKeyword('R')) {
                return new PdfReference((int) $first->getValue(), (int) $second->getValue());
            }
        }

        $reader->setPosition($rewindTo);
        return new PdfNumeric($first->getValue());
    }

    private function parseArray(): PdfArray
    {
        $items = [];
        while (true) {
            $token = $this->tokenizer->nextToken();
            if ($token->is(Token::ARRAY_CLOSE)) {
                break;
            }
            if ($token->is(Token::EOF)) {
                throw new ParserException('Unterminated array.');
            }
            $items[] = $this->parseFromToken($token);
        }
        return new PdfArray($items);
    }

    private function parseDictionaryOrStream(): PdfObject
    {
        $entries = [];
        while (true) {
            $keyToken = $this->tokenizer->nextToken();
            if ($keyToken->is(Token::DICT_CLOSE)) {
                break;
            }
            if ($keyToken->is(Token::EOF)) {
                throw new ParserException('Unterminated dictionary.');
            }
            if (!$keyToken->is(Token::NAME)) {
                throw new ParserException(
                    'Expected a name key in dictionary at offset ' . $keyToken->getOffset() . '.'
                );
            }

            $valueToken = $this->tokenizer->nextToken();
            if ($valueToken->is(Token::DICT_CLOSE) || $valueToken->is(Token::EOF)) {
                throw new ParserException("Missing value for key /{$keyToken->getValue()}.");
            }
            $entries[(string) $keyToken->getValue()] = $this->parseFromToken($valueToken);
        }

        $dictionary = new PdfDictionary($entries);

        // A stream is a dictionary immediately followed by the 'stream' keyword.
        $reader   = $this->tokenizer->getReader();
        $rewindTo = $reader->getPosition();
        $next     = $this->tokenizer->nextToken();
        if ($next->isKeyword('stream')) {
            return $this->parseStream($dictionary);
        }

        $reader->setPosition($rewindTo);
        return $dictionary;
    }

    /**
     * Read stream data after the 'stream' keyword has been consumed (7.3.8).
     */
    private function parseStream(PdfDictionary $dictionary): PdfStream
    {
        $reader = $this->tokenizer->getReader();

        // 'stream' is followed by CRLF or a single LF (7.3.8.1). Tolerate a lone CR.
        $c = $reader->peek();
        if ($c === "\r") {
            $reader->skip(1);
            if ($reader->peek() === "\n") {
                $reader->skip(1);
            }
        } elseif ($c === "\n") {
            $reader->skip(1);
        }
        $dataStart = $reader->getPosition();

        $length = $this->resolveLength($dictionary);
        if ($length !== null) {
            $data     = $reader->readBytes($length);
            $endToken = $this->tokenizer->nextToken();
            if ($endToken->isKeyword('endstream')) {
                return new PdfStream($dictionary, $data);
            }
            // /Length was wrong or indirect: fall back to scanning.
            $reader->setPosition($dataStart);
        }

        return new PdfStream($dictionary, $this->readStreamByScan($reader, $dataStart));
    }

    /**
     * Resolve /Length to a non-negative integer: directly when present, or via
     * the injected resolver when it is an indirect reference. Returns null when
     * unresolvable, so the caller falls back to scanning for 'endstream'.
     */
    private function resolveLength(PdfDictionary $dictionary): ?int
    {
        $length = $dictionary->get('Length');

        if ($length instanceof PdfReference && $this->resolver !== null) {
            $length = $this->resolver->resolve($length);
        }

        if ($length instanceof PdfNumeric && $length->isInteger()) {
            $value = (int) $length->getValue();
            return $value >= 0 ? $value : null;
        }

        return null;
    }

    /**
     * Locate 'endstream' from $dataStart, treating everything before it (minus a
     * single trailing EOL) as the stream data. Leaves the cursor past 'endstream'.
     */
    private function readStreamByScan(ByteReader $reader, int $dataStart): string
    {
        $endPos = $reader->indexOf('endstream', $dataStart);
        if ($endPos === null) {
            throw new ParserException('Missing endstream keyword.');
        }

        $reader->setPosition($dataStart);
        $data = $reader->readBytes($endPos - $dataStart);

        // The EOL immediately before 'endstream' is a delimiter, not data (7.3.8.1).
        if (substr($data, -2) === "\r\n") {
            $data = substr($data, 0, -2);
        } elseif (substr($data, -1) === "\n" || substr($data, -1) === "\r") {
            $data = substr($data, 0, -1);
        }

        $endToken = $this->tokenizer->nextToken();
        if (!$endToken->isKeyword('endstream')) {
            throw new ParserException(
                "Expected 'endstream' at offset " . $endToken->getOffset() . '.'
            );
        }

        return $data;
    }
}
