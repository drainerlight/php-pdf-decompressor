<?php

declare(strict_types=1);

namespace PdfDecompressor\Tests\Unit;

use PdfDecompressor\Console\Cli;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PdfDecompressor\Console\Cli
 */
class CliTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures';

    /** @var string[] paths to clean up */
    private $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    /**
     * @param string[] $args arguments after the script name
     * @return array{0:int,1:string,2:string} [exitCode, stdout, stderr]
     */
    private function invokeCli(array $args): array
    {
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');

        $exit = (new Cli())->run(array_merge(['pdf-decompress'], $args), $stdout, $stderr);

        rewind($stdout);
        rewind($stderr);

        return [$exit, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }

    private function tempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cli_') . '.pdf';
        $this->tempFiles[] = $path;
        @unlink($path); // we want the name, not an existing file
        return $path;
    }

    public function testHelpExitsZeroAndPrintsUsage(): void
    {
        [$exit, $stdout] = $this->invokeCli(['--help']);
        $this->assertSame(0, $exit);
        $this->assertNotFalse(strpos($stdout, 'Usage: pdf-decompress'));
    }

    public function testMissingArgumentsIsUsageError(): void
    {
        [$exit, , $stderr] = $this->invokeCli([self::FIXTURES . '/compressed.pdf']);
        $this->assertSame(2, $exit);
        $this->assertNotFalse(strpos($stderr, 'Usage:'));
    }

    public function testUnknownOptionIsUsageError(): void
    {
        [$exit, , $stderr] = $this->invokeCli(['--bogus', 'a', 'b']);
        $this->assertSame(2, $exit);
        $this->assertNotFalse(strpos($stderr, 'Unknown option'));
    }

    public function testUnreadableInputIsRuntimeError(): void
    {
        [$exit, , $stderr] = $this->invokeCli(['/no/such/file.pdf', $this->tempPath()]);
        $this->assertSame(1, $exit);
        $this->assertNotFalse(strpos($stderr, 'Cannot read input file'));
    }

    public function testRefusesToOverwriteWithoutForce(): void
    {
        $output = $this->tempPath();
        file_put_contents($output, 'existing');

        [$exit, , $stderr] = $this->invokeCli([self::FIXTURES . '/compressed.pdf', $output]);
        $this->assertSame(1, $exit);
        $this->assertNotFalse(strpos($stderr, 'already exists'));
    }

    public function testSuccessfulConversion(): void
    {
        $output = $this->tempPath();

        [$exit, $stdout, $stderr] = $this->invokeCli([self::FIXTURES . '/compressed.pdf', $output]);

        $this->assertSame(0, $exit, $stderr);
        $this->assertNotFalse(strpos($stdout, 'Wrote'));
        $this->assertStringStartsWith('%PDF-1.4', (string) file_get_contents($output));
    }

    public function testForceOverwritesExistingOutput(): void
    {
        $output = $this->tempPath();
        file_put_contents($output, 'existing');

        [$exit] = $this->invokeCli(['--force', self::FIXTURES . '/compressed.pdf', $output]);
        $this->assertSame(0, $exit);
        $this->assertStringStartsWith('%PDF-1.4', (string) file_get_contents($output));
    }

    public function testQuietSuppressesSuccessMessage(): void
    {
        [$exit, $stdout] = $this->invokeCli(['--quiet', self::FIXTURES . '/compressed.pdf', $this->tempPath()]);
        $this->assertSame(0, $exit);
        $this->assertSame('', $stdout);
    }

    public function testEncryptedInputIsReported(): void
    {
        $encrypted = $this->tempPath();
        file_put_contents(
            $encrypted,
            "%PDF-1.4\n"
            . "xref\n0 1\n0000000000 65535 f \n"
            . "trailer\n<< /Size 1 /Root 1 0 R /Encrypt 2 0 R >>\n"
            . "startxref\n9\n%%EOF"
        );

        [$exit, , $stderr] = $this->invokeCli([$encrypted, $this->tempPath()]);
        $this->assertSame(1, $exit);
        $this->assertNotFalse(strpos($stderr, 'Error:'));
    }
}
