<?php

declare(strict_types=1);

namespace LaravelFunLab\Exceptions;

use InvalidArgumentException;

/**
 * Thrown by AwardXpBuilder::save() when the award is rejected by the
 * validation pipeline, the per-action XP cap, or an opt-out check.
 *
 * Extends InvalidArgumentException so existing `catch (\Throwable)` blocks
 * in controllers still catch it, but lets callers discriminate rejection
 * from generic input errors.
 */
class AwardRejectedException extends InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly string $kind = 'rejected',
    ) {
        parent::__construct($message);
    }
}
