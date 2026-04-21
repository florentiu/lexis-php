<?php

declare(strict_types=1);

namespace Lexis\Exception;

/**
 * Thrown on HTTP 5xx — the Lexis API hit an internal error. The SDK already
 * retried this with exponential backoff up to max_retries before surfacing
 * it, so by the time you see this exception the error is persistent and
 * worth escalating to the Lexis team with the response body attached.
 */
final class ServerException extends LexisException {}
