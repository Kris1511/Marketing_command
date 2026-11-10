<?php

namespace App\Exceptions;

use Exception;

/**
 * Raised when post media cannot be turned into a URL that Meta's servers can download
 * (not HTTPS, local/private host, unreachable, wrong Content-Type, unsupported format or size).
 *
 * These failures are about the media itself, so retrying the Graph API call with a different
 * access token can never fix them; callers use this type to skip that token fallback.
 */
class InstagramMediaException extends Exception
{
}
