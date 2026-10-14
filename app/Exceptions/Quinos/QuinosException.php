<?php

namespace App\Exceptions\Quinos;

use RuntimeException;

/**
 * Base class. Messages must never contain passwords, cookies or tokens.
 */
class QuinosException extends RuntimeException {}
