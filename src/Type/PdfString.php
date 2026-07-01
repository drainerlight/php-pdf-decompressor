<?php

declare(strict_types=1);

namespace PdfDecompressor\Type;

/**
 * A string object (ISO 32000-1, 7.3.4). Holds the decoded raw bytes, regardless
 * of whether the source used literal "(...)" or hexadecimal "<...>" notation.
 */
final class PdfString implements PdfObject
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
