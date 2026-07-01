<?php

declare(strict_types=1);

namespace PdfDecompressor\Tests\Integration;

use PdfDecompressor\Exception\NotImplementedException;
use PdfDecompressor\Normalizer;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end contract for the normalizer. These tests define the target
 * behaviour for the MVP (phase 4). While normalize() is unimplemented they are
 * marked incomplete rather than failing, so the suite communicates intent
 * without going red.
 *
 * @covers \PdfDecompressor\Normalizer
 */
class NormalizeFixtureTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures';

    /**
     * The core promise: a compressed PDF becomes a classic-structure PDF that
     * contains no object streams and no cross-reference streams.
     */
    public function testNormalizedOutputHasNoObjectOrXrefStreams(): void
    {
        $out = $this->normalizeCompressedFixtureOrSkip();

        $this->assertFalse(
            (bool) preg_match('~/Type\s*/ObjStm\b~', $out),
            'Normalized output must not contain object streams.'
        );
        $this->assertFalse(
            (bool) preg_match('~/Type\s*/XRef\b~', $out),
            'Normalized output must not contain cross-reference streams.'
        );
    }

    /**
     * The output must start with a classic PDF header and end with %%EOF.
     */
    public function testNormalizedOutputIsWellFormedClassicPdf(): void
    {
        $out = $this->normalizeCompressedFixtureOrSkip();

        $this->assertSame('%PDF-1.', substr($out, 0, 7));
        $this->assertNotFalse(strpos($out, 'xref'), 'Expected a classic xref table.');
        $this->assertNotFalse(strpos($out, '%%EOF'), 'Expected a trailing %%EOF marker.');
    }

    /**
     * Regression guard against the original bug: the free FPDI parser must be
     * able to open the normalized file. Only runs when FPDI is available on the
     * include path (dev environments); otherwise skipped.
     */
    public function testNormalizedOutputIsReadableByFreeFpdiParser(): void
    {
        if (!class_exists(\setasign\Fpdi\Fpdi::class)) {
            $this->markTestSkipped('setasign/fpdi not installed in this environment.');
        }

        $out = $this->normalizeCompressedFixtureOrSkip();
        $tmp = tempnam(sys_get_temp_dir(), 'pdfdecomp_') . '.pdf';
        file_put_contents($tmp, $out);

        try {
            $fpdi  = new \setasign\Fpdi\Fpdi();
            $pages = $fpdi->setSourceFile($tmp);
            $this->assertGreaterThan(0, $pages);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Runs normalize() on the compressed fixture, or marks the test incomplete
     * while the feature is still pending.
     */
    private function normalizeCompressedFixtureOrSkip(): string
    {
        $bytes = file_get_contents(self::FIXTURES . '/compressed.pdf');
        try {
            return (new Normalizer())->normalize($bytes);
        } catch (NotImplementedException $e) {
            $this->markTestIncomplete('Normalizer::normalize() not implemented yet (phase 4).');
        }
    }
}
