<?php

declare(strict_types=1);

namespace PdfDecompressor\Exception;

/**
 * Thrown when a byte-level read goes out of range (e.g. a stale cross-reference
 * offset). A domain exception so callers get a clean error instead of a bare
 * \OutOfRangeException surfacing as an "unexpected" failure.
 */
class ReaderException extends PdfDecompressorException
{
}
