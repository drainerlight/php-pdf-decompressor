<?php

declare(strict_types=1);

namespace PdfDecompressor\Type;

/**
 * A name object (ISO 32000-1, 7.3.5). Holds the name value without the leading
 * slash and with #xx escapes already decoded (e.g. /A#20B -> "A B").
 */
final class PdfName implements PdfObject
{
    /** @var string */
    private $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
