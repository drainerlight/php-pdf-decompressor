<?php

declare(strict_types=1);

namespace PdfDecompressor\Type;

/**
 * An indirect reference "N G R" (ISO 32000-1, 7.3.10).
 */
final class PdfReference implements PdfObject
{
    /** @var int */
    private $objectNumber;

    /** @var int */
    private $generationNumber;

    public function __construct(int $objectNumber, int $generationNumber)
    {
        $this->objectNumber     = $objectNumber;
        $this->generationNumber = $generationNumber;
    }

    public function getObjectNumber(): int
    {
        return $this->objectNumber;
    }

    public function getGenerationNumber(): int
    {
        return $this->generationNumber;
    }
}
