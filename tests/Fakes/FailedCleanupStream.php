<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Tests\Fakes;

use RuntimeException;

/** Simulates cleanup failure without OS-specific permissions or file locks. */
final class FailedCleanupStream
{
    /** @var resource|null */
    public $context;

    public static bool $exists = true;

    public static int $attempts = 0;

    public static string $failureMethod = 'scandir';

    public static string $failureMode = 'exception';

    public static string $snapshotFailure = '';

    public static ?string $sourcePath = null;

    public static int $closedInputs = 0;

    private bool $isSource = false;

    private int $position = 0;

    /**
     * Expose a directory until the test releases its synthetic workspace.
     *
     * @return array{mode: int, size: int}|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        $path = \str_replace('\\', '/', $path);

        // Synthetic paths must stay readable regardless of the runner's UID/GID.
        return self::$exists
            ? ['mode' => $path === self::$sourcePath || \str_ends_with($path, '.dxf') || \str_ends_with($path, '/residue') ? 0100666 : 0040777, 'size' => 8]
            : false;
    }

    /** Fail before cleanup can enumerate or remove any actual files. */
    public function dir_opendir(string $path, int $options): bool
    {
        return self::$failureMethod === 'scandir' ? $this->fail() : true;
    }

    /** Enumerate a synthetic child only when testing unlink failure. */
    public function dir_readdir(): string|false
    {
        $entries = self::$failureMethod === 'unlink' ? ['.', '..', 'residue'] : ['.', '..'];

        return $entries[$this->position++] ?? false;
    }

    /** Close the synthetic directory handle. */
    public function dir_closedir(): bool
    {
        return true;
    }

    /** Fail a synthetic child removal. */
    public function unlink(string $path): bool
    {
        return $this->fail();
    }

    /** Fail a synthetic directory removal. */
    public function rmdir(string $path, int $options): bool
    {
        return $this->fail();
    }

    /** Provide readable bytes for successful delivery before cleanup fails. */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->isSource = \str_replace('\\', '/', $path) === self::$sourcePath;
        if (self::$snapshotFailure === 'open' && \str_contains($mode, 'b') && $mode !== 'rb') {
            \trigger_error('Synthetic snapshot open failure.', \E_USER_WARNING);

            return false;
        }

        return true;
    }

    /** Record release of a caller-owned source handle. */
    public function stream_close(): void
    {
        if ($this->isSource) {
            self::$closedInputs++;
        }
    }

    /** Simulate a snapshot write without creating real artifacts. */
    public function stream_write(string $contents): int
    {
        return self::$snapshotFailure === 'short' ? 0 : \strlen($contents);
    }

    /** Accept the snapshot writer's lock without creating an OS lock. */
    public function stream_lock(int $operation): bool
    {
        return true;
    }

    /** Accept truncation by file_put_contents without modifying real files. */
    public function stream_truncate(int $size): bool
    {
        return true;
    }

    /** Allow a synthetic workspace to be created during snapshot validation. */
    public function mkdir(string $path, int $mode, int $options): bool
    {
        return true;
    }

    /** Read the synthetic artifact without touching a real source file. */
    public function stream_read(int $count): string
    {
        $contents = \substr('artifact', $this->position, $count);
        $this->position += \strlen($contents);

        return $contents;
    }

    /** Report the end of the synthetic artifact. */
    public function stream_eof(): bool
    {
        return $this->position >= 8;
    }

    /**
     * Expose the synthetic artifact size to file_get_contents.
     *
     * @return array{mode: int, size: int}
     */
    public function stream_stat(): array
    {
        return ['mode' => 0100666, 'size' => 8];
    }

    /** Emit a deterministic filesystem failure containing private diagnostic text. */
    private function fail(): bool
    {
        self::$attempts++;

        if (self::$failureMode === 'false') {
            return false;
        }

        if (self::$failureMode === 'warning') {
            \trigger_error('Private C:/drawings/customer.dwg cleanup failure.', \E_USER_WARNING);

            return false;
        }

        if (self::$failureMode === 'anonymous') {
            throw new class('Private C:/drawings/customer.dwg cleanup failure.') extends RuntimeException {};
        }

        throw new RuntimeException('Private C:/drawings/customer.dwg cleanup failure.');
    }
}
