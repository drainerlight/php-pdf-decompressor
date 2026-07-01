<?php

declare(strict_types=1);

namespace PdfDecompressor\Parser;

use PdfDecompressor\Type\PdfObject;
use PdfDecompressor\Type\PdfReference;

/**
 * Resolves an indirect reference to the object it points at.
 *
 * Injected into {@see ObjectParser} so a stream whose /Length is an indirect
 * reference can be read exactly (rather than falling back to scanning for
 * 'endstream'). Implemented by {@see \PdfDecompressor\Document\Document}.
 */
interface ObjectResolver
{
    public function resolve(PdfReference $reference): ?PdfObject;
}
