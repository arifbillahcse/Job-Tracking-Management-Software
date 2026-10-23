<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Thrown to abort (and roll back) a change that would leave the system without an administrator. */
final class LastAdministratorException extends RuntimeException
{
}
