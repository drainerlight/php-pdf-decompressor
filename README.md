# pdf-unstream

Pure-PHP conversion of PDF 1.5+ **cross-reference streams** (`/Type /XRef`) and
**object streams** (`/Type /ObjStm`) into a **classic PDF 1.4 structure** — so PDF
parsers that only understand the traditional cross-reference table (such as the
**free parser shipped with FPDI**) can read them.

No external binaries (no qpdf, no Ghostscript), no PHP extensions beyond `ext-zlib`.
MIT licensed.

> **Not affiliated with Setasign or FPDI.** This is an independent, clean-room
> implementation based solely on the public ISO 32000-1 specification. "FPDI" is
> mentioned only to describe compatibility.

## The problem it solves

The free FPDI parser aborts on modern PDFs with:

> This PDF document probably uses a compression technique which is not supported
> by the free parser shipped with FPDI.

`pdf-unstream` rewrites such a PDF into an equivalent classic-structure file that
the free parser accepts — the same effect as
`qpdf --object-streams=disable --stream-data=uncompress --force-version=1.4`,
but in pure PHP.

## Status

**Phase 0 (scaffold).** Working and tested: the `FlateDecode` filter incl. PNG/TIFF
predictors, and `Normalizer::isCompressed()`. The full `Normalizer::normalize()`
conversion is implemented across phases 1–4 — see [PLANNING.md](PLANNING.md).

## Intended usage

```php
use PdfUnstream\Normalizer;

$normalizer = new Normalizer();

if (Normalizer::isCompressed(file_get_contents('input.pdf'))) {
    $normalizer->normalizeFile('input.pdf', 'output.pdf');
}
```

## Development

```bash
composer install
composer test
```

## License

MIT — see [LICENSE](LICENSE).
