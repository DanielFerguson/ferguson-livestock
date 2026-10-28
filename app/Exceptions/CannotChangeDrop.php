<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A change to a drop that the rules don't allow, with the reason in words the owner can act on.
 */
class CannotChangeDrop extends RuntimeException {}
