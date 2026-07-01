<?php

declare(strict_types=1);

namespace PdfDecompressor\Reader;

/**
 * A forward/seekable cursor over the raw PDF bytes (ISO 32000-1, 7.2).
 *
 * Works on single bytes (PDF is a byte-oriented format); no character-set
 * interpretation happens here.
 */
final class ByteReader
{
    /** @var string */
    private $buffer;

    /** @var int */
    private $length;

    /** @var int */
    private $position = 0;

    public function __construct(string $buffer)
    {
        $this->buffer = $buffer;
        $this->length = strlen($buffer);
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        if ($position < 0 || $position > $this->length) {
            throw new \OutOfRangeException(
                'Position ' . $position . ' out of range [0, ' . $this->length . '].'
            );
        }
        $this->position = $position;
    }

    public function isEof(): bool
    {
        return $this->position >= $this->length;
    }

    /**
     * Return the byte at the current position (+ $ahead) without advancing,
     * or null if that position is out of range.
     */
    public function peek(int $ahead = 0): ?string
    {
        $index = $this->position + $ahead;
        if ($index < 0 || $index >= $this->length) {
            return null;
        }
        return $this->buffer[$index];
    }

    /**
     * Return the current byte and advance by one, or null at EOF.
     */
    public function read(): ?string
    {
        if ($this->position >= $this->length) {
            return null;
        }
        return $this->buffer[$this->position++];
    }

    /**
     * Read up to $count bytes and advance. Returns fewer bytes near EOF.
     */
    public function readBytes(int $count): string
    {
        if ($count <= 0) {
            return '';
        }
        $chunk = substr($this->buffer, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }

    /**
     * Advance (or rewind, for negative values) by $count bytes, clamped to
     * [0, length].
     */
    public function skip(int $count): void
    {
        $this->position = max(0, min($this->length, $this->position + $count));
    }

    /**
     * Find the next occurrence of $needle at or after $from (default: current
     * position). Returns null if not found. Does not move the cursor.
     */
    public function indexOf(string $needle, ?int $from = null): ?int
    {
        $pos = strpos($this->buffer, $needle, $from ?? $this->position);
        return $pos === false ? null : $pos;
    }
}
