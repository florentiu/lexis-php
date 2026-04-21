<?php

declare(strict_types=1);

namespace Lexis\Exception;

/**
 * Thrown on HTTP 400 — the request body or query was malformed. The message
 * typically names the offending field (e.g. "Field 'index' is required",
 * "Document missing primary key \"id\""). Not retryable — fix the payload.
 */
final class ValidationException extends LexisException {}
