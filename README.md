# php-pdf-decompressor

**Decompress a PDF in pure PHP** — convert PDF 1.5+ **cross-reference streams**
(`/Type /XRef`) and **object streams** (`/Type /ObjStm`) into a **classic PDF 1.4
structure**, so PDF parsers that only understand the traditional cross-reference
table (such as the **free parser shipped with FPDI**) can read them.

A **qpdf-free / Ghostscript-free alternative**: no external binaries, no PHP
extensions beyond `ext-zlib`. MIT licensed.

> **Not affiliated with Setasign or FPDI.** Independent, clean-room implementation
> based solely on the public ISO 32000-1 specification. "FPDI" and "qpdf" are named
> only to describe compatibility and purpose.

## The problem it solves

The free FPDI parser aborts on modern (PDF 1.5+) documents with:

> This PDF document probably uses a compression technique which is not supported
> by the free parser shipped with FPDI.

The cause: the PDF uses a **compressed cross-reference stream** and/or **object
streams (ObjStm)** instead of a classic `xref` table. `php-pdf-decompressor`
rewrites such a file into an equivalent classic-structure PDF that the free parser
accepts — the same effect as:

```
qpdf --object-streams=disable --stream-data=uncompress --force-version=1.4 in.pdf out.pdf
```

…but in **pure PHP**, so it works on shared hosting where you cannot install qpdf
or Ghostscript.

## Installation

```bash
composer require drainerlight/php-pdf-decompressor
```

## Usage

```php
use PdfDecompressor\Normalizer;

$normalizer = new Normalizer();

// Only convert when needed (object streams / compressed xref present)
if (Normalizer::isCompressed(file_get_contents('input.pdf'))) {
    $normalizer->normalizeFile('input.pdf', 'output.pdf');
}

// …then feed output.pdf to FPDI's free parser as usual.
```

## Status

**Phase 0 (scaffold).** Working and tested: the `FlateDecode` filter incl. PNG/TIFF
predictors, and `Normalizer::isCompressed()`. The full `Normalizer::normalize()`
conversion is delivered across phases 1–4 — see [PLANNING.md](PLANNING.md).

## Development

```bash
composer install
composer test
```

## Keywords

PHP PDF decompress · decompress PDF PHP · PDF object stream · ObjStm · PDF
cross-reference stream · convert PDF 1.5 to 1.4 · FPDI compressed xref · FPDI free
parser compression technique not supported · qpdf alternative PHP.

## License

MIT — see [LICENSE](LICENSE).
