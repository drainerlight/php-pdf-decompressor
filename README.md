# php-pdf-decompressor

**Make modern PDFs readable by legacy parsers — in pure PHP.**

[![CI](https://github.com/drainerlight/php-pdf-decompressor/actions/workflows/ci.yml/badge.svg)](https://github.com/drainerlight/php-pdf-decompressor/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-%E2%89%A57.4-8892BF.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Converts PDF 1.5+ files that use **compressed cross-reference streams** (`/Type /XRef`)
and **object streams** (`/Type /ObjStm`) into a **classic PDF 1.4** structure that
parsers such as the **free parser shipped with FPDI** can read.

A **qpdf-free / Ghostscript-free** alternative: no external binaries, no PHP
extensions beyond `ext-zlib`. Works on shared hosting where you can't install
anything.

> This PDF document probably uses a compression technique which is not supported
> by the free parser shipped with FPDI.

If you have ever seen that error — this library is the fix.

---

- [Why](#why)
- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
  - [Use it with FPDI](#use-it-with-fpdi)
  - [As a library](#as-a-library)
  - [Command line](#command-line)
- [How it works](#how-it-works)
- [What's supported](#whats-supported)
- [FAQ](#faq)
- [Contributing](#contributing)
- [License](#license)

## Why

Modern PDFs (produced by most current tools) store their cross-reference table and
many objects **compressed**. Older, pure-PHP PDF importers — most notably the free
parser bundled with [FPDI](https://www.setasign.com/fpdi) — can't read that and
abort. The usual fixes require a **system binary**:

```bash
qpdf --object-streams=disable --stream-data=uncompress --force-version=1.4 in.pdf out.pdf
```

…which you often can't install (shared hosting, locked-down servers). This library
does the same job **in pure PHP**, so you can normalize a PDF at runtime and hand it
straight to FPDI.

## Requirements

- PHP **7.4+** (tested through 8.3)
- `ext-zlib`

## Installation

```bash
composer require drainerlight/php-pdf-decompressor
```

## Usage

### Use it with FPDI

The most common use case — normalize only when needed, then import as usual:

```php
use PdfDecompressor\Normalizer;
use setasign\Fpdi\Fpdi;

$file = 'modern.pdf';

// Only rewrite the file if it uses features the free parser rejects.
if (Normalizer::isCompressed(file_get_contents($file))) {
    (new Normalizer())->normalizeFile($file, $file = 'normalized.pdf');
}

$pdf = new Fpdi();
$pdf->setSourceFile($file); // no more "compression technique not supported"
$pdf->importPage(1);
```

### As a library

```php
use PdfDecompressor\Normalizer;

$normalizer = new Normalizer();

// File to file
$normalizer->normalizeFile('input.pdf', 'output.pdf');

// Bytes to bytes
$classicBytes = $normalizer->normalize(file_get_contents('input.pdf'));
```

### Command line

```bash
vendor/bin/pdf-decompress [--force] [--quiet] input.pdf output.pdf
```

Exit codes: `0` success, `1` runtime error (I/O, unreadable/encrypted PDF), `2`
usage error.

## How it works

The same steps a tool like qpdf performs, implemented from the ISO 32000-1 spec:

1. **Parse** the cross-reference table — classic table *or* compressed
   cross-reference stream, following `/Prev` chains and hybrid `/XRefStm`.
2. **Unpack** every object, including those packed inside object streams (ObjStm).
3. **Rewrite** everything as uncompressed indirect objects with a classic
   cross-reference table and a minimal trailer.

If `startxref` is missing or corrupt, it **rebuilds** the cross-reference table by
scanning the file for object definitions.

## What's supported

| Feature | Status |
| --- | --- |
| Compressed cross-reference streams (`/XRef`) | ✅ |
| Object streams (`/ObjStm`) | ✅ |
| `/Prev` chains + hybrid `/XRefStm` | ✅ |
| Broken / missing `startxref` (rebuild) | ✅ |
| Generation numbers preserved | ✅ |
| Encrypted PDFs (`/Encrypt`) | ⛔ detected & rejected (no decryption) |
| Filters other than FlateDecode (LZW, ASCII85, …) | ⛔ not yet |
| `/Extends` object-stream chaining | ⛔ not yet |

See [PLANNING.md](PLANNING.md) for the roadmap and known edge cases.

> **Not affiliated with Setasign or FPDI.** This is an independent, clean-room
> implementation based solely on the public ISO 32000-1 specification. "FPDI" and
> "qpdf" are named only to describe compatibility and purpose.

## FAQ

**How do I fix "This PDF document probably uses a compression technique which is not
supported by the free parser shipped with FPDI"?**
That error means the PDF uses a compressed cross-reference stream and/or object
streams (PDF 1.5+). Run the file through this library first, then hand the result to
FPDI — see [Use it with FPDI](#use-it-with-fpdi). No commercial add-on or system
binary required.

**How can I read a PDF 1.5, 1.6 or 1.7 with FPDI's free parser?**
Normalize it to a classic PDF 1.4 structure with `Normalizer::normalizeFile()` and
open the output with FPDI as usual.

**How do I decompress a PDF in PHP without qpdf or Ghostscript?**
This library reimplements the relevant part of `qpdf --object-streams=disable
--stream-data=uncompress` in pure PHP. It only needs `ext-zlib`, so it runs on
shared hosting where you can't install binaries.

**What are object streams (ObjStm) and cross-reference streams?**
They are PDF 1.5+ features that pack many objects into one compressed stream and
replace the classic `xref` table with a compressed one. They shrink files but break
parsers that only understand the traditional cross-reference table.

**Does it decrypt password-protected PDFs?**
No. Encrypted PDFs (with an `/Encrypt` dictionary) are detected and rejected with a
clear error rather than producing garbage.

**Is this a replacement for the commercial FPDI PDF-Parser add-on?**
It solves the same "compressed PDF won't open" problem for free, from a different
angle: instead of teaching the parser to read compressed PDFs, it rewrites the PDF
into a form the free parser already understands. It is an independent, clean-room
project and is not affiliated with Setasign.

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). In short:
`composer install`, keep `composer test` green on PHP 7.4+, and add a test for any
new behavior.

## License

MIT — see [LICENSE](LICENSE).
