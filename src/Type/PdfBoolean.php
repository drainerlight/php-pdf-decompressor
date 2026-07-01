<?php

declare(strict_types=1);

namespace PdfDecompressor\Type;

/**
 * A boolean object (ISO 32000-1, 7.3.2).
 */
final class PdfBoolean implements PdfObject
{
    /** @var bool */
    private $value;

    public function __construct(bool $value)
    {
        $this->value = $value;
    }

    public function getValue(): bool
    {
        return $this->value;
    }
}
