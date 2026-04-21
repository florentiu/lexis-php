<?php

declare(strict_types=1);

namespace Lexis\Exception;

/**
 * Thrown when the HTTP request never got a response — DNS failure, TCP
 * refused, TLS error, connection timeout, read timeout, etc. Status code is
 * 0 because no server response was ever received. Retried automatically;
 * you'll only see this after exhausting max_retries.
 */
final class NetworkException extends LexisException {}
