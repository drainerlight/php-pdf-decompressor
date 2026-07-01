<?php

declare(strict_types=1);

namespace PdfDecompressor\Tests\Unit;

use PdfDecompressor\ObjectStream\ObjectStream;
use PdfDecompressor\Type\PdfArray;
use PdfDecompressor\Type\PdfDictionary;
use PdfDecompressor\Type\PdfName;
use PdfDecompressor\Type\PdfNumeric;
use PdfDecompressor\Type\PdfStream;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PdfDecompressor\ObjectStream\ObjectStream
 */
class ObjectStreamTest extends TestCase
{
    /**
     * Build an uncompressed ObjStm by hand so the header/offset logic is tested
     * in isolation (no filter involved).
     *
     * Objects: 10 = "<< /A 1 >>" (offset 0), 11 = "[1 2 3]" (offset 11).
     */
    private function buildObjectStream(): ObjectStream
    {
        $bodies = '<< /A 1 >>' . ' ' . '[1 2 3]'; // obj10 at 0, obj11 at 11
        $header = "10 0 11 11\n";                   // pairs: (10,0) (11,11)
        $data   = $header . $bodies;

        $dictionary = new PdfDictionary([
            'Type'  => new PdfName('ObjStm'),
            'N'     => new PdfNumeric(2),
            'First' => new PdfNumeric(strlen($header)),
        ]);

        return ObjectStream::fromStream(new PdfStream($dictionary, $data));
    }

    public function testCountAndObjectNumbers(): void
    {
        $objectStream = $this->buildObjectStream();
        $this->assertSame(2, $objectStream->count());
        $this->assertSame([10, 11], $objectStream->getObjectNumbers());
    }

    public function testGetByNumberResolvesCorrectObject(): void
    {
        $objectStream = $this->buildObjectStream();

        $obj10 = $objectStream->getObjectByNumber(10);
        $this->assertInstanceOf(PdfDictionary::class, $obj10);
        $this->assertSame(1, $obj10->get('A')->getValue());

        $obj11 = $objectStream->getObjectByNumber(11);
        $this->assertInstanceOf(PdfArray::class, $obj11);
        $this->assertSame(3, $obj11->count());
    }

    public function testGetByIndexMatchesGetByNumber(): void
    {
        $objectStream = $this->buildObjectStream();
        $this->assertInstanceOf(PdfDictionary::class, $objectStream->getObjectByIndex(0));
        $this->assertInstanceOf(PdfArray::class, $objectStream->getObjectByIndex(1));
    }

    public function testUnknownObjectNumberReturnsNull(): void
    {
        $this->assertNull($this->buildObjectStream()->getObjectByNumber(999));
    }
}
