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

    /**
     * Expose a directory until the test releases its synthetic workspace.
     *
     * @return array{mode: int}|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        return self::$exists ? ['mode' => 0040700] : false;
    }

    /** Fail before cleanup can enumerate or remove any actual files. */
    public function dir_opendir(string $path, int $options): bool
    {
        self::$attempts++;

        throw new RuntimeException('Synthetic cleanup failure.');
    }
}
