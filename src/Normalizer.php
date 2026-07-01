<?php

declare(strict_types=1);

namespace PdfDecompressor;

use PdfDecompressor\Exception\NotImplementedException;
use PdfDecompressor\Exception\PdfDecompressorException;

/**
 * Facade for converting a PDF 1.5+ that uses compressed cross-reference streams
 * and/or object streams into a classic PDF 1.4 structure that legacy parsers
 * (such as FPDI's free parser) can read.
 *
 * This is the public entry point. The heavy lifting (parsing, cross-reference
 * resolution, object-stream unpacking and rewriting) is added in phases 1-4;
 * see PLANNING.md. Until then {@see normalize()} throws NotImplementedException,
 * while {@see isCompressed()} is already usable.
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

        // Phase 1-4: parse -> resolve xref (table/stream) -> unpack object streams
        // -> rewrite as classic PDF 1.4. Not implemented yet.
        throw new NotImplementedException(
            'Normalizer::normalize() is not implemented yet (planned for phase 4, see PLANNING.md).'
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
     * NOTE: this is a byte-level heuristic sufficient for "does this need
     * normalizing?" decisions. A precise, startxref-following detector arrives
     * with the cross-reference reader in phase 2.
     */
    public static function isCompressed(string $pdfBytes): bool
    {
        if (preg_match('~/Type\s*/ObjStm\b~', $pdfBytes) === 1) {
            return true;
        }
        if (preg_match('~/Type\s*/XRef\b~', $pdfBytes) === 1) {
            return true;
        }

        return false;
    }
}
