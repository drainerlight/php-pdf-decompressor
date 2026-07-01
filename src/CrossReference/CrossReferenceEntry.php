<?php

declare(strict_types=1);

namespace PdfDecompressor\CrossReference;

/**
 * A single cross-reference entry (ISO 32000-1, 7.5.4 and 7.5.8).
 *
 * The three logical fields mirror the cross-reference stream layout:
 *   - free (type 0):         unused
 *   - uncompressed (type 1): field2 = byte offset, field3 = generation number
 *   - compressed (type 2):   field2 = object-stream number, field3 = index in it
 */
final class CrossReferenceEntry
{
    public const TYPE_FREE         = 0;
    public const TYPE_UNCOMPRESSED = 1;
    public const TYPE_COMPRESSED   = 2;

    /** @var int */
    private $type;

    /** @var int */
    private $field2;

    /** @var int */
    private $field3;

    private function __construct(int $type, int $field2, int $field3)
    {
        $this->type   = $type;
        $this->field2 = $field2;
        $this->field3 = $field3;
    }

    public static function free(): self
    {
        return new self(self::TYPE_FREE, 0, 0);
    }

    public static function uncompressed(int $offset, int $generationNumber): self
    {
        return new self(self::TYPE_UNCOMPRESSED, $offset, $generationNumber);
    }

    public static function compressed(int $streamObjectNumber, int $indexInStream): self
    {
        return new self(self::TYPE_COMPRESSED, $streamObjectNumber, $indexInStream);
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function isFree(): bool
    {
        return $this->type === self::TYPE_FREE;
    }

    public function isUncompressed(): bool
    {
        return $this->type === self::TYPE_UNCOMPRESSED;
    }

    public function isCompressed(): bool
    {
        return $this->type === self::TYPE_COMPRESSED;
    }

    public function getOffset(): int
    {
        return $this->field2;
    }

    public function getGenerationNumber(): int
    {
        return $this->field3;
    }

    public function getStreamObjectNumber(): int
    {
        return $this->field2;
    }

    public function getIndexInStream(): int
    {
        return $this->field3;
    }
}
