<?php

declare(strict_types=1);

namespace PdfDecompressor;

use PdfDecompressor\Document\Document;
use PdfDecompressor\Exception\PdfDecompressorException;
use PdfDecompressor\Type\PdfDictionary;
use PdfDecompressor\Type\PdfName;
use PdfDecompressor\Type\PdfStream;
use PdfDecompressor\Writer\PdfWriter;

/**
 * Facade for converting a PDF 1.5+ that uses compressed cross-reference streams
 * and/or object streams into a classic PDF 1.4 structure that legacy parsers
 * (such as FPDI's free parser) can read.
 *
 * The pipeline: parse the document, resolve every object (unpacking object
 * streams), drop the object-stream and cross-reference-stream containers, and
 * rewrite everything as uncompressed indirect objects with a classic cross-
 * reference table.
 */
final class Normalizer
{
    /**
     * Convert PDF bytes into a classic-structure PDF and return the new bytes.
     *
     * @throws PdfDecompressorException on unreadable input or unsupported features
     */
    public function normalize(string $pdfBytes): string
    {
        if ($pdfBytes === '' || strncmp($pdfBytes, '%PDF-', 5) !== 0) {
            throw new PdfDecompressorException('Input does not look like a PDF (missing %PDF- header).');
        }

        $document = Document::parse($pdfBytes);
        $table    = $document->getCrossReferenceTable();

        $objects     = [];
        $generations = [];
        foreach ($table->getObjectNumbers() as $objectNumber) {
            if ($objectNumber === 0) {
                continue; // object 0 is always the free-list head
            }
            $entry = $table->get($objectNumber);
            if ($entry === null || $entry->isFree()) {
                continue;
            }

            $value = $document->getObject($objectNumber);
            if ($value === null) {
                continue;
            }
            // Drop the containers whose contents we have just inlined as
            // standalone objects (object streams and cross-reference streams).
            if ($value instanceof PdfStream && $this->isContainer($value)) {
                continue;
            }

            $objects[$objectNumber]     = $value;
            $generations[$objectNumber] = $entry->isUncompressed() ? $entry->getGenerationNumber() : 0;
        }

        return (new PdfWriter())->write(
            $objects,
            $this->buildTrailer($document->getTrailer()),
            $generations
        );
    }

    /**
     * Read a PDF file, normalize it and write the result to $outputPath.
     *
     * @throws PdfDecompressorException on I/O errors or unsupported input
     */
    public function normalizeFile(string $inputPath, string $outputPath): void
    {
        $bytes = @file_get_contents($inputPath);
        if ($bytes === false) {
            throw new PdfDecompressorException('Cannot read input file: ' . $inputPath);
        }

        $result = $this->normalize($bytes);

        if (@file_put_contents($outputPath, $result) === false) {
            throw new PdfDecompressorException('Cannot write output file: ' . $outputPath);
        }
    }

    /**
     * Heuristic check whether a PDF uses features the free FPDI parser rejects,
     * i.e. object streams (/Type /ObjStm) or cross-reference streams (/Type /XRef).
     *
     * A byte-level heuristic sufficient for "does this need normalizing?"
     * decisions without fully parsing the file.
     */
    public static function isCompressed(string $pdfBytes): bool
    {
        return preg_match('~/Type\s*/ObjStm\b~', $pdfBytes) === 1
            || preg_match('~/Type\s*/XRef\b~', $pdfBytes) === 1;
    }

    private function isContainer(PdfStream $stream): bool
    {
        $type = $stream->getDictionary()->get('Type');
        return $type instanceof PdfName && in_array($type->getValue(), ['ObjStm', 'XRef'], true);
    }

    /**
     * Build a minimal classic trailer, keeping only the entries that belong there
     * and dropping cross-reference-stream specifics (/W, /Index, /Prev, /Type …).
     * /Size is added by the writer.
     */
    private function buildTrailer(PdfDictionary $sourceTrailer): PdfDictionary
    {
        $entries = [];
        foreach (['Root', 'Info', 'ID'] as $key) {
            if ($sourceTrailer->has($key)) {
                $entries[$key] = $sourceTrailer->get($key);
            }
        }
        return new PdfDictionary($entries);
    }
}
