<?php

declare(strict_types=1);

namespace PdfDecompressor\Document;

use PdfDecompressor\CrossReference\CrossReferenceReader;
use PdfDecompressor\CrossReference\CrossReferenceTable;
use PdfDecompressor\Exception\CrossReferenceException;
use PdfDecompressor\Exception\EncryptionNotSupportedException;
use PdfDecompressor\Exception\NotImplementedException;
use PdfDecompressor\Lexer\Tokenizer;
use PdfDecompressor\Parser\ObjectParser;
use PdfDecompressor\Parser\ObjectResolver;
use PdfDecompressor\Reader\ByteReader;
use PdfDecompressor\Type\PdfDictionary;
use PdfDecompressor\Type\PdfObject;
use PdfDecompressor\Type\PdfReference;

/**
 * A parsed PDF: cross-reference index + on-demand object access.
 *
 * Uncompressed objects are parsed lazily from their byte offset and memoized.
 * As an {@see ObjectResolver} it also lets the parser resolve indirect stream
 * lengths exactly. Objects stored inside object streams (ObjStm) are resolved in
 * phase 3; until then {@see getObject()} throws for them.
 */
final class Document implements ObjectResolver
{
    /** @var string */
    private $bytes;

    /** @var CrossReferenceTable */
    private $crossReferenceTable;

    /** @var array<int,PdfObject|null> */
    private $cache = [];

    /** @var array<int,true> object numbers currently being resolved (cycle guard) */
    private $resolving = [];

    private function __construct(string $bytes, CrossReferenceTable $crossReferenceTable)
    {
        $this->bytes               = $bytes;
        $this->crossReferenceTable = $crossReferenceTable;
    }

    /**
     * Parse the cross-reference information of a PDF and return a Document.
     *
     * @throws EncryptionNotSupportedException if the PDF declares /Encrypt
     * @throws CrossReferenceException on a malformed cross-reference structure
     */
    public static function parse(string $bytes): self
    {
        $table    = (new CrossReferenceReader())->read($bytes);
        $document = new self($bytes, $table);

        if ($document->isEncrypted()) {
            throw new EncryptionNotSupportedException(
                'This PDF is encrypted (/Encrypt present); decryption is not supported.'
            );
        }

        return $document;
    }

    public function getTrailer(): PdfDictionary
    {
        return $this->crossReferenceTable->getTrailer();
    }

    public function getCrossReferenceTable(): CrossReferenceTable
    {
        return $this->crossReferenceTable;
    }

    public function isEncrypted(): bool
    {
        return $this->crossReferenceTable->getTrailer()->has('Encrypt');
    }

    /**
     * Fetch the value of an indirect object by its number, or null if the object
     * is unknown or marked free.
     *
     * @throws NotImplementedException for objects stored inside an object stream
     * @throws CrossReferenceException if the stored offset does not hold that object
     */
    public function getObject(int $objectNumber): ?PdfObject
    {
        if (array_key_exists($objectNumber, $this->cache)) {
            return $this->cache[$objectNumber];
        }

        $entry = $this->crossReferenceTable->get($objectNumber);
        if ($entry === null || $entry->isFree()) {
            return $this->cache[$objectNumber] = null;
        }
        if ($entry->isCompressed()) {
            throw new NotImplementedException(
                "Object {$objectNumber} lives in an object stream; resolved in phase 3."
            );
        }
        if (isset($this->resolving[$objectNumber])) {
            throw new CrossReferenceException(
                "Circular reference while resolving object {$objectNumber}."
            );
        }

        $this->resolving[$objectNumber] = true;
        try {
            $reader = new ByteReader($this->bytes);
            $reader->setPosition($entry->getOffset());
            $parser   = new ObjectParser(new Tokenizer($reader), $this);
            $indirect = $parser->parseIndirectObject();

            if ($indirect->getObjectNumber() !== $objectNumber) {
                throw new CrossReferenceException(
                    "Cross-reference offset for object {$objectNumber} points at object "
                    . $indirect->getObjectNumber() . '.'
                );
            }

            return $this->cache[$objectNumber] = $indirect->getValue();
        } finally {
            unset($this->resolving[$objectNumber]);
        }
    }

    /**
     * If $object is an indirect reference, return the object it points at;
     * otherwise return it unchanged.
     */
    public function resolve(PdfReference $reference): ?PdfObject
    {
        return $this->getObject($reference->getObjectNumber());
    }
}
