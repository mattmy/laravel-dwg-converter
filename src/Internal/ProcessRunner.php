<?php

declare(strict_types=1);

namespace Mattmy\DwgConverter\Internal;

use Mattmy\DwgConverter\Exceptions\DwgOperationFailed;
use Mattmy\DwgConverter\Exceptions\InvalidDwg;
use Mattmy\DwgConverter\Exceptions\LibreDwgUnavailable;

/**
 * Defines the internal seam for executing LibreDWG processes.
 */
interface ProcessRunner
{
    /**
     * Verify that the selected executable supports an operation.
     *
     * @throws LibreDwgUnavailable
     */
    public function assertAvailable(
        string $executable,
        string $operation,
        ?string $expectedTool = null,
        string $stage = 'convert',
    ): void;

    /**
     * Execute one isolated LibreDWG command.
     *
     * @param  list<string>  $command
     *
     * @throws DwgOperationFailed
     * @throws InvalidDwg
     */
    public function run(
        array $command,
        Workspace $workspace,
        float $timeout,
        int $maxOutputBytes,
        string $operation,
        ?string $stdoutPath = null,
        string $stage = 'convert',
    ): void;
}
