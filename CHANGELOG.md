# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-07-01

Initial release.

### Added
- `Normalizer::normalize()` / `normalizeFile()` — convert a PDF 1.5+ that uses
  compressed cross-reference streams and/or object streams into a classic PDF 1.4
  that legacy parsers (e.g. FPDI's free parser) can read.
- `Normalizer::isCompressed()` — quick check whether a PDF needs normalizing.
- CLI `bin/pdf-decompress [--force] [--quiet] input.pdf output.pdf`.
- PDF object parser (lexer + typed object model) built from ISO 32000-1.
- Cross-reference reader: classic table **and** cross-reference stream, `/Prev`
  chains, hybrid `/XRefStm`.
- Object-stream (ObjStm) unpacking, incl. FlateDecode with PNG/TIFF predictors.
- Classic PDF 1.4 writer preserving object and generation numbers.
- Cross-reference **rebuild** fallback for broken/missing `startxref`.
- Encrypted-PDF detection (rejected cleanly instead of emitting garbage).
- Tested on PHP 7.4, 8.0, 8.1, 8.2 and 8.3.

[0.1.0]: https://github.com/drainerlight/php-pdf-decompressor/releases/tag/v0.1.0
