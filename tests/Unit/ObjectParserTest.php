<?php

declare(strict_types=1);

namespace PdfDecompressor\Tests\Unit;

use PdfDecompressor\Lexer\Tokenizer;
use PdfDecompressor\Parser\ObjectParser;
use PdfDecompressor\Reader\ByteReader;
use PdfDecompressor\Type\PdfArray;
use PdfDecompressor\Type\PdfBoolean;
use PdfDecompressor\Type\PdfDictionary;
use PdfDecompressor\Type\PdfName;
use PdfDecompressor\Type\PdfNull;
use PdfDecompressor\Type\PdfNumeric;
use PdfDecompressor\Type\PdfReference;
use PdfDecompressor\Type\PdfStream;
use PdfDecompressor\Type\PdfString;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PdfDecompressor\Parser\ObjectParser
 */
class ObjectParserTest extends TestCase
{
    private function parser(string $input): ObjectParser
    {
        return new ObjectParser(new Tokenizer(new ByteReader($input)));
    }

    public function testScalars(): void
    {
        $this->assertInstanceOf(PdfNumeric::class, $this->parser('42')->parseObject());
        $this->assertInstanceOf(PdfName::class, $this->parser('/Foo')->parseObject());
        $this->assertInstanceOf(PdfString::class, $this->parser('(hi)')->parseObject());
        $this->assertTrue($this->parser('true')->parseObject() instanceof PdfBoolean);
        $this->assertInstanceOf(PdfNull::class, $this->parser('null')->parseObject());
    }

    public function testBooleanValue(): void
    {
        /** @var PdfBoolean $false */
        $false = $this->parser('false')->parseObject();
        $this->assertInstanceOf(PdfBoolean::class, $false);
        $this->assertFalse($false->getValue());
    }

    public function testReferenceIsRecognised(): void
    {
        /** @var PdfReference $ref */
        $ref = $this->parser('12 0 R')->parseObject();
        $this->assertInstanceOf(PdfReference::class, $ref);
        $this->assertSame(12, $ref->getObjectNumber());
        $this->assertSame(0, $ref->getGenerationNumber());
    }

    public function testConsecutiveNumbersAreNotMistakenForReference(): void
    {
        /** @var PdfArray $array */
        $array = $this->parser('[1 2 3]')->parseObject();
        $this->assertInstanceOf(PdfArray::class, $array);
        $this->assertSame(3, $array->count());
        foreach ($array->getItems() as $item) {
            $this->assertInstanceOf(PdfNumeric::class, $item);
        }
        $this->assertSame(2, $array->get(1)->getValue());
    }

    public function testArrayWithMixedTypesAndReference(): void
    {
        /** @var PdfArray $array */
        $array = $this->parser('[1 (two) /three 4 0 R [5]]')->parseObject();
        $items = $array->getItems();
        $this->assertCount(5, $items);
        $this->assertInstanceOf(PdfNumeric::class, $items[0]);
        $this->assertInstanceOf(PdfString::class, $items[1]);
        $this->assertInstanceOf(PdfName::class, $items[2]);
        $this->assertInstanceOf(PdfReference::class, $items[3]);
        $this->assertInstanceOf(PdfArray::class, $items[4]);
    }

    public function testNestedDictionary(): void
    {
        /** @var PdfDictionary $dict */
        $dict = $this->parser('<< /Type /Page /Count 3 /Sub << /A true >> >>')->parseObject();
        $this->assertInstanceOf(PdfDictionary::class, $dict);
        $this->assertInstanceOf(PdfName::class, $dict->get('Type'));
        $this->assertSame('Page', $dict->get('Type')->getValue());
        $this->assertSame(3, $dict->get('Count')->getValue());
        $this->assertInstanceOf(PdfDictionary::class, $dict->get('Sub'));
        $this->assertTrue($dict->get('Sub')->get('A')->getValue());
    }

    public function testStreamWithDirectLength(): void
    {
        $body  = 'Hello Stream Body';
        $input = "<< /Length " . strlen($body) . " >>\nstream\n" . $body . "\nendstream";

        /** @var PdfStream $stream */
        $stream = $this->parser($input)->parseObject();
        $this->assertInstanceOf(PdfStream::class, $stream);
        $this->assertSame($body, $stream->getData());
        $this->assertSame(strlen($body), $stream->getDictionary()->get('Length')->getValue());
    }

    public function testStreamWithIndirectLengthFallsBackToScan(): void
    {
        // /Length is an indirect reference (unresolvable here) -> scan to endstream.
        $body  = 'binary-ish payload';
        $input = "<< /Length 9 0 R >>\nstream\n" . $body . "\nendstream";

        /** @var PdfStream $stream */
        $stream = $this->parser($input)->parseObject();
        $this->assertInstanceOf(PdfStream::class, $stream);
        $this->assertSame($body, $stream->getData());
    }

    public function testDictionaryNotFollowedByStreamLeavesCursorIntact(): void
    {
        // Verifies the stream-detection look-ahead rewinds correctly when the
        // dictionary is NOT a stream: the next object must still be readable.
        $parser = $this->parser('<< /A 1 >> /Next');
        $this->assertInstanceOf(PdfDictionary::class, $parser->parseObject());

        $next = $parser->parseObject();
        $this->assertInstanceOf(PdfName::class, $next);
        $this->assertSame('Next', $next->getValue());
    }

    public function testParseIndirectObject(): void
    {
        $indirect = $this->parser("5 0 obj\n<< /A 1 >>\nendobj")->parseIndirectObject();
        $this->assertSame(5, $indirect->getObjectNumber());
        $this->assertSame(0, $indirect->getGenerationNumber());
        $this->assertInstanceOf(PdfDictionary::class, $indirect->getValue());
    }

    public function testParseIndirectObjectWithStream(): void
    {
        $body     = 'abc';
        $input    = "7 0 obj\n<< /Length 3 >>\nstream\n" . $body . "\nendstream\nendobj";
        $indirect = $this->parser($input)->parseIndirectObject();
        $this->assertSame(7, $indirect->getObjectNumber());
        $this->assertInstanceOf(PdfStream::class, $indirect->getValue());
        $this->assertSame($body, $indirect->getValue()->getData());
    }

    public function testIndirectObjectWithScalarValue(): void
    {
        // Value is a bare number immediately followed by endobj -> must not be
        // read as a reference.
        $indirect = $this->parser("9 0 obj 123 endobj")->parseIndirectObject();
        $this->assertInstanceOf(PdfNumeric::class, $indirect->getValue());
        $this->assertSame(123, $indirect->getValue()->getValue());
    }
}
