<?php

declare(strict_types=1);

namespace PdfDecompressor\Tests\Integration;

use PdfDecompressor\Document\Document;
use PdfDecompressor\Normalizer;
use PdfDecompressor\Type\PdfDictionary;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests for the full normalize pipeline against the compressed fixture
 * (a real PDF 1.5 with an object stream + cross-reference stream).
 *
 * @covers \PdfDecompressor\Normalizer
 */
class NormalizeFixtureTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures';

    private function normalizeCompressedFixture(): string
    {
        return (new Normalizer())->normalize(file_get_contents(self::FIXTURES . '/compressed.pdf'));
    }

    public function testNormalizedOutputHasNoObjectOrXrefStreams(): void
    {
        $out = $this->normalizeCompressedFixture();

        $this->assertSame(0, preg_match('~/Type\s*/ObjStm\b~', $out), 'no object streams expected');
        $this->assertSame(0, preg_match('~/Type\s*/XRef\b~', $out), 'no cross-reference streams expected');
        $this->assertFalse(Normalizer::isCompressed($out), 'output must not look compressed anymore');
    }

    public function testNormalizedOutputIsWellFormedClassicPdf(): void
    {
        $out = $this->normalizeCompressedFixture();

        $this->assertSame('%PDF-1.', substr($out, 0, 7));
        $this->assertNotFalse(strpos($out, "\nxref\n"), 'expected a classic xref table');
        $this->assertNotFalse(strpos($out, 'trailer'), 'expected a trailer');
        $this->assertNotFalse(strpos($out, '%%EOF'), 'expected a trailing %%EOF marker');
    }

    /**
     * Self-consistent round trip: re-parse the normalized output with our own
     * Document and confirm the document graph is intact and fully uncompressed.
     */
    public function testNormalizedOutputReparsesAndIsFullyUncompressed(): void
    {
        $document = Document::parse($this->normalizeCompressedFixture());

        $rootReference = $document->getTrailer()->get('Root');
        /** @var PdfDictionary $catalog */
        $catalog = $document->getObject($rootReference->getObjectNumber());
        $this->assertSame('Catalog', $catalog->get('Type')->getValue());

        /** @var PdfDictionary $pages */
        $pages = $document->resolve($catalog->get('Pages'));
        $this->assertSame('Pages', $pages->get('Type')->getValue());
        $this->assertSame(2, $pages->get('Count')->getValue());

        foreach ($document->getCrossReferenceTable()->getEntries() as $entry) {
            $this->assertFalse($entry->isCompressed(), 'normalized output must have no compressed objects');
        }
    }

    /**
     * The regression guard against the original bug: the free FPDI parser must be
     * able to open the normalized file and see the right number of pages.
     */
    public function testNormalizedOutputIsReadableByFreeFpdiParser(): void
    {
        if (!class_exists(\setasign\Fpdi\Fpdi::class)) {
            $this->markTestSkipped('setasign/fpdi not installed in this environment.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pdfdecomp_') . '.pdf';
        file_put_contents($tmp, $this->normalizeCompressedFixture());

        try {
            $fpdi  = new \setasign\Fpdi\Fpdi();
            $pages = $fpdi->setSourceFile($tmp);
            $this->assertSame(2, $pages, 'FPDI free parser should read both pages');
        } finally {
            @unlink($tmp);
        }
    }
}
