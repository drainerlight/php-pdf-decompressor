<?php

declare(strict_types=1);

namespace PdfDecompressor\Type;

/**
 * A dictionary object (ISO 32000-1, 7.3.7): an unordered map from name keys
 * (without leading slash) to PDF objects.
 */
final class PdfDictionary implements PdfObject
{
    /** @var array<string,PdfObject> */
    private $entries;

    /**
     * @param array<string,PdfObject> $entries
     */
    public function __construct(array $entries = [])
    {
        $this->entries = $entries;
    }

    /**
     * @return array<string,PdfObject>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->entries);
    }

    public function get(string $key): ?PdfObject
    {
        return $this->entries[$key] ?? null;
    }
}
