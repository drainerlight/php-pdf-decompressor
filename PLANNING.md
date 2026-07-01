# php-pdf-decompressor — Projektplanung

## 1. Problem & Ziel

Der **kostenlose** PDF-Parser von FPDI (`setasign/fpdi`) kann PDFs ab Version 1.5
nicht lesen, wenn sie **komprimierte Cross-Reference-Streams** (`/Type /XRef`) oder
**Object-Streams** (`/Type /ObjStm`) verwenden. Er bricht an genau einer Stelle ab:

```
vendor/setasign/fpdi/src/PdfParser/CrossReference/CrossReference.php:248-260
  → throw CrossReferenceException::COMPRESSED_XREF
  → "This PDF document probably uses a compression technique which is not
     supported by the free parser shipped with FPDI."
```

Die kommerzielle Erweiterung `setasign/fpdi-pdf-parser` löst das (kostenpflichtig).

**Ziel dieses Projekts:** eine **reine PHP-Bibliothek** (MIT, keine System-Binaries
wie qpdf/Ghostscript), die eine PDF 1.5+ verlustfrei in eine **klassische
PDF-1.4-Struktur** umschreibt — klassische XRef-Tabelle, Objekte aus Object-Streams
ausgepackt, Streams optional dekomprimiert. Danach kann jeder klassische Parser
(FPDI-Free, TCPDF-Import u. a.) die Datei lesen.

Konzeptionell ist das der `qpdf --object-streams=disable --stream-data=uncompress
--force-version=1.4`-Schritt, nur in PHP.

## 2. Nicht-Ziele (bewusst außen vor)

- Kein Rendering, keine Textextraktion, kein Editing.
- Keine Verschlüsselung/Entschlüsselung (`/Encrypt`) — erkennen und sauber abweisen.
- Keine Linearisierung/Optimierung.
- Keine Reparatur kaputter PDFs jenseits des Nötigen.

## 3. Rechtlicher Rahmen (Clean-Room)

- Implementierung **ausschließlich** aus der offenen Spezifikation **ISO 32000-1**
  (bzw. Adobe PDF Reference 1.7) und dem MIT-lizenzierten FPDI-Code.
- **Kein Einblick** in den kommerziellen `fpdi-pdf-parser`-Code; nichts daraus ableiten.
- Kein Marken-Trittbrettfahren: eigener Name, keine „FPDI"-Marke im Paketnamen,
  keine Suggestion einer offiziellen/endorsten Verbindung. README stellt klar:
  „nicht mit Setasign affiliiert".
- Commits/Kommentare referenzieren nur ISO-32000-Abschnitte.

## 4. Öffentliche API (Zielbild)

```php
use PdfDecompressor\Normalizer;

$normalizer = new Normalizer();

// Datei -> Datei
$normalizer->normalizeFile('input.pdf', 'output.pdf');

// String -> String
$plainBytes = $normalizer->normalize($pdfBytes);

// Nur prüfen, ob eine Umwandlung nötig ist
if (Normalizer::isCompressed($pdfBytes)) { ... }
```

Optionaler späterer Zusatz: ein FPDI-Bridge-Reader, der sich an der
`CrossReference`-Naht einklinkt (Phase 6, optional).

## 5. Architektur / Komponenten

| Komponente | Aufgabe | ISO-Ref |
|---|---|---|
| `Reader\ByteReader` | Cursor über die Rohbytes, Suchen/Slicen | 7.2 |
| `Lexer\Tokenizer` | PDF-Tokens (Zahlen, Namen, Strings, Delimiter) | 7.2 |
| `Parser\ObjectParser` | Objekte parsen: dict/array/name/num/string/ref/stream/bool/null | 7.3 |
| `Filter\FlateDecode` | zlib-Inflate + Predictoren (PNG/TIFF) | 7.4.4 |
| `CrossReference\TableReader` | klassische `xref`-Tabelle + `trailer` | 7.5.4 |
| `CrossReference\StreamReader` | Cross-Reference-Stream `/Type /XRef` (W/Index) | 7.5.8 |
| `ObjectStream\ObjectStreamParser` | `/Type /ObjStm` entpacken, Objekte indexieren | 7.5.7 |
| `Document\PdfDocument` | vollständiger Objektgraph (obj-num → Objekt) | — |
| `Writer\ClassicPdfWriter` | PDF 1.4 mit klassischer xref-Tabelle neu schreiben | 7.5.4 |
| `Normalizer` | Fassade / Orchestrierung | — |

### Datenfluss
```
Rohbytes
  → startxref finden → XRef lesen (Tabelle ODER Stream, inkl. Hybrid /XRefStm)
  → vollständigen Objektindex bauen (freie Objekte + in ObjStm komprimierte)
  → alle Objekte materialisieren (ObjStm auspacken)
  → ClassicPdfWriter: Objekte 1..n sequenziell schreiben, neue xref-Tabelle,
    ObjStm-/XRef-Stream-Objekte weglassen, Trailer, %%EOF
  → klassische PDF 1.4
```

## 6. Kernrisiken (die üblichen Bruchstellen)

1. **PNG-Predictoren** (Predictor ≥ 10, meist 12/Up) — häufigste Fehlerquelle bei
   XRef-Streams. Braucht saubere Sub/Up/Average/Paeth-Umkehr. → früh & gründlich testen.
2. **`/W`-Breiten & `/Index`** im XRef-Stream korrekt binär zerlegen (auch W-Feld = 0).
3. **Hybrid-Reference-Dateien** (`/XRefStm` neben klassischer Tabelle).
4. **Verschachtelte/mehrere `/Prev`-XRefs** (inkrementelle Updates) korrekt mergen
   (spätere Definition gewinnt).
5. **Objekt-Referenzen in Streams-Längen** (`/Length 12 0 R`) — Länge als Referenz.
6. **Verschlüsselte PDFs** — erkennen (`/Encrypt` im Trailer) und mit klarer
   Exception abweisen (kein stiller Datenmüll).

## 7. Phasenplan (test-getrieben)

- **Phase 0 — Gerüst (dieser Stand):** Projektstruktur, Composer/PSR-4, Lizenz,
  PHPUnit, Fixtures (via qpdf reproduzierbar), `FlateDecode` inkl. Predictoren
  (implementiert + getestet), `Normalizer::isCompressed()` (Heuristik + Test),
  `Normalizer::normalize()` als definierter Platzhalter (`NotImplementedException`)
  mit Contract-Tests, die die Zielsemantik festschreiben.
- **Phase 1 — Objekt-Parser (erledigt):** ByteReader, Tokenizer + Token,
  Type-Hierarchie (PdfNull/Boolean/Numeric/String/Name/Array/Dictionary/Reference/
  Stream/IndirectObject), ObjectParser inkl. Referenz-Disambiguierung und
  Stream-Lesen; Unit-Tests je Typ.
- **Phase 2 — Cross-Reference:** TableReader + StreamReader, Objektindex, Hybrid & Prev.
- **Phase 3 — Object-Streams:** ObjStm entpacken, Objekte auflösbar machen.
- **Phase 4 — Writer (erledigt):** PdfSerializer (Wert→Syntax, /Length direkt),
  PdfWriter (klassische Objekte + xref-Tabelle, Objektnummern erhalten, Container
  weggelassen), `Normalizer::normalize()` verdrahtet. Verifiziert gegen den echten
  FPDI-Free-Parser inkl. realer mehrseitiger Produktions-PDFs.
- **Phase 5 — Fassade/CLI (erledigt):** testbare `Console\Cli` (Streams injizierbar,
  Exit-Codes 0/1/2) + dünnes Binary `bin/pdf-decompress`; als Composer-`bin` registriert.
- **Phase 6 — optional:** FPDI-Bridge-Reader an der `CrossReference`-Naht.

## 8. Definition of Done (MVP = bis Phase 4)

- `normalize()` verwandelt alle Projekt-Fixtures in Dateien, die der **FPDI-Free-Parser
  ohne Fehler** öffnet und mit **gleicher Seitenzahl** wie das Original liest.
- Keine `ObjStm`/`/Type /XRef`-Objekte mehr in der Ausgabe.
- Verschlüsselte/nicht unterstützte PDFs werfen eine klare, dokumentierte Exception.
- CI grün auf PHP 7.4 und 8.2/8.3.

## 9. Teststrategie

- **Unit:** `FlateDecode` (bekannte Predictor-Vektoren), Tokenizer, ObjectParser,
  StreamReader (W/Index-Zerlegung an synthetischen Bytes).
- **Integration:** Fixtures aus `tests/fixtures/` (reproduzierbar via
  `tests/fixtures/generate.sh`, benötigt qpdf nur zur Erzeugung, nicht zum Testen).
  Assertions u. a. gegen den echten FPDI-Free-Parser (dev-only, falls installiert)
  bzw. strukturell (keine ObjStm/XRef-Streams, korrekte Objektzahl).
- **Round-Trip:** Objekt-Inhalte der Ausgabe gegen die qpdf-Referenz
  `expected_classic.pdf` vergleichen.

## 10. Bewusste Grenzen nach Phase 1 (in späteren Phasen zu schließen)

1. **Indirekte `/Length` + Scan-Fallback:** Ist `/Length` eine indirekte Referenz,
   kann sie erst mit dem XRef aufgelöst werden (Phase 2). Solange greift ein Scan
   nach `endstream`. Enthalten die (komprimierten) Stream-Bytes selbst die Folge
   `endstream`, schneidet der Scan zu früh ab. → In Phase 2 `/Length` echt auflösen
   und den Scan nur noch als letzte Rückfallebene nutzen.
2. **Literal-String-EOL:** Rohe CR/CRLF innerhalb `(...)` werden **nicht** zu LF
   normalisiert (Abweichung von 7.3.4.2). Bewusst: für verlustfreies Neuschreiben
   werden die Originalbytes bewahrt; die korrekte Escapes/Erhaltung beim Schreiben
   ist Sache von Phase 4.
3. **Keine Referenzauflösung:** Der ObjectParser liefert `PdfReference` als Wert,
   löst sie aber nicht auf — das übernimmt der Objektindex in Phase 2/3.
4. **Verschlüsselung** (`/Encrypt`) wird in Phase 1 noch nicht erkannt/abgewiesen
   (vorgesehen für die Trailer-Auswertung in Phase 2).
