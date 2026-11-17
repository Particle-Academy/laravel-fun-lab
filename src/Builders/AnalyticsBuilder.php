<?php

declare(strict_types=1);

namespace LaravelFunLab\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LaravelFunLab\Contracts\AnalyticsServiceContract;
use LaravelFunLab\Enums\AwardType;
use LaravelFunLab\Models\EventLog;

/**
 * AnalyticsBuilder - Fluent Builder for Analytics Queries
 *
 * Provides a clean, expressive API for querying engagement analytics from event logs.
 * Supports filtering, aggregation, time-series queries, and export-ready data formats.
 *
 * Usage:
 * LFL::analytics()->byType('points')->period('weekly')->total();
 * LFL::analytics()->activeUsers()->between($start, $end)->count();
 * LFL::analytics()->achievementCompletion('first-login')->rate();
 */
class AnalyticsBuilder implements AnalyticsServiceContract
{
    protected ?AwardType $awardType = null;

    protected ?string $awardableType = null;

    /** Set by forAwardable(): narrows to that one awardable, not just its type. */
    protected int|string|null $awardableId = null;

    /** @var \Carbon\Carbon|Carbon|null */
    protected $startDate = null;

    /** @var \Carbon\Carbon|Carbon|null */
    protected $endDate = null;

    protected ?string $source = null;

    protected ?string $achievementSlug = null;

    /**
     * Filter analytics by award type.
     *
     * @param  AwardType|string  $type  The award type to filter by
     * @return $this
     */
    public function byType(AwardType|string $type): self
    {
        $this->awardType = $type instanceof AwardType ? $type : AwardType::from($type);

        return $this;
    }

    /**
     * Filter analytics by awardable type (User, Team, Organization, etc.).
     *
     * @param  string  $type  The fully qualified class name of the awardable model
     * @return $this
     */
    public function forAwardableType(string $type): self
    {
        $this->awardableType = $type;

        return $this;
    }

    /**
     * Filter analytics by specific awardable entity.
     *
     * @param  Model  $awardable  The awardable model instance
     * @return $this
     */
    public function forAwardable(Model $awardable): self
    {
        $this->awardableType = get_class($awardable);

        return $this->whereAwardable($awardable);
    }

    /**
     * Filter analytics by source.
     *
     * @param  string  $source  The source identifier
     * @return $this
     */
    public function fromSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    /**
     * Filter analytics by achievement slug.
     *
     * @param  string  $slug  The achievement slug
     * @return $this
     */
    public function forAchievement(string $slug): self
    {
        $this->achievementSlug = $slug;

        return $this;
    }

    /**
     * Filter analytics by time period.
     *
     * @param  string  $period  The time period: 'daily', 'weekly', 'monthly', 'yearly', or null for all-time
     * @return $this
     */
    public function period(?string $period = null): self
    {
        if ($period === null || $period === 'all-time') {
            $this->startDate = null;
            $this->endDate = null;

            return $this;
        }

        $this->endDate = Carbon::now();

        $this->startDate = match ($period) {
            'daily' => Carbon::today(),
            'weekly' => Carbon::now()->startOfWeek(),
            'monthly' => Carbon::now()->startOfMonth(),
            'yearly' => Carbon::now()->startOfYear(),
            default => null,
        };

        return $this;
    }

    /**
     * Filter analytics between two dates.
     *
     * @param  \Carbon\Carbon|Carbon|string  $start  Start date
     * @param  \Carbon\Carbon|Carbon|string|null  $end  End date (defaults to now)
     * @return $this
     */
    public function between(\Carbon\Carbon|Carbon|string $start, \Carbon\Carbon|Carbon|string|null $end = null): self
    {
        $this->startDate = ($start instanceof \Carbon\Carbon || $start instanceof Carbon) ? $start : Carbon::parse($start);
        $this->endDate = ($end instanceof \Carbon\Carbon || $end instanceof Carbon) ? $end : ($end !== null ? Carbon::parse($end) : Carbon::now());

        return $this;
    }

    /**
     * Filter analytics since a specific date.
     *
     * @param  \Carbon\Carbon|Carbon|string  $date  The start date
     * @return $this
     */
    public function since(\Carbon\Carbon|Carbon|string $date): self
    {
        $this->startDate = ($date instanceof \Carbon\Carbon || $date instanceof Carbon) ? $date : Carbon::parse($date);
        $this->endDate = Carbon::now();

        return $this;
    }

    /**
     * Filter analytics until a specific date.
     *
     * @param  \Carbon\Carbon|Carbon|string  $date  The end date
     * @return $this
     */
    public function until(\Carbon\Carbon|Carbon|string $date): self
    {
        $this->endDate = ($date instanceof \Carbon\Carbon || $date instanceof Carbon) ? $date : Carbon::parse($date);

        return $this;
    }

    /**
     * Get the total count of events matching the current filters.
     */
    public function count(): int
    {
        return $this->buildQuery()->count();
    }

    /**
     * Get the total amount (sum) for cumulative award types.
     */
    public function total(): float
    {
        return (float) $this->buildQuery()->sum('amount') ?? 0.0;
    }

    /**
     * Get the average amount for cumulative award types.
     */
    public function average(): float
    {
        return (float) $this->buildQuery()->avg('amount') ?? 0.0;
    }

    /**
     * Get the minimum amount for cumulative award types.
     */
    public function min(): ?float
    {
        $result = $this->buildQuery()->min('amount');

        return $result !== null ? (float) $result : null;
    }

    /**
     * Get the maximum amount for cumulative award types.
     */
    public function max(): ?float
    {
        $result = $this->buildQuery()->max('amount');

        return $result !== null ? (float) $result : null;
    }

    /**
     * Get distinct active users (awardables) within the filtered period.
     */
    public function activeUsers(): int
    {
        $driver = DB::getDriverName();
        $distinctExpr = match ($driver) {
            'sqlite' => "COUNT(DISTINCT awardable_type || '-' || awardable_id)",
            'mysql', 'mariadb' => "COUNT(DISTINCT CONCAT(awardable_type, '-', awardable_id))",
            'pgsql' => "COUNT(DISTINCT CONCAT(awardable_type, '-', awardable_id))",
            default => "COUNT(DISTINCT CONCAT(awardable_type, '-', awardable_id))",
        };

        return (int) $this->buildQuery()
            ->selectRaw("{$distinctExpr} as unique_count")
            ->value('unique_count') ?? 0;
    }

    /**
     * Get achievement completion rate for a specific achievement.
     *
     * Calculates the percentage of unique awardables that have earned the specified achievement.
     * Requires filtering by achievement slug first or passing it as a parameter.
     *
     * Note: This calculates completion rate based on awardables who have any activity in the
     * filtered period. For a true completion rate, you may want to compare against total
     * awardables in the system (e.g., from profiles table).
     *
     * @param  string|null  $achievementSlug  Optional achievement slug (if not already filtered)
     * @return float Completion rate as a percentage (0-100)
     */
    public function achievementCompletionRate(?string $achievementSlug = null): float
    {
        if ($achievementSlug !== null) {
            $this->forAchievement($achievementSlug);
        }

        if ($this->achievementSlug === null) {
            return 0.0;
        }

        // Get total unique awardables that have any activity (baseline for comparison)
        $baseQuery = EventLog::query();

        // Apply all filters except achievement filter
        if ($this->awardType !== null) {
            $baseQuery->ofAwardType($this->awardType);
        }
        if ($this->awardableType !== null) {
            $baseQuery->where('awardable_type', $this->awardableType);
        }
        if ($this->awardableId !== null) {
            $baseQuery->where('awardable_id', $this->awardableId);
        }
        if ($this->source !== null) {
            $baseQuery->fromSource($this->source);
        }
        if ($this->startDate !== null || $this->endDate !== null) {
            if ($this->startDate !== null && $this->endDate !== null) {
                $baseQuery->between($this->startDate, $this->endDate);
            } elseif ($this->startDate !== null) {
                $baseQuery->where('occurred_at', '>=', $this->startDate);
            } elseif ($this->endDate !== null) {
                $baseQuery->where('occurred_at', '<=', $this->endDate);
            }
        }

        $driver = DB::getDriverName();
        $distinctExpr = match ($driver) {
            'sqlite' => "COUNT(DISTINCT awardable_type || '-' || awardable_id)",
            'mysql', 'mariadb' => "COUNT(DISTINCT CONCAT(awardable_type, '-', awardable_id))",
            'pgsql' => "COUNT(DISTINCT CONCAT(awardable_type, '-', awardable_id))",
            default => "COUNT(DISTINCT CONCAT(awardable_type, '-', awardable_id))",
        };

        $totalEligible = (int) $baseQuery
            ->selectRaw("{$distinctExpr} as unique_count")
            ->value('unique_count') ?? 0;

        if ($totalEligible === 0) {
            return 0.0;
        }

        // Get count of awardables that actually earned this achievement
        $completed = (int) $this->buildQuery()
            ->whereNotNull('achievement_slug')
            ->where('achievement_slug', $this->achievementSlug)
            ->selectRaw("{$distinctExpr} as unique_count")
            ->value('unique_count') ?? 0;

        return ($completed / $totalEligible) * 100;
    }

    /**
     * Get time-series data grouped by a time interval.
     *
     * @param  string  $interval  The interval: 'hour', 'day', 'week', 'month', 'year'
     * @return array<int, array<string, mixed>> Array of intervals with counts and totals
     */
    public function timeSeries(string $interval = 'day'): array
    {
        $query = $this->buildQuery();
        $driver = DB::getDriverName();

        // Use database-specific date formatting functions
        $dateFormat = match ($interval) {
            'hour' => match ($driver) {
                'sqlite' => "strftime('%Y-%m-%d %H:00:00', occurred_at)",
                'mysql', 'mariadb' => "DATE_FORMAT(occurred_at, '%Y-%m-%d %H:00:00')",
                'pgsql' => "to_char(occurred_at, 'YYYY-MM-DD HH24:00:00')",
                default => "DATE_FORMAT(occurred_at, '%Y-%m-%d %H:00:00')",
            },
            'day' => match ($driver) {
                'sqlite' => "strftime('%Y-%m-%d', occurred_at)",
                'mysql', 'mariadb' => "DATE_FORMAT(occurred_at, '%Y-%m-%d')",
                'pgsql' => "to_char(occurred_at, 'YYYY-MM-DD')",
                default => "DATE_FORMAT(occurred_at, '%Y-%m-%d')",
            },
            'week' => match ($driver) {
                'sqlite' => "strftime('%Y-%W', occurred_at)",
                'mysql', 'mariadb' => "DATE_FORMAT(occurred_at, '%Y-%u')",
                'pgsql' => "to_char(occurred_at, 'IYYY-IW')",
                default => "DATE_FORMAT(occurred_at, '%Y-%u')",
            },
            'month' => match ($driver) {
                'sqlite' => "strftime('%Y-%m', occurred_at)",
                'mysql', 'mariadb' => "DATE_FORMAT(occurred_at, '%Y-%m')",
                'pgsql' => "to_char(occurred_at, 'YYYY-MM')",
                default => "DATE_FORMAT(occurred_at, '%Y-%m')",
            },
            'year' => match ($driver) {
                'sqlite' => "strftime('%Y', occurred_at)",
                'mysql', 'mariadb' => "DATE_FORMAT(occurred_at, '%Y')",
                'pgsql' => "to_char(occurred_at, 'YYYY')",
                default => "DATE_FORMAT(occurred_at, '%Y')",
            },
            default => match ($driver) {
                'sqlite' => "strftime('%Y-%m-%d', occurred_at)",
                'mysql', 'mariadb' => "DATE_FORMAT(occurred_at, '%Y-%m-%d')",
                'pgsql' => "to_char(occurred_at, 'YYYY-MM-DD')",
                default => "DATE_FORMAT(occurred_at, '%Y-%m-%d')",
            },
        };

        $results = $query
            ->selectRaw("{$dateFormat} as period")
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        return $results->map(function ($row) {
            return [
                'period' => $row->period,
                'count' => (int) $row->count,
                'total' => (float) $row->total,
            ];
        })->toArray();
    }

    /**
     * Get aggregated data grouped by award type.
     *
     * @return array<string, array<string, mixed>> Array keyed by award type with counts and totals
     */
    public function byAwardType(): array
    {
        $results = $this->buildQuery()
            ->selectRaw('award_type')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->groupBy('award_type')
            ->get();

        return $results->mapWithKeys(function ($row) {
            return [
                $row->award_type => [
                    'count' => (int) $row->count,
                    'total' => (float) $row->total,
                ],
            ];
        })->toArray();
    }

    /**
     * Get aggregated data grouped by awardable type.
     *
     * @return array<string, array<string, mixed>> Array keyed by awardable type with counts and totals
     */
    public function byAwardableType(): array
    {
        $results = $this->buildQuery()
            ->selectRaw('awardable_type')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->selectRaw('COUNT(DISTINCT awardable_id) as unique_awardables')
            ->groupBy('awardable_type')
            ->get();

        return $results->mapWithKeys(function ($row) {
            return [
                $row->awardable_type => [
                    'count' => (int) $row->count,
                    'total' => (float) $row->total,
                    'unique_awardables' => (int) $row->unique_awardables,
                ],
            ];
        })->toArray();
    }

    /**
     * Get aggregated data grouped by source.
     *
     * @return array<string, array<string, mixed>> Array keyed by source with counts and totals
     */
    public function bySource(): array
    {
        $results = $this->buildQuery()
            ->selectRaw("COALESCE(source, 'unknown') as source")
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->groupBy('source')
            ->get();

        return $results->mapWithKeys(function ($row) {
            return [
                $row->source => [
                    'count' => (int) $row->count,
                    'total' => (float) $row->total,
                ],
            ];
        })->toArray();
    }

    /**
     * Get export-ready data as an array.
     *
     * @param  int|null  $limit  Optional limit on number of records
     * @return array<int, array<string, mixed>> Array of event log records
     */
    public function export(?int $limit = null): array
    {
        // Ties (rows logged in the same instant) break on id, newest first, so
        // the order is a property of the data rather than of the database.
        $query = $this->buildQuery()->orderBy('occurred_at', 'desc')->orderBy('id', 'desc');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->map(function (EventLog $log) {
            return [
                'id' => $log->id,
                'event_type' => $log->event_type,
                'award_type' => $log->award_type,
                'awardable_type' => $log->awardable_type,
                'awardable_id' => $log->awardable_id,
                'achievement_slug' => $log->achievement_slug,
                'amount' => $log->amount,
                'reason' => $log->reason,
                'source' => $log->source,
                'occurred_at' => $log->occurred_at?->toIso8601String(),
                'context' => $log->context,
            ];
        })->toArray();
    }

    /**
     * Get the underlying query builder for custom queries.
     *
     * @return Builder<EventLog>
     */
    public function query(): Builder
    {
        return $this->buildQuery();
    }

    /**
     * Build the base query with all filters applied.
     *
     * @return Builder<EventLog>
     */
    protected function buildQuery(): Builder
    {
        $query = EventLog::query();

        // Filter by award type
        if ($this->awardType !== null) {
            $query->ofAwardType($this->awardType);
        }

        // Filter by awardable type
        if ($this->awardableType !== null) {
            $query->where('awardable_type', $this->awardableType);
        }
        if ($this->awardableId !== null) {
            $query->where('awardable_id', $this->awardableId);
        }

        // Filter by achievement slug
        if ($this->achievementSlug !== null) {
            $query->where('achievement_slug', $this->achievementSlug);
        }

        // Filter by source
        if ($this->source !== null) {
            $query->fromSource($this->source);
        }

        // Filter by date range
        if ($this->startDate !== null || $this->endDate !== null) {
            if ($this->startDate !== null && $this->endDate !== null) {
                $query->between($this->startDate, $this->endDate);
            } elseif ($this->startDate !== null) {
                $query->where('occurred_at', '>=', $this->startDate);
            } elseif ($this->endDate !== null) {
                $query->where('occurred_at', '<=', $this->endDate);
            }
        }

        return $query;
    }

    /**
     * Apply awardable filter to the query.
     *
     * @return $this
     */
    protected function whereAwardable(Model $awardable): self
    {
        // Applied in buildQuery() beside the type. Before 0.8.0 this was a
        // no-op, so forAwardable() answered for every awardable of the type.
        $this->awardableId = $awardable->getKey();

        return $this;
    }
}
