<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A message action that is not allowed in the message's current state. Safe to show.
 */
class MessageActionException extends RuntimeException {}
