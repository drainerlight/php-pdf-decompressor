<?php

declare(strict_types=1);

namespace PdfDecompressor\CrossReference;

use PdfDecompressor\Type\PdfDictionary;

/**
 * The merged cross-reference information for a document: a map from object
 * number to its {@see CrossReferenceEntry}, plus the (newest) trailer dictionary.
 */
final class CrossReferenceTable
{
    /** @var array<int,CrossReferenceEntry> */
    private $entries;

    /** @var PdfDictionary */
    private $trailer;

    /**
     * @param array<int,CrossReferenceEntry> $entries
     */
    public function __construct(array $entries, PdfDictionary $trailer)
    {
        $this->entries = $entries;
        $this->trailer = $trailer;
    }

    public function getTrailer(): PdfDictionary
    {
        return $this->trailer;
    }

    public function has(int $objectNumber): bool
    {
        return isset($this->entries[$objectNumber]);
    }

    public function get(int $objectNumber): ?CrossReferenceEntry
    {
        return $this->entries[$objectNumber] ?? null;
    }

    /**
     * @return array<int,CrossReferenceEntry> keyed by object number
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /**
     * @return int[] all known object numbers
     */
    public function getObjectNumbers(): array
    {
        return array_keys($this->entries);
    }
}
