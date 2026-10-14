<?php

namespace App\Exceptions\Quinos;

/** Network problem or an unexpected answer from Quinos. */
class QuinosRequestException extends QuinosException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
