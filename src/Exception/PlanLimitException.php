<?php

declare(strict_types=1);

namespace Lexis\Exception;

/**
 * Thrown on HTTP 402 — the organization hit a plan quota (indexes, documents,
 * or monthly searches). Upgrading the plan or freeing headroom (deleting
 * documents, dropping unused indexes) is the only fix; retrying won't help.
 */
final class PlanLimitException extends LexisException {}
