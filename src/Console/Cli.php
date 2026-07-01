<?php

declare(strict_types=1);

namespace PdfDecompressor\Console;

use PdfDecompressor\Exception\PdfDecompressorException;
use PdfDecompressor\Normalizer;

/**
 * Command-line front end for {@see Normalizer}. The stream handles are injected
 * so the whole thing is testable without spawning a process.
 *
 * Exit codes: 0 success, 1 runtime error (I/O, unreadable/encrypted PDF, …),
 * 2 usage error (bad arguments).
 */
final class Cli
{
    /**
     * @param string[]         $argv   full argv (element 0 is the script name)
     * @param resource         $stdout
     * @param resource         $stderr
     */
    public function run(array $argv, $stdout, $stderr): int
    {
        $arguments  = array_slice($argv, 1);
        $force      = false;
        $quiet      = false;
        $positional = [];

        foreach ($arguments as $argument) {
            switch ($argument) {
                case '-h':
                case '--help':
                    $this->printUsage($stdout);
                    return 0;
                case '-f':
                case '--force':
                    $force = true;
                    break;
                case '-q':
                case '--quiet':
                    $quiet = true;
                    break;
                default:
                    if ($argument !== '' && $argument[0] === '-') {
                        fwrite($stderr, "Unknown option: {$argument}\n");
                        $this->printUsage($stderr);
                        return 2;
                    }
                    $positional[] = $argument;
            }
        }

        if (count($positional) !== 2) {
            $this->printUsage($stderr);
            return 2;
        }
        [$input, $output] = $positional;

        if (!is_file($input) || !is_readable($input)) {
            fwrite($stderr, "Cannot read input file: {$input}\n");
            return 1;
        }
        if (is_file($output) && !$force) {
            fwrite($stderr, "Output file already exists (use --force to overwrite): {$output}\n");
            return 1;
        }

        try {
            (new Normalizer())->normalizeFile($input, $output);
        } catch (PdfDecompressorException $e) {
            fwrite($stderr, 'Error: ' . $e->getMessage() . "\n");
            return 1;
        } catch (\Throwable $e) {
            fwrite($stderr, 'Unexpected error: ' . $e->getMessage() . "\n");
            return 1;
        }

        if (!$quiet) {
            fwrite($stdout, 'Wrote ' . $output . ' (' . filesize($output) . " bytes)\n");
        }

        return 0;
    }

    /**
     * @param resource $stream
     */
    private function printUsage($stream): void
    {
        fwrite(
            $stream,
            "Usage: pdf-decompress [--force] [--quiet] <input.pdf> <output.pdf>\n\n"
            . "Convert a PDF that uses compressed cross-reference streams and/or object\n"
            . "streams (PDF 1.5+) into a classic PDF 1.4 that legacy parsers such as\n"
            . "FPDI's free parser can read.\n\n"
            . "Options:\n"
            . "  -f, --force   overwrite the output file if it already exists\n"
            . "  -q, --quiet   do not print the success message\n"
            . "  -h, --help    show this help\n"
        );
    }
}
