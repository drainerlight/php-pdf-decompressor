<?php

declare(strict_types=1);

namespace PdfDecompressor\Type;

/**
 * A stream object (ISO 32000-1, 7.3.8): a dictionary followed by raw stream data.
 * The data is stored exactly as read (still encoded/compressed); decoding is the
 * job of the filter layer, not the parser.
 */
final class PdfStream implements PdfObject
{
    /** @var PdfDictionary */
    private $dictionary;

    /** @var string */
    private $data;

    public function __construct(PdfDictionary $dictionary, string $data)
    {
        $this->dictionary = $dictionary;
        $this->data       = $data;
    }

    public function getDictionary(): PdfDictionary
    {
        return $this->dictionary;
    }

    public function getData(): string
    {
        return $this->data;
    }
}
