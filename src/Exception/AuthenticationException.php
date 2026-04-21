<?php

declare(strict_types=1);

namespace Lexis\Exception;

/**
 * Thrown on HTTP 401 — the API key is missing, malformed, or has been
 * revoked. Not retryable: fetch a new key and rebuild the client.
 */
final class AuthenticationException extends LexisException {}
