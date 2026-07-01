<?php

declare(strict_types=1);

namespace PdfDecompressor\Type;

/**
 * An indirect object definition "N G obj ... endobj" (ISO 32000-1, 7.3.10):
 * an object number, a generation number and the contained value.
 */
final class PdfIndirectObject implements PdfObject
{
    /** @var int */
    private $objectNumber;

    /** @var int */
    private $generationNumber;

    /** @var PdfObject */
    private $value;

    public function __construct(int $objectNumber, int $generationNumber, PdfObject $value)
    {
        $this->objectNumber     = $objectNumber;
        $this->generationNumber = $generationNumber;
        $this->value            = $value;
    }

    public function getObjectNumber(): int
    {
        return $this->objectNumber;
    }

    public function getGenerationNumber(): int
    {
        return $this->generationNumber;
    }

    public function getValue(): PdfObject
    {
        return $this->value;
    }
}
