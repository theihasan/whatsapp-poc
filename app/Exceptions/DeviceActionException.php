<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A device lifecycle request that cannot be performed in the device's current state.
 * The message is safe to show to users.
 */
class DeviceActionException extends RuntimeException {}
