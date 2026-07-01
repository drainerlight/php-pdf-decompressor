<?php

declare(strict_types=1);

namespace PdfDecompressor\Exception;

/**
 * Thrown when a stream filter (e.g. FlateDecode) or its predictor cannot be applied.
 */
class FilterException extends PdfDecompressorException
{
}
