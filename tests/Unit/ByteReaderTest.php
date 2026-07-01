<?php

declare(strict_types=1);

namespace PdfDecompressor\Tests\Unit;

use PdfDecompressor\Exception\ReaderException;
use PdfDecompressor\Reader\ByteReader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PdfDecompressor\Reader\ByteReader
 */
class ByteReaderTest extends TestCase
{
    public function testReadAdvancesAndReturnsNullAtEof(): void
    {
        $reader = new ByteReader('AB');
        $this->assertSame('A', $reader->read());
        $this->assertSame('B', $reader->read());
        $this->assertNull($reader->read());
        $this->assertTrue($reader->isEof());
    }

    public function testPeekDoesNotAdvance(): void
    {
        $reader = new ByteReader('ABC');
        $this->assertSame('A', $reader->peek());
        $this->assertSame('B', $reader->peek(1));
        $this->assertSame(0, $reader->getPosition());
        $this->assertNull($reader->peek(99));
    }

    public function testReadBytesReturnsChunkAndClampsAtEof(): void
    {
        $reader = new ByteReader('Hello');
        $this->assertSame('Hel', $reader->readBytes(3));
        $this->assertSame('lo', $reader->readBytes(10));
        $this->assertSame('', $reader->readBytes(1));
    }

    public function testSkipIsClampedToBounds(): void
    {
        $reader = new ByteReader('abc');
        $reader->skip(100);
        $this->assertSame(3, $reader->getPosition());
        $reader->skip(-100);
        $this->assertSame(0, $reader->getPosition());
    }

    public function testIndexOf(): void
    {
        $reader = new ByteReader('aXbXc');
        $this->assertSame(1, $reader->indexOf('X'));
        $this->assertSame(3, $reader->indexOf('X', 2));
        $this->assertNull($reader->indexOf('Z'));
    }

    public function testSetPositionOutOfRangeThrows(): void
    {
        $this->expectException(ReaderException::class);
        (new ByteReader('abc'))->setPosition(4);
    }
}
