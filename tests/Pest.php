<?php

declare(strict_types=1);

use Mattmy\DwgConverter\Tests\TestCase;

pest()->in('Unit');
pest()->extend(TestCase::class)->in('Feature', 'Integration');
