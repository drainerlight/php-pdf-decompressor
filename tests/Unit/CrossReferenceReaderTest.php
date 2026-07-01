<?php

declare(strict_types=1);

namespace PdfDecompressor\Tests\Unit;

use PdfDecompressor\CrossReference\CrossReferenceReader;
use PdfDecompressor\Type\PdfReference;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PdfDecompressor\CrossReference\CrossReferenceReader
 * @covers \PdfDecompressor\CrossReference\CrossReferenceEntry
 * @covers \PdfDecompressor\CrossReference\CrossReferenceTable
 */
class CrossReferenceReaderTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures';

    public function testClassicTableFixture(): void
    {
        $table = (new CrossReferenceReader())->read(file_get_contents(self::FIXTURES . '/base.pdf'));

        $this->assertInstanceOf(PdfReference::class, $table->getTrailer()->get('Root'));
        $this->assertNotEmpty($table->getObjectNumbers());
        foreach ($table->getEntries() as $entry) {
            $this->assertFalse($entry->isCompressed(), 'classic FPDF output has no compressed objects');
        }
    }

    public function testCrossReferenceStreamFixtureHasBothEntryTypes(): void
    {
        $table = (new CrossReferenceReader())->read(file_get_contents(self::FIXTURES . '/compressed.pdf'));

        $hasCompressed   = false;
        $hasUncompressed = false;
        foreach ($table->getEntries() as $entry) {
            $hasCompressed   = $hasCompressed || $entry->isCompressed();
            $hasUncompressed = $hasUncompressed || $entry->isUncompressed();
        }

        $this->assertTrue($hasCompressed, 'compressed.pdf must contain object-stream (type 2) entries');
        $this->assertTrue($hasUncompressed, 'the xref stream object itself is uncompressed (type 1)');
        $this->assertInstanceOf(PdfReference::class, $table->getTrailer()->get('Root'));
    }

    /**
     * Synthetic incremental update: an original section plus a newer section that
     * redefines object 1 and adds object 3, linked via /Prev. Verifies that the
     * newest entry wins and older-only entries survive the merge.
     */
    public function testPrevChainMergeNewestWins(): void
    {
        $header   = "%PDF-1.4\n";
        $section1 = "xref\n1 2\n"
            . "0000000100 00000 n \n"   // obj1 -> 100 (old)
            . "0000000150 00000 n \n"   // obj2 -> 150
            . "trailer\n<< /Size 4 /Root 1 0 R >>\n";

        $s1     = strlen($header);                 // offset of section1's "xref"
        $prefix = $header . $section1;
        $s2     = strlen($prefix);                 // offset of section2's "xref"

        $section2 = "xref\n1 1\n"
            . "0000000200 00000 n \n"              // obj1 -> 200 (new, must win)
            . "3 1\n"
            . "0000000300 00000 n \n"              // obj3 -> 300
            . "trailer\n<< /Size 4 /Root 1 0 R /Prev {$s1} >>\n";

        $pdf = $prefix . $section2 . "startxref\n{$s2}\n%%EOF";

        $table = (new CrossReferenceReader())->read($pdf);

        $this->assertSame(200, $table->get(1)->getOffset(), 'newest section must win for obj1');
        $this->assertSame(150, $table->get(2)->getOffset(), 'older-only obj2 must survive via /Prev');
        $this->assertSame(300, $table->get(3)->getOffset());
    }

    /**
     * Synthetic cross-reference stream exercising the /W + /Index binary decoding
     * in isolation: W = [1,1,1], no filter, entries for objects 0..2.
     */
    public function testSyntheticXrefStreamBinaryDecoding(): void
    {
        $data = "\x00\x00\x00"   // obj0: type 0 (free)
              . "\x01\x10\x00"   // obj1: type 1, offset 0x10, gen 0
              . "\x02\x05\x03";  // obj2: type 2, stream obj 5, index 3

        $object = "5 0 obj\n"
            . "<< /Type /XRef /Size 3 /Root 1 0 R /W [1 1 1] /Length 9 >>\n"
            . "stream\n" . $data . "\nendstream\nendobj\n";
        $pdf = "%PDF-1.5\n" . $object . "startxref\n9\n%%EOF";

        $table = (new CrossReferenceReader())->read($pdf);

        $this->assertTrue($table->get(0)->isFree());
        $this->assertTrue($table->get(1)->isUncompressed());
        $this->assertSame(0x10, $table->get(1)->getOffset());
        $this->assertTrue($table->get(2)->isCompressed());
        $this->assertSame(5, $table->get(2)->getStreamObjectNumber());
        $this->assertSame(3, $table->get(2)->getIndexInStream());
        $this->assertInstanceOf(PdfReference::class, $table->getTrailer()->get('Root'));
    }
}
