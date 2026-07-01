<?php

declare(strict_types=1);

namespace PdfDecompressor\Lexer;

/**
 * A lexical token produced by {@see Tokenizer}.
 */
final class Token
{
    public const NUMBER      = 'number';
    public const NAME        = 'name';
    public const STRING      = 'string';
    public const DICT_OPEN   = 'dict_open';   // <<
    public const DICT_CLOSE  = 'dict_close';  // >>
    public const ARRAY_OPEN  = 'array_open';  // [
    public const ARRAY_CLOSE = 'array_close'; // ]
    public const KEYWORD     = 'keyword';     // obj, endobj, stream, R, true, ...
    public const EOF         = 'eof';

    /** @var string */
    private $type;

    /** @var mixed for NUMBER: int|float; NAME/STRING/KEYWORD: string; else null */
    private $value;

    /** @var int byte offset where the token starts */
    private $offset;

    /**
     * @param mixed $value
     */
    public function __construct(string $type, $value, int $offset)
    {
        $this->type   = $type;
        $this->value  = $value;
        $this->offset = $offset;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function is(string $type): bool
    {
        return $this->type === $type;
    }

    public function isKeyword(string $keyword): bool
    {
        return $this->type === self::KEYWORD && $this->value === $keyword;
    }
}
