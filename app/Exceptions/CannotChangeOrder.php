<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A change to an order that the rules don't allow, like a free item on an abandoned checkout, with the reason in
 * words the owner can act on.
 */
class CannotChangeOrder extends RuntimeException {}
