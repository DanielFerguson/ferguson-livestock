<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A change to an order that the rules don't allow, like a free item on an abandoned checkout or a delivery night
 * after the cutoff, with the reason in words the person making it can act on.
 */
class CannotChangeOrder extends RuntimeException {}
