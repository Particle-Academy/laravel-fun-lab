<?php

declare(strict_types=1);

namespace LaravelFunLab\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a daily, weekly or monthly leaderboard is asked for while the
 * event log is off.
 *
 * A period board ranks on XP earned in the period, and the event log is the
 * only record of when XP was earned. Returning an empty board instead would
 * read exactly like a quiet week, so the request is refused.
 *
 * Extends InvalidArgumentException so existing handlers still catch it.
 */
class PeriodLeaderboardUnavailableException extends InvalidArgumentException {}
