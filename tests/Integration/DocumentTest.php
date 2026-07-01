<?php

declare(strict_types=1);

namespace PdfDecompressor\Tests\Integration;

use PdfDecompressor\Document\Document;
use PdfDecompressor\Exception\CrossReferenceException;
use PdfDecompressor\Exception\EncryptionNotSupportedException;
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

    /**
     * The Phase-3 payoff: in compressed.pdf the Catalog and Pages live inside an
     * object stream, so this exercises full ObjStm unpacking end-to-end.
     */
    public function testResolvesCatalogAndPagesFromObjectStreamPdf(): void
    {
        $document = Document::parse(file_get_contents(self::FIXTURES . '/compressed.pdf'));

        $rootReference = $document->getTrailer()->get('Root');
        $this->assertInstanceOf(PdfReference::class, $rootReference);

        // Guard: the test is only meaningful if the catalog really is compressed.
        $rootEntry = $document->getCrossReferenceTable()->get($rootReference->getObjectNumber());
        $this->assertTrue($rootEntry->isCompressed(), 'catalog is expected to live in an object stream');

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

    public function testSelfReferentialStreamLengthIsRejected(): void
    {
        // Object 1 is a stream whose /Length points back at object 1 -> resolving
        // it must fail with a clear error instead of recursing forever.
        $header = "%PDF-1.4\n";
        $object = "1 0 obj\n<< /Length 1 0 R >>\nstream\nABCD\nendstream\nendobj\n";
        $offset = strlen($header);
        $prefix = $header . $object;
        $xrefAt = strlen($prefix);

        $xref = "xref\n0 2\n0000000000 65535 f \n"
            . sprintf('%010d', $offset) . " 00000 n \n"
            . "trailer\n<< /Size 2 /Root 1 0 R >>\n";
        $pdf = $prefix . $xref . "startxref\n{$xrefAt}\n%%EOF";

        $document = Document::parse($pdf);

        $this->expectException(CrossReferenceException::class);
        $document->getObject(1);
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
