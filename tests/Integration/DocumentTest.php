<?php

declare(strict_types=1);

namespace PdfDecompressor\Tests\Integration;

use PdfDecompressor\Document\Document;
use PdfDecompressor\Exception\EncryptionNotSupportedException;
use PdfDecompressor\Exception\NotImplementedException;
use PdfDecompressor\Type\PdfDictionary;
use PdfDecompressor\Type\PdfReference;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PdfDecompressor\Document\Document
 */
class DocumentTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures';

    /**
     * First real end-to-end resolution on a real (if simple) PDF: trailer /Root
     * -> Catalog -> Pages, with the correct page count.
     */
    public function testResolvesCatalogAndPagesFromClassicPdf(): void
    {
        $document = Document::parse(file_get_contents(self::FIXTURES . '/base.pdf'));

        $rootReference = $document->getTrailer()->get('Root');
        $this->assertInstanceOf(PdfReference::class, $rootReference);

        /** @var PdfDictionary $catalog */
        $catalog = $document->getObject($rootReference->getObjectNumber());
        $this->assertInstanceOf(PdfDictionary::class, $catalog);
        $this->assertSame('Catalog', $catalog->get('Type')->getValue());

        /** @var PdfDictionary $pages */
        $pages = $document->resolve($catalog->get('Pages'));
        $this->assertInstanceOf(PdfDictionary::class, $pages);
        $this->assertSame('Pages', $pages->get('Type')->getValue());
        $this->assertSame(2, $pages->get('Count')->getValue());
    }

    public function testCompressedObjectAccessIsDeferredToPhase3(): void
    {
        $document = Document::parse(file_get_contents(self::FIXTURES . '/compressed.pdf'));

        $compressedNumber = null;
        foreach ($document->getCrossReferenceTable()->getEntries() as $number => $entry) {
            if ($entry->isCompressed()) {
                $compressedNumber = $number;
                break;
            }
        }
        $this->assertNotNull($compressedNumber, 'expected at least one compressed object');

        $this->expectException(NotImplementedException::class);
        $document->getObject($compressedNumber);
    }

    public function testEncryptedPdfIsRejected(): void
    {
        // Minimal classic-xref PDF whose trailer declares /Encrypt. Object offsets
        // are irrelevant here — parse() only needs to read the trailer.
        $pdf = "%PDF-1.4\n"
            . "xref\n0 1\n0000000000 65535 f \n"
            . "trailer\n<< /Size 1 /Root 1 0 R /Encrypt 2 0 R >>\n"
            . "startxref\n9\n%%EOF";

        $this->expectException(EncryptionNotSupportedException::class);
        Document::parse($pdf);
    }
}
