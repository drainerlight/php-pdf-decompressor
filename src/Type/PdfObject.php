<?php

declare(strict_types=1);

namespace PdfDecompressor\Type;

/**
 * Marker interface for every parsed PDF object type (ISO 32000-1, 7.3).
 *
 * The type is preserved explicitly (rather than collapsing to native PHP values)
 * so the document can be rewritten losslessly: e.g. a name /Foo and a string
 * (Foo) both carry the bytes "Foo" but must be re-emitted differently.
 */
interface PdfObject
{
}
