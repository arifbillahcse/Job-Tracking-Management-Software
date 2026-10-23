<?php

declare(strict_types=1);

namespace App\Services\Import;

use RuntimeException;

/** The file as a whole cannot be imported (unreadable, wrong type, missing columns, too big). The message is safe to show. */
final class ImportFileException extends RuntimeException
{
}
