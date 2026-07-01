<?php

declare(strict_types=1);

namespace PdfDecompressor\Exception;

/**
 * Thrown when a PDF declares an /Encrypt dictionary. Decryption is out of scope;
 * failing loudly avoids silently emitting garbage.
 */
class EncryptionNotSupportedException extends PdfDecompressorException
{
}
