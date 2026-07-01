#!/usr/bin/env bash
#
# Reproduces the test fixtures. Requires qpdf (only for regenerating fixtures —
# NOT needed to run the test suite; the generated PDFs are committed).
#
# base.pdf            classic PDF produced by a normal generator (here: FPDF)
# compressed.pdf      PDF 1.5 with an object stream + cross-reference stream
#                     (this is the "hard" input that the free FPDI parser rejects)
# expected_classic.pdf reference output: qpdf-decompressed classic PDF 1.4
#
set -euo pipefail
cd "$(dirname "$0")"

if ! command -v qpdf >/dev/null 2>&1; then
  echo "qpdf is required to regenerate fixtures." >&2
  exit 1
fi

# base.pdf is expected to already exist (a plain classic PDF). If you need to
# recreate it, use any PDF generator that emits a classic xref table.

qpdf --object-streams=generate --compress-streams=y --stream-data=compress \
     base.pdf compressed.pdf

qpdf --object-streams=disable --stream-data=uncompress --force-version=1.4 \
     compressed.pdf expected_classic.pdf

echo "Fixtures regenerated."
