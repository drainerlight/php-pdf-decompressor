<?php

declare(strict_types=1);

namespace PdfDecompressor\Filter;

use PdfDecompressor\Exception\FilterException;
use PdfDecompressor\Type\PdfArray;
use PdfDecompressor\Type\PdfDictionary;
use PdfDecompressor\Type\PdfName;
use PdfDecompressor\Type\PdfNumeric;
use PdfDecompressor\Type\PdfObject;
use PdfDecompressor\Type\PdfStream;

/**
 * Applies a stream's /Filter chain to its raw data (ISO 32000-1, 7.4).
 *
 * Supports the filters that matter for the structures this library must decode —
 * cross-reference streams and object streams — i.e. FlateDecode (incl. its
 * predictors) and the unencoded case. Other filters (LZW, ASCII85, DCT, …) are
 * rejected explicitly rather than producing silent garbage.
 */
final class StreamDecoder
{
    public static function decode(PdfStream $stream): string
    {
        $dictionary = $stream->getDictionary();
        $data       = $stream->getData();

        $filters = self::filterNames($dictionary->get('Filter'));
        if ($filters === []) {
            return $data;
        }

        $parms = self::parmsList($dictionary->get('DecodeParms'), count($filters));
        foreach ($filters as $i => $name) {
            switch ($name) {
                case 'FlateDecode':
                case 'Fl':
                    $data = FlateDecode::decode($data, $parms[$i]);
                    break;
                default:
                    throw new FilterException("Unsupported stream filter '{$name}'.");
            }
        }

        return $data;
    }

    /**
     * @return string[]
     */
    private static function filterNames(?PdfObject $filter): array
    {
        if ($filter instanceof PdfName) {
            return [$filter->getValue()];
        }
        if ($filter instanceof PdfArray) {
            $names = [];
            foreach ($filter->getItems() as $item) {
                if (!$item instanceof PdfName) {
                    throw new FilterException('Invalid entry in /Filter array.');
                }
                $names[] = $item->getValue();
            }
            return $names;
        }
        return [];
    }

    /**
     * @return array<int,array<string,int>> one parameter set per filter
     */
    private static function parmsList(?PdfObject $parms, int $count): array
    {
        $list = array_fill(0, $count, []);

        if ($parms instanceof PdfArray) {
            foreach ($parms->getItems() as $i => $item) {
                if ($i >= $count) {
                    break;
                }
                $list[$i] = self::parms($item);
            }
        } elseif ($parms !== null) {
            $list[0] = self::parms($parms);
        }

        return $list;
    }

    /**
     * @return array<string,int>
     */
    private static function parms(?PdfObject $parms): array
    {
        if (!$parms instanceof PdfDictionary) {
            return [];
        }
        $result = [];
        foreach (['Predictor', 'Colors', 'BitsPerComponent', 'Columns'] as $key) {
            $value = $parms->get($key);
            if ($value instanceof PdfNumeric && $value->isInteger()) {
                $result[$key] = (int) $value->getValue();
            }
        }
        return $result;
    }
}
