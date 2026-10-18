<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The provider could not be reached and the request certainly did not take effect,
 * so it is safe to retry.
 */
class ProviderUnavailableException extends RuntimeException {}
