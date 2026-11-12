<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A cash order that can't be recorded, with the reason in words the owner can act on.
 */
class CannotRecordOrder extends RuntimeException {}
