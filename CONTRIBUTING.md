# Contributing

Thanks for your interest in improving php-pdf-decompressor!

## Getting started

```bash
git clone https://github.com/drainerlight/php-pdf-decompressor.git
cd php-pdf-decompressor
composer install
composer test
```

The test suite must stay green on **PHP 7.4+**.

## Guidelines

- **Add a test** for any new behavior or bug fix.
- Match the existing style: `declare(strict_types=1)`, typed signatures, PSR-4,
  small focused classes.
- Reference the relevant **ISO 32000-1** section in comments when implementing PDF
  behavior.
- **Clean-room only:** implement from the public PDF specification. Do not copy from,
  or derive from, any proprietary PDF parser.

## Reporting bugs

Open an issue with:
- what you did and what happened,
- the PHP version,
- if possible, a minimal PDF (or a description of its structure) that reproduces it.

Please do not attach confidential documents.

## Scope

Good first contributions: additional stream filters (LZW, ASCII85), `/Extends`
object-stream chaining, and hardening against malformed input. See
[PLANNING.md](PLANNING.md) for the roadmap.
