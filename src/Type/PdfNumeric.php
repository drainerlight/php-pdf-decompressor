<?php

declare(strict_types=1);

namespace PdfDecompressor\Type;

/**
 * A numeric object: integer or real (ISO 32000-1, 7.3.3).
 *
 * The value keeps its PHP type (int vs float) so integers and reals are
 * re-emitted faithfully.
 */
final class PdfNumeric implements PdfObject
{
    /** @var int|float */
    private $value;

    /**
     * @param int|float $value
     */
    public function __construct($value)
    {
        $this->value = $value;
    }

    /**
     * @return int|float
     */
    public function getValue()
    {
        return $this->value;
    }

    public function isInteger(): bool
    {
        return is_int($this->value);
    }
}
