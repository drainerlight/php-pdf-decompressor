<?php

declare(strict_types=1);

namespace PdfDecompressor\Tests\Unit;

use PdfDecompressor\Type\PdfDictionary;
use PdfDecompressor\Type\PdfNumeric;
use PdfDecompressor\Type\PdfReference;
use PdfDecompressor\Writer\PdfWriter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PdfDecompressor\Writer\PdfWriter
 */
class PdfWriterTest extends TestCase
{
    public function testWritesClassicHeaderTableAndTrailer(): void
    {
        $out = (new PdfWriter())->write(
            [1 => new PdfNumeric(42)],
            new PdfDictionary(['Root' => new PdfReference(1, 0)])
        );

        $this->assertStringStartsWith('%PDF-1.4', $out);
        $this->assertNotFalse(strpos($out, "1 0 obj\n42\nendobj"));
        $this->assertNotFalse(strpos($out, "\nxref\n0 2\n"));
        $this->assertNotFalse(strpos($out, '0000000000 65535 f'));
        $this->assertNotFalse(strpos($out, '/Size 2'));
        $this->assertNotFalse(strpos($out, '%%EOF'));
    }

    public function testPreservesGenerationNumbers(): void
    {
        $out = (new PdfWriter())->write(
            [5 => new PdfNumeric(1)],
            new PdfDictionary(['Root' => new PdfReference(5, 3)]),
            [5 => 3]
        );

        $this->assertNotFalse(strpos($out, "5 3 obj"), 'object header must carry generation 3');
        $this->assertMatchesRegularExpression('~\d{10} 00003 n~', $out, 'xref entry must record generation 3');
    }

    public function testDroppedNumbersBecomeFreeEntries(): void
    {
        // Only object 3 present -> objects 1 and 2 must appear as free entries.
        $out = (new PdfWriter())->write(
            [3 => new PdfNumeric(0)],
            new PdfDictionary(['Root' => new PdfReference(3, 0)])
        );

        $this->assertNotFalse(strpos($out, "\nxref\n0 4\n"));
        $this->assertSame(2, substr_count($out, '0000000000 00000 f'), 'objects 1 and 2 are free');
    }
}
