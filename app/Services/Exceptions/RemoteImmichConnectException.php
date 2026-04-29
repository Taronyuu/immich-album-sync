<?php

namespace App\Services\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

class RemoteImmichConnectException extends RuntimeException
{
    public function isUnreachable(): bool
    {
        return $this->getPrevious() instanceof ConnectionException;
    }
}
