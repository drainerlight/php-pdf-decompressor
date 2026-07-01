<?php

declare(strict_types=1);

namespace PdfDecompressor\Lexer;

use PdfDecompressor\Exception\ParserException;
use PdfDecompressor\Reader\ByteReader;

/**
 * Turns raw PDF bytes into a stream of {@see Token}s (ISO 32000-1, 7.2).
 *
 * The tokenizer is deliberately dumb about grammar: it only classifies lexical
 * units (numbers, names, strings, delimiters, keywords). Assembling them into
 * objects is the {@see \PdfDecompressor\Parser\ObjectParser}'s job. Stream data
 * is NOT tokenized — the parser reads it directly from the shared ByteReader.
 */
final class Tokenizer
{
    /** PDF white-space bytes (7.2.3, Table 1): NUL, TAB, LF, FF, CR, SP. */
    private const WHITESPACE = "\x00\x09\x0A\x0C\x0D\x20";

    /** PDF delimiter bytes (7.2.3, Table 2). */
    private const DELIMITERS = "()<>[]{}/%";

    /** @var ByteReader */
    private $reader;

    public function __construct(ByteReader $reader)
    {
        $this->reader = $reader;
    }

    public function getReader(): ByteReader
    {
        return $this->reader;
    }

    public function nextToken(): Token
    {
        $this->skipWhitespaceAndComments();
        $offset = $this->reader->getPosition();
        $c      = $this->reader->peek();

        if ($c === null) {
            return new Token(Token::EOF, null, $offset);
        }

        if ($c === '/') {
            return $this->readName($offset);
        }
        if ($c === '(') {
            return $this->readLiteralString($offset);
        }
        if ($c === '<') {
            if ($this->reader->peek(1) === '<') {
                $this->reader->skip(2);
                return new Token(Token::DICT_OPEN, '<<', $offset);
            }
            return $this->readHexString($offset);
        }
        if ($c === '>') {
            if ($this->reader->peek(1) === '>') {
                $this->reader->skip(2);
                return new Token(Token::DICT_CLOSE, '>>', $offset);
            }
            throw new ParserException("Unexpected '>' at offset {$offset}.");
        }
        if ($c === '[') {
            $this->reader->skip(1);
            return new Token(Token::ARRAY_OPEN, '[', $offset);
        }
        if ($c === ']') {
            $this->reader->skip(1);
            return new Token(Token::ARRAY_CLOSE, ']', $offset);
        }
        // { and } only appear inside PostScript type-4 functions; keep them as
        // opaque keyword tokens so structural parsing never trips over them.
        if ($c === '{' || $c === '}') {
            $this->reader->skip(1);
            return new Token(Token::KEYWORD, $c, $offset);
        }
        if ($this->isNumericStart($c)) {
            return $this->readNumber($offset);
        }

        return $this->readKeyword($offset);
    }

    private function skipWhitespaceAndComments(): void
    {
        while (true) {
            $c = $this->reader->peek();
            if ($c === null) {
                return;
            }
            if (self::isWhitespace($c)) {
                $this->reader->skip(1);
                continue;
            }
            if ($c === '%') { // comment runs to end of line (7.2.4)
                $this->reader->skip(1);
                $this->skipToEol();
                continue;
            }
            return;
        }
    }

    private function skipToEol(): void
    {
        while (true) {
            $c = $this->reader->read();
            if ($c === null || $c === "\n" || $c === "\r") {
                return;
            }
        }
    }

    private function readName(int $offset): Token
    {
        $this->reader->skip(1); // consume '/'
        $name = '';
        while (true) {
            $c = $this->reader->peek();
            if ($c === null || self::isWhitespace($c) || self::isDelimiter($c)) {
                break;
            }
            if ($c === '#') {
                $h1 = $this->reader->peek(1);
                $h2 = $this->reader->peek(2);
                if ($h1 !== null && $h2 !== null && ctype_xdigit($h1) && ctype_xdigit($h2)) {
                    $name .= chr((int) hexdec($h1 . $h2));
                    $this->reader->skip(3);
                    continue;
                }
                // malformed escape: treat '#' literally
            }
            $name .= $c;
            $this->reader->skip(1);
        }
        return new Token(Token::NAME, $name, $offset);
    }

    private function readNumber(int $offset): Token
    {
        $text = '';
        $c    = $this->reader->peek();
        if ($c === '+' || $c === '-') {
            $text .= $c;
            $this->reader->skip(1);
            $c = $this->reader->peek();
        }

        $isFloat = false;
        while ($c !== null && (ctype_digit($c) || $c === '.')) {
            if ($c === '.') {
                $isFloat = true;
            }
            $text .= $c;
            $this->reader->skip(1);
            $c = $this->reader->peek();
        }

        if ($text === '' || $text === '+' || $text === '-') {
            throw new ParserException("Malformed number at offset {$offset}.");
        }

        $value = $isFloat ? (float) $text : (int) $text;
        return new Token(Token::NUMBER, $value, $offset);
    }

    private function readLiteralString(int $offset): Token
    {
        $this->reader->skip(1); // consume '('
        $result = '';
        $depth  = 1;

        while (true) {
            $c = $this->reader->read();
            if ($c === null) {
                throw new ParserException("Unterminated literal string at offset {$offset}.");
            }

            if ($c === '\\') {
                $result .= $this->readLiteralEscape();
                continue;
            }
            if ($c === '(') {
                $depth++;
                $result .= '(';
                continue;
            }
            if ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
                $result .= ')';
                continue;
            }
            $result .= $c;
        }

        return new Token(Token::STRING, $result, $offset);
    }

    /**
     * Decode the byte(s) following a backslash inside a literal string (7.3.4.2).
     */
    private function readLiteralEscape(): string
    {
        $c = $this->reader->read();
        if ($c === null) {
            throw new ParserException('Unterminated escape in literal string.');
        }

        switch ($c) {
            case 'n':
                return "\n";
            case 'r':
                return "\r";
            case 't':
                return "\t";
            case 'b':
                return "\x08";
            case 'f':
                return "\x0C";
            case '(':
                return '(';
            case ')':
                return ')';
            case '\\':
                return '\\';
            case "\n":
                return ''; // line continuation
            case "\r":
                if ($this->reader->peek() === "\n") {
                    $this->reader->skip(1);
                }
                return ''; // line continuation
        }

        if ($c >= '0' && $c <= '7') { // 1-3 digit octal escape
            $octal = $c;
            for ($i = 0; $i < 2; $i++) {
                $d = $this->reader->peek();
                if ($d !== null && $d >= '0' && $d <= '7') {
                    $octal .= $d;
                    $this->reader->skip(1);
                } else {
                    break;
                }
            }
            return chr((int) octdec($octal) & 0xFF);
        }

        // Unknown escape: the backslash is ignored, the character kept (7.3.4.2).
        return $c;
    }

    private function readHexString(int $offset): Token
    {
        $this->reader->skip(1); // consume '<'
        $hex = '';
        while (true) {
            $c = $this->reader->read();
            if ($c === null) {
                throw new ParserException("Unterminated hex string at offset {$offset}.");
            }
            if ($c === '>') {
                break;
            }
            if (self::isWhitespace($c)) {
                continue;
            }
            if (!ctype_xdigit($c)) {
                throw new ParserException("Invalid hex digit '{$c}' at offset {$offset}.");
            }
            $hex .= $c;
        }

        if ((strlen($hex) % 2) === 1) {
            $hex .= '0'; // final odd digit is assumed to be followed by 0 (7.3.4.3)
        }

        return new Token(Token::STRING, $hex === '' ? '' : (string) hex2bin($hex), $offset);
    }

    private function readKeyword(int $offset): Token
    {
        $keyword = '';
        while (true) {
            $c = $this->reader->peek();
            if ($c === null || self::isWhitespace($c) || self::isDelimiter($c)) {
                break;
            }
            $keyword .= $c;
            $this->reader->skip(1);
        }

        if ($keyword === '') {
            $byte = $this->reader->peek();
            throw new ParserException(
                'Unexpected byte 0x' . ($byte === null ? 'EOF' : bin2hex($byte)) . " at offset {$offset}."
            );
        }

        return new Token(Token::KEYWORD, $keyword, $offset);
    }

    private function isNumericStart(string $c): bool
    {
        return $c === '+' || $c === '-' || $c === '.' || ctype_digit($c);
    }

    private static function isWhitespace(string $c): bool
    {
        return strpos(self::WHITESPACE, $c) !== false;
    }

    private static function isDelimiter(string $c): bool
    {
        return strpos(self::DELIMITERS, $c) !== false;
    }
}
