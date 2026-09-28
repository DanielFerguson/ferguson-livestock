<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An order the shop can't take as it stands. The message says what to change, in plain English.
 */
class InvalidCheckout extends RuntimeException {}
