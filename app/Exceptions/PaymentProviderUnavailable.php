<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Stripe couldn't be reached, or refused the request.
 */
class PaymentProviderUnavailable extends RuntimeException {}
