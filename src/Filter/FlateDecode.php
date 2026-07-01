<?php

declare(strict_types=1);

namespace PdfUnstream\Filter;

use PdfUnstream\Exception\FilterException;

/**
 * FlateDecode filter (ISO 32000-1, 7.4.4) with predictor support (7.4.4.4).
 *
 * Handles the zlib/deflate decompression used by both object streams and
 * cross-reference streams, plus the PNG and TIFF predictor post-processing that
 * cross-reference streams almost always apply (typically Predictor 12 / PNG Up).
 *
 * Predictor parameters come from the stream dictionary's /DecodeParms:
 *   - Predictor        (default 1  = none)
 *   - Colors           (default 1)
 *   - BitsPerComponent (default 8)
 *   - Columns          (default 1)
 */
final class FlateDecode
{
    /**
     * Decompress $data and, if requested, reverse the predictor.
     *
     * @param array<string,int> $params /DecodeParms values (see class doc)
     */
    public static function decode(string $data, array $params = []): string
    {
        $inflated = self::inflate($data);

        $predictor = (int) ($params['Predictor'] ?? 1);
        if ($predictor <= 1) {
            return $inflated;
        }

        $colors  = (int) ($params['Colors'] ?? 1);
        $bpc     = (int) ($params['BitsPerComponent'] ?? 8);
        $columns = (int) ($params['Columns'] ?? 1);

        if ($predictor === 2) {
            return self::reverseTiffPredictor($inflated, $colors, $bpc, $columns);
        }

        // Predictor values >= 10 are the PNG predictors. The per-row filter-type
        // byte selects the concrete algorithm, so any value >= 10 is handled the
        // same way here.
        return self::reversePngPredictor($inflated, $colors, $bpc, $columns);
    }

    /**
     * Raw zlib/deflate inflate. Tries the zlib wrapper first, then a raw stream.
     */
    private static function inflate(string $data): string
    {
        $result = @gzuncompress($data);
        if ($result === false) {
            $result = @gzinflate($data);
        }
        if ($result === false) {
            throw new FilterException('FlateDecode: zlib inflate failed (corrupt or non-flate stream).');
        }

        return $result;
    }

    /**
     * Reverse a PNG predictor (ISO 32000-1, 7.4.4.4 / RFC 2083).
     *
     * Each row is prefixed with a filter-type byte; the filter is applied per byte
     * using the reconstructed left (a), up (b) and upper-left (c) bytes.
     */
    private static function reversePngPredictor(string $data, int $colors, int $bpc, int $columns): string
    {
        $bytesPerPixel = (int) max(1, (int) ceil(($colors * $bpc) / 8));
        $rowLength     = (int) ceil(($colors * $bpc * $columns) / 8);

        if ($rowLength <= 0) {
            throw new FilterException('FlateDecode: invalid predictor row length.');
        }

        $stride = $rowLength + 1; // + filter-type byte
        $length = strlen($data);
        $prev   = array_fill(0, $rowLength, 0);
        $out    = '';

        for ($offset = 0; $offset < $length; $offset += $stride) {
            $filterType = ord($data[$offset]);
            $row        = array_fill(0, $rowLength, 0);

            for ($i = 0; $i < $rowLength; $i++) {
                $pos = $offset + 1 + $i;
                if ($pos >= $length) {
                    break; // tolerate a short final row
                }
                $x = ord($data[$pos]);
                $a = $i >= $bytesPerPixel ? $row[$i - $bytesPerPixel] : 0;   // left
                $b = $prev[$i];                                             // up
                $c = $i >= $bytesPerPixel ? $prev[$i - $bytesPerPixel] : 0; // upper-left

                switch ($filterType) {
                    case 0: // None
                        $value = $x;
                        break;
                    case 1: // Sub
                        $value = $x + $a;
                        break;
                    case 2: // Up
                        $value = $x + $b;
                        break;
                    case 3: // Average
                        $value = $x + intdiv($a + $b, 2);
                        break;
                    case 4: // Paeth
                        $value = $x + self::paeth($a, $b, $c);
                        break;
                    default:
                        throw new FilterException(
                            'FlateDecode: unsupported PNG predictor filter type ' . $filterType . '.'
                        );
                }

                $row[$i] = $value & 0xFF;
            }

            foreach ($row as $byte) {
                $out .= chr($byte);
            }
            $prev = $row;
        }

        return $out;
    }

    /**
     * Reverse the TIFF Predictor 2 (horizontal differencing). Only the common
     * 8-bits-per-component case is supported; other depths are rare for the
     * cross-reference/object-stream use case and are rejected explicitly.
     */
    private static function reverseTiffPredictor(string $data, int $colors, int $bpc, int $columns): string
    {
        if ($bpc !== 8) {
            throw new FilterException('FlateDecode: TIFF predictor only supports 8 bits per component.');
        }

        $rowLength = $colors * $columns;
        if ($rowLength <= 0) {
            throw new FilterException('FlateDecode: invalid TIFF predictor row length.');
        }

        $length = strlen($data);
        $out    = '';

        for ($offset = 0; $offset < $length; $offset += $rowLength) {
            $row = array_fill(0, $rowLength, 0);
            for ($i = 0; $i < $rowLength; $i++) {
                $pos = $offset + $i;
                if ($pos >= $length) {
                    break;
                }
                $x       = ord($data[$pos]);
                $left    = $i >= $colors ? $row[$i - $colors] : 0;
                $row[$i] = ($x + $left) & 0xFF;
            }
            foreach ($row as $byte) {
                $out .= chr($byte);
            }
        }

        return $out;
    }

    /**
     * Paeth predictor function (RFC 2083, 6.6).
     */
    private static function paeth(int $a, int $b, int $c): int
    {
        $p  = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }
        if ($pb <= $pc) {
            return $b;
        }

        return $c;
    }
}
