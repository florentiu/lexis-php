<?php

declare(strict_types=1);

namespace Lexis\Exception;

/**
 * Thrown on HTTP 409 — a sync run is no longer in a state that accepts the
 * requested operation (e.g. you tried to push documents to a run that's
 * already committed or aborted). Start a fresh run instead.
 */
final class ConflictException extends LexisException {}
