<?php

declare(strict_types=1);

namespace PdfDecompressor\Writer;

use PdfDecompressor\Serializer\PdfSerializer;
use PdfDecompressor\Type\PdfDictionary;
use PdfDecompressor\Type\PdfNumeric;
use PdfDecompressor\Type\PdfObject;

/**
 * Writes a classic PDF 1.4 file: uncompressed indirect objects followed by a
 * traditional cross-reference table and trailer (ISO 32000-1, 7.5.4).
 *
 * Object numbers are preserved (dropped numbers become free entries), so every
 * indirect reference in the object graph stays valid without renumbering.
 * All objects are written with generation 0.
 */
final class PdfWriter
{
    /** @var PdfSerializer */
    private $serializer;

    public function __construct(?PdfSerializer $serializer = null)
    {
        $this->serializer = $serializer ?? new PdfSerializer();
    }

    /**
     * @param array<int,PdfObject> $objects  objectNumber => value (0 is reserved)
     * @param PdfDictionary        $trailer  trailer entries (/Size is set here)
     */
    public function write(array $objects, PdfDictionary $trailer): string
    {
        ksort($objects);
        $size = ($objects === [] ? 0 : max(array_keys($objects))) + 1;

        $out     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($out);
            $out .= $number . " 0 obj\n" . $this->serializer->serialize($object) . "\nendobj\n";
        }

        $xrefOffset = strlen($out);
        $out       .= "xref\n0 " . $size . "\n";
        $out       .= "0000000000 65535 f\r\n"; // object 0: head of the free list
        for ($number = 1; $number < $size; $number++) {
            if (isset($offsets[$number])) {
                $out .= sprintf("%010d 00000 n\r\n", $offsets[$number]);
            } else {
                $out .= "0000000000 00000 f\r\n";
            }
        }

        $trailerEntries          = $trailer->getEntries();
        $trailerEntries['Size']  = new PdfNumeric($size);
        $out .= "trailer\n" . $this->serializer->serialize(new PdfDictionary($trailerEntries)) . "\n";
        $out .= "startxref\n" . $xrefOffset . "\n%%EOF\n";

        return $out;
    }
}
