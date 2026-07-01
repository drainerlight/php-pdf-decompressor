<?php

declare(strict_types=1);

namespace PdfDecompressor\Tests\Unit;

use PdfDecompressor\Exception\FilterException;
use PdfDecompressor\Filter\FlateDecode;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PdfDecompressor\Filter\FlateDecode
 */
class FlateDecodeTest extends TestCase
{
    public function testInflatesPlainFlateStreamWithoutPredictor(): void
    {
        $raw = 'Hello, PDF object stream contents.';
        $this->assertSame($raw, FlateDecode::decode(gzcompress($raw)));
    }

    public function testInvalidDataThrows(): void
    {
        $this->expectException(FilterException::class);
        FlateDecode::decode('this is not zlib data at all');
    }

    /**
     * PNG "Up" predictor (filter byte 2): each byte = raw - byteAbove.
     * Two rows, 3 columns, 1 colour, 8 bpc.
     *   row0 raw [10,20,30]  encoded (up=0)      -> [10,20,30]
     *   row1 raw [15,25,35]  encoded (up=row0)   -> [ 5, 5, 5]
     */
    public function testReversesPngUpPredictor(): void
    {
        $encoded  = "\x02\x0A\x14\x1E" . "\x02\x05\x05\x05";
        $expected = "\x0A\x14\x1E" . "\x0F\x19\x23";

        $result = FlateDecode::decode(gzcompress($encoded), [
            'Predictor'        => 12,
            'Columns'          => 3,
            'Colors'           => 1,
            'BitsPerComponent' => 8,
        ]);

        $this->assertSame($expected, $result);
    }

    /**
     * PNG "Sub" predictor (filter byte 1): each byte = raw - left (bpp = 1).
     *   raw [10,20,30] encoded -> [10,10,10]
     */
    public function testReversesPngSubPredictor(): void
    {
        $encoded  = "\x01\x0A\x0A\x0A";
        $expected = "\x0A\x14\x1E";

        $result = FlateDecode::decode(gzcompress($encoded), [
            'Predictor' => 11,
            'Columns'   => 3,
        ]);

        $this->assertSame($expected, $result);
    }

    /**
     * PNG "None" filter (byte 0) must pass the row through unchanged.
     */
    public function testPngNoneFilterIsIdentity(): void
    {
        $encoded  = "\x00\x01\x02\x03\x04";
        $expected = "\x01\x02\x03\x04";

        $result = FlateDecode::decode(gzcompress($encoded), [
            'Predictor' => 15,
            'Columns'   => 4,
        ]);

        $this->assertSame($expected, $result);
    }

    /**
     * TIFF predictor 2 (horizontal differencing), 8 bpc, 1 colour.
     *   encoded [10,10,10] -> raw [10,20,30]
     */
    public function testReversesTiffPredictor(): void
    {
        $encoded  = "\x0A\x0A\x0A";
        $expected = "\x0A\x14\x1E";

        $result = FlateDecode::decode(gzcompress($encoded), [
            'Predictor'        => 2,
            'Columns'          => 3,
            'Colors'           => 1,
            'BitsPerComponent' => 8,
        ]);

        $this->assertSame($expected, $result);
    }

    public function testUnsupportedPngFilterTypeThrows(): void
    {
        // filter-type byte 9 is not a valid PNG filter
        $encoded = "\x09\x01\x02\x03";

        $this->expectException(FilterException::class);
        FlateDecode::decode(gzcompress($encoded), [
            'Predictor' => 12,
            'Columns'   => 3,
        ]);
    }
}
