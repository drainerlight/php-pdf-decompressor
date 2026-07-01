<?php

declare(strict_types=1);

namespace PdfUnstream\Tests\Unit;

use PdfUnstream\Exception\NotImplementedException;
use PdfUnstream\Exception\PdfUnstreamException;
use PdfUnstream\Normalizer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PdfUnstream\Normalizer
 */
class NormalizerDetectionTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures';

    public function testDetectsObjectStreamPdfAsCompressed(): void
    {
        $bytes = file_get_contents(self::FIXTURES . '/compressed.pdf');
        $this->assertTrue(Normalizer::isCompressed($bytes));
    }

    public function testClassicPdfIsNotDetectedAsCompressed(): void
    {
        $bytes = file_get_contents(self::FIXTURES . '/expected_classic.pdf');
        $this->assertFalse(Normalizer::isCompressed($bytes));
    }

    public function testPlainFpdfOutputIsNotDetectedAsCompressed(): void
    {
        $bytes = file_get_contents(self::FIXTURES . '/base.pdf');
        $this->assertFalse(Normalizer::isCompressed($bytes));
    }

    public function testNormalizeRejectsNonPdfInput(): void
    {
        $this->expectException(PdfUnstreamException::class);
        (new Normalizer())->normalize('just some bytes, not a pdf');
    }

    public function testNormalizeIsNotImplementedYet(): void
    {
        // Documents the current phase-0 state: valid PDF input is accepted past
        // the header check but the conversion itself is still pending (phase 4).
        $bytes = file_get_contents(self::FIXTURES . '/compressed.pdf');
        $this->expectException(NotImplementedException::class);
        (new Normalizer())->normalize($bytes);
    }
}
