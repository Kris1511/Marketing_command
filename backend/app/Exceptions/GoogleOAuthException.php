<?php

namespace App\Exceptions;

use Exception;

class GoogleOAuthException extends Exception
{
    public bool $authRequired;
    public string $platform;

    public function __construct(
        string $message = "Google authorization expired or was revoked. Reconnection required.",
        string $platform = "google",
        bool $authRequired = true,
        int $code = 401,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->authRequired = $authRequired;
        $this->platform = $platform;
    }

    public function isAuthRequired(): bool
    {
        return $this->authRequired;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }
}
