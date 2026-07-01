<?php

declare(strict_types=1);

namespace PdfDecompressor\Document;

use PdfDecompressor\CrossReference\CrossReferenceEntry;
use PdfDecompressor\CrossReference\CrossReferenceReader;
use PdfDecompressor\CrossReference\CrossReferenceTable;
use PdfDecompressor\Exception\CrossReferenceException;
use PdfDecompressor\Exception\EncryptionNotSupportedException;
use PdfDecompressor\Lexer\Tokenizer;
use PdfDecompressor\ObjectStream\ObjectStream;
use PdfDecompressor\Parser\ObjectParser;
use PdfDecompressor\Parser\ObjectResolver;
use PdfDecompressor\Reader\ByteReader;
use PdfDecompressor\Type\PdfDictionary;
use PdfDecompressor\Type\PdfObject;
use PdfDecompressor\Type\PdfReference;
use PdfDecompressor\Type\PdfStream;

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

    /** @var array<int,ObjectStream> parsed object streams keyed by their object number */
    private $objectStreams = [];

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
     * is unknown or marked free. Handles both uncompressed objects and objects
     * stored inside an object stream (ObjStm).
     *
     * @throws CrossReferenceException on a bad offset or a circular reference
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
        if (isset($this->resolving[$objectNumber])) {
            throw new CrossReferenceException(
                "Circular reference while resolving object {$objectNumber}."
            );
        }

        $this->resolving[$objectNumber] = true;
        try {
            $value = $entry->isCompressed()
                ? $this->readCompressedObject($objectNumber, $entry)
                : $this->readUncompressedObject($objectNumber, $entry);

            return $this->cache[$objectNumber] = $value;
        } finally {
            unset($this->resolving[$objectNumber]);
        }
    }

    private function readUncompressedObject(int $objectNumber, CrossReferenceEntry $entry): PdfObject
    {
        $reader = new ByteReader($this->bytes);
        $reader->setPosition($entry->getOffset());
        $indirect = (new ObjectParser(new Tokenizer($reader), $this))->parseIndirectObject();

        if ($indirect->getObjectNumber() !== $objectNumber) {
            throw new CrossReferenceException(
                "Cross-reference offset for object {$objectNumber} points at object "
                . $indirect->getObjectNumber() . '.'
            );
        }

        return $indirect->getValue();
    }

    private function readCompressedObject(int $objectNumber, CrossReferenceEntry $entry): PdfObject
    {
        $objectStream = $this->getObjectStream($entry->getStreamObjectNumber());

        $value = $objectStream->getObjectByNumber($objectNumber);
        if ($value === null) {
            throw new CrossReferenceException(
                "Object {$objectNumber} not found in object stream {$entry->getStreamObjectNumber()}."
            );
        }

        return $value;
    }

    private function getObjectStream(int $streamObjectNumber): ObjectStream
    {
        if (isset($this->objectStreams[$streamObjectNumber])) {
            return $this->objectStreams[$streamObjectNumber];
        }

        $container = $this->getObject($streamObjectNumber);
        if (!$container instanceof PdfStream) {
            throw new CrossReferenceException(
                "Object stream {$streamObjectNumber} is not a stream object."
            );
        }

        return $this->objectStreams[$streamObjectNumber] = ObjectStream::fromStream($container);
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
