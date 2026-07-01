<?php

declare(strict_types=1);

namespace PdfDecompressor\Serializer;

use PdfDecompressor\Exception\PdfDecompressorException;
use PdfDecompressor\Type\PdfArray;
use PdfDecompressor\Type\PdfBoolean;
use PdfDecompressor\Type\PdfDictionary;
use PdfDecompressor\Type\PdfName;
use PdfDecompressor\Type\PdfNull;
use PdfDecompressor\Type\PdfNumeric;
use PdfDecompressor\Type\PdfObject;
use PdfDecompressor\Type\PdfReference;
use PdfDecompressor\Type\PdfStream;
use PdfDecompressor\Type\PdfString;

/**
 * Serializes a {@see PdfObject} back into PDF syntax (ISO 32000-1, 7.3).
 *
 * Streams are always written with a correct direct /Length equal to the byte
 * length of their (unchanged) data, which removes any dependency on indirect
 * length objects in the output.
 */
final class PdfSerializer
{
    public function serialize(PdfObject $object): string
    {
        if ($object instanceof PdfNull) {
            return 'null';
        }
        if ($object instanceof PdfBoolean) {
            return $object->getValue() ? 'true' : 'false';
        }
        if ($object instanceof PdfNumeric) {
            return $this->number($object->getValue());
        }
        if ($object instanceof PdfString) {
            return $this->literalString($object->getValue());
        }
        if ($object instanceof PdfName) {
            return '/' . $this->name($object->getValue());
        }
        if ($object instanceof PdfReference) {
            return $object->getObjectNumber() . ' ' . $object->getGenerationNumber() . ' R';
        }
        if ($object instanceof PdfArray) {
            return $this->arrayObject($object);
        }
        if ($object instanceof PdfStream) {
            return $this->stream($object);
        }
        if ($object instanceof PdfDictionary) {
            return $this->dictionary($object);
        }

        throw new PdfDecompressorException('Cannot serialize object of type ' . get_class($object) . '.');
    }

    /**
     * @param int|float $value
     */
    private function number($value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        // Fixed-point, no exponent, trailing zeros trimmed.
        $text = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        return ($text === '' || $text === '-') ? '0' : $text;
    }

    private function literalString(string $value): string
    {
        // Escape the characters that would otherwise break a literal string.
        // \r is escaped so readers do not normalise a raw CR into LF (7.3.4.2).
        $escaped = strtr($value, [
            '\\' => '\\\\',
            '('  => '\\(',
            ')'  => '\\)',
            "\r" => '\\r',
        ]);
        return '(' . $escaped . ')';
    }

    private function name(string $value): string
    {
        $result = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            $code = ord($char);
            if ($code < 0x21 || $code > 0x7E || strpos("()<>[]{}/%#", $char) !== false) {
                $result .= sprintf('#%02X', $code);
            } else {
                $result .= $char;
            }
        }
        return $result;
    }

    private function arrayObject(PdfArray $array): string
    {
        $parts = [];
        foreach ($array->getItems() as $item) {
            $parts[] = $this->serialize($item);
        }
        return '[' . implode(' ', $parts) . ']';
    }

    private function dictionary(PdfDictionary $dictionary): string
    {
        $result = '<<';
        foreach ($dictionary->getEntries() as $key => $value) {
            $result .= ' /' . $this->name((string) $key) . ' ' . $this->serialize($value);
        }
        return $result . ' >>';
    }

    private function stream(PdfStream $stream): string
    {
        $data    = $stream->getData();
        $entries = $stream->getDictionary()->getEntries();
        // Force a correct, direct /Length for the (unchanged) data.
        $entries['Length'] = new PdfNumeric(strlen($data));

        return $this->dictionary(new PdfDictionary($entries))
            . "\nstream\n" . $data . "\nendstream";
    }
}
