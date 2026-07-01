<?php

declare(strict_types=1);

namespace PdfDecompressor\Exception;

/**
 * Thrown when the cross-reference table/stream cannot be located or parsed.
 */
class CrossReferenceException extends PdfDecompressorException
{
}
