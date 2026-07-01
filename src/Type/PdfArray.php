<?php

declare(strict_types=1);

namespace PdfDecompressor\Type;

/**
 * An array object (ISO 32000-1, 7.3.6): an ordered list of PDF objects.
 */
final class PdfArray implements PdfObject
{
    /** @var PdfObject[] */
    private $items;

    /**
     * @param PdfObject[] $items
     */
    public function __construct(array $items = [])
    {
        $this->items = array_values($items);
    }

    /**
     * @return PdfObject[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function get(int $index): ?PdfObject
    {
        return $this->items[$index] ?? null;
    }
}
