<?php

declare(strict_types=1);

namespace Lexis\Exception;

/**
 * Thrown on HTTP 404 — the requested index or sync run doesn't exist (or
 * belongs to a different organization). For /search this usually means the
 * index slug was mistyped or no sync has been committed against it yet.
 */
final class NotFoundException extends LexisException {}
