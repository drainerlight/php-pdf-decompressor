<?php

declare(strict_types=1);

namespace PdfDecompressor\Tests\Unit;

use PdfDecompressor\Exception\ParserException;
use PdfDecompressor\Lexer\Token;
use PdfDecompressor\Lexer\Tokenizer;
use PdfDecompressor\Reader\ByteReader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PdfDecompressor\Lexer\Tokenizer
 * @covers \PdfDecompressor\Lexer\Token
 */
class TokenizerTest extends TestCase
{
    private function tokenize(string $input): Tokenizer
    {
        return new Tokenizer(new ByteReader($input));
    }

    public function testIntegerAndRealNumbers(): void
    {
        $t = $this->tokenize('42 -17 3.14 .5 +8');

        $this->assertSame(42, $t->nextToken()->getValue());
        $this->assertSame(-17, $t->nextToken()->getValue());
        $this->assertSame(3.14, $t->nextToken()->getValue());
        $this->assertSame(0.5, $t->nextToken()->getValue());
        $this->assertSame(8, $t->nextToken()->getValue());
        $this->assertSame(Token::EOF, $t->nextToken()->getType());
    }

    public function testNumberTypesAreDistinguished(): void
    {
        $t = $this->tokenize('7 7.0');
        $this->assertTrue(is_int($t->nextToken()->getValue()));
        $this->assertTrue(is_float($t->nextToken()->getValue()));
    }

    public function testNameWithHexEscape(): void
    {
        $t     = $this->tokenize('/Type /A#20B');
        $first = $t->nextToken();
        $this->assertSame(Token::NAME, $first->getType());
        $this->assertSame('Type', $first->getValue());
        $this->assertSame('A B', $t->nextToken()->getValue());
    }

    public function testLiteralStringWithEscapesAndNesting(): void
    {
        $t     = $this->tokenize('(a\\(b\\) c\\n(inner)end)');
        $token = $t->nextToken();
        $this->assertSame(Token::STRING, $token->getType());
        $this->assertSame("a(b) c\n(inner)end", $token->getValue());
    }

    public function testLiteralStringOctalEscape(): void
    {
        // \101 == 'A', \0 == NUL
        $token = $this->tokenize('(\\101\\0B)')->nextToken();
        $this->assertSame("A\x00B", $token->getValue());
    }

    public function testHexStringWithOddPaddingAndWhitespace(): void
    {
        // "901F A" -> bytes 0x90 0x1F 0xA0 (last digit padded with 0)
        $token = $this->tokenize('<901F A>')->nextToken();
        $this->assertSame("\x90\x1F\xA0", $token->getValue());
    }

    public function testDictionaryAndArrayDelimiters(): void
    {
        $t = $this->tokenize('<< /K [1] >>');
        $this->assertSame(Token::DICT_OPEN, $t->nextToken()->getType());
        $this->assertSame(Token::NAME, $t->nextToken()->getType());
        $this->assertSame(Token::ARRAY_OPEN, $t->nextToken()->getType());
        $this->assertSame(Token::NUMBER, $t->nextToken()->getType());
        $this->assertSame(Token::ARRAY_CLOSE, $t->nextToken()->getType());
        $this->assertSame(Token::DICT_CLOSE, $t->nextToken()->getType());
    }

    public function testKeywords(): void
    {
        $t = $this->tokenize('obj endobj R true false null stream');
        foreach (['obj', 'endobj', 'R', 'true', 'false', 'null', 'stream'] as $kw) {
            $token = $t->nextToken();
            $this->assertSame(Token::KEYWORD, $token->getType());
            $this->assertTrue($token->isKeyword($kw), "expected keyword {$kw}");
        }
    }

    public function testCommentsAreSkipped(): void
    {
        $t = $this->tokenize("% a comment line\n123 % trailing\n456");
        $this->assertSame(123, $t->nextToken()->getValue());
        $this->assertSame(456, $t->nextToken()->getValue());
    }

    public function testTokenOffsetIsReported(): void
    {
        $t = $this->tokenize('   /Name');
        $this->assertSame(3, $t->nextToken()->getOffset());
    }

    public function testEmptyNameAndEmptyHexString(): void
    {
        $t    = $this->tokenize('/ <>');
        $name = $t->nextToken();
        $this->assertSame(Token::NAME, $name->getType());
        $this->assertSame('', $name->getValue());
        $hex = $t->nextToken();
        $this->assertSame(Token::STRING, $hex->getType());
        $this->assertSame('', $hex->getValue());
    }

    public function testLiteralStringPreservesRawNewlineBytes(): void
    {
        // Intentional deviation from ISO 32000-1 7.3.4.2 EOL normalization: a
        // rewriter keeps the original bytes so re-emitted content round-trips
        // exactly. Documented here so the behaviour is deliberate.
        $token = $this->tokenize("(line1\nline2)")->nextToken();
        $this->assertSame("line1\nline2", $token->getValue());
    }

    public function testUnterminatedStringThrows(): void
    {
        $this->expectException(ParserException::class);
        $this->tokenize('(no closing paren')->nextToken();
    }
}
