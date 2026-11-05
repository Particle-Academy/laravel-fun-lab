<?php

declare(strict_types=1);

namespace LaravelFunLab\Builders;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LaravelFunLab\Contracts\LeaderboardServiceContract;
use LaravelFunLab\Models\GamedMetric;
use LaravelFunLab\Models\Profile;
use LaravelFunLab\Models\ProfileMetric;

/**
 * LeaderboardBuilder - Fluent Builder for Leaderboard Queries
 *
 * Provides a clean, expressive API for constructing leaderboard queries
 * with filtering, sorting, pagination, and opt-out exclusion.
 *
 * Usage:
 * LFL::leaderboard()->for(User::class)->by('xp')->period('weekly')->get();
 */
class LeaderboardBuilder implements LeaderboardServiceContract
{
    protected ?string $awardableType = null;

    protected string $sortBy = 'xp';

    protected ?string $period = null;

    protected int $perPage = 15;

    protected ?int $page = null;

    protected bool $excludeOptedOut = true;

    /**
     * The GamedMetric (slug or model) to rank on, or null for the profile-wide total.
     */
    protected string|GamedMetric|null $metric = null;

    /**
     * Rank on a single GamedMetric (an "XP type") instead of the profile-wide total.
     *
     * Only profiles with a record for that metric appear. `by('xp')` sorts on that
     * metric's XP; `by('achievements')` and `by('prizes')` sort that metric's
     * participants on their profile-wide counts. Each result carries `metric_xp`
     * and `metric_level`. Combines with `period()`, where the period score is that
     * metric's XP alone. An unknown slug throws when the query is built.
     *
     * @param  string|GamedMetric|null  $metric  Slug, model, or null to clear
     * @return $this
     */
    public function metric(string|GamedMetric|null $metric): self
    {
        $this->metric = $metric;

        return $this;
    }

    /**
     * Filter leaderboard by awardable type (User, Team, Organization, etc.).
     *
     * @param  string  $type  The fully qualified class name of the awardable model
     * @return $this
     */
    public function for(string $type): self
    {
        $this->awardableType = $type;

        return $this;
    }

    /**
     * Sort leaderboard by metric.
     *
     * @param  string  $metric  The metric to sort by: 'xp', 'achievements', 'prizes', or 'points' (alias for xp)
     * @return $this
     */
    public function by(string $metric): self
    {
        // Map 'points' to 'xp' for backwards compatibility
        $this->sortBy = $metric === 'points' ? 'xp' : $metric;

        return $this;
    }

    /**
     * Filter leaderboard by time period.
     *
     * @param  string  $period  The time period: 'daily', 'weekly', 'monthly', 'all-time', or null for all-time
     * @return $this
     */
    public function period(?string $period = null): self
    {
        $this->period = $period;

        return $this;
    }

    /**
     * Set pagination per page.
     *
     * @param  int  $perPage  Number of items per page
     * @return $this
     */
    public function perPage(int $perPage): self
    {
        $this->perPage = $perPage;

        return $this;
    }

    /**
     * Set the current page number.
     *
     * @param  int  $page  Page number
     * @return $this
     */
    public function page(int $page): self
    {
        $this->page = $page;

        return $this;
    }

    /**
     * Include or exclude opted-out profiles.
     *
     * @param  bool  $exclude  Whether to exclude opted-out profiles (default: true)
     * @return $this
     */
    public function excludeOptedOut(bool $exclude = true): self
    {
        $this->excludeOptedOut = $exclude;

        return $this;
    }

    /**
     * Build and execute the query, returning a collection of profiles with ranks.
     *
     * @return Collection<int, Profile>
     */
    public function get(): Collection
    {
        $query = $this->buildQuery();

        return $query->get()->map(function (Profile $profile, int $index) {
            $profile->setAttribute('rank', $index + 1);

            return $profile;
        });
    }

    /**
     * Build and execute the query with pagination.
     */
    public function paginate(): LengthAwarePaginator
    {
        $query = $this->buildQuery();

        $paginator = $query->paginate($this->perPage, ['*'], 'page', $this->page);

        // Add rank to each item based on pagination offset
        $rankOffset = ($paginator->currentPage() - 1) * $paginator->perPage();

        $paginator->getCollection()->transform(function (Profile $profile, int $index) use ($rankOffset) {
            $profile->setAttribute('rank', $rankOffset + $index + 1);

            return $profile;
        });

        return $paginator;
    }

    /**
     * Get the first N results from the leaderboard.
     *
     * @param  int  $limit  Number of results to return
     * @return Collection<int, Profile>
     */
    public function take(int $limit): Collection
    {
        $query = $this->buildQuery();

        return $query->limit($limit)->get()->map(function (Profile $profile, int $index) {
            $profile->setAttribute('rank', $index + 1);

            return $profile;
        });
    }

    /**
     * Build the base query with all filters applied.
     *
     * @return Builder<Profile>
     */
    protected function buildQuery(): Builder
    {
        $profileTable = (new Profile)->getTable();
        $query = Profile::query();

        // Filter by awardable type (apply before join to avoid ambiguity)
        if ($this->awardableType !== null) {
            $query->where($profileTable.'.awardable_type', $this->awardableType);
        }

        // Exclude opted-out profiles by default
        if ($this->excludeOptedOut) {
            $query->optedIn();
        }

        $metric = $this->resolveMetric();

        if ($metric !== null) {
            // One metric: an inner join on (profile_id, gamed_metric_id) gives at
            // most one row per profile, so no GROUP BY is needed in any mode, and
            // the period filter is a plain WHERE on that row.
            $query = $this->applyMetricJoin($query, $metric);
        } elseif ($this->isPeriodBoard()) {
            $query = $this->applyTimeFilter($query);
        }

        // Apply sorting
        $query = $this->applySorting($query);

        // Ties break on profile id, so paging never repeats or skips a row.
        $query->orderBy($profileTable.'.id');

        return $query;
    }

    protected function isPeriodBoard(): bool
    {
        return $this->period !== null && $this->period !== 'all-time' && $this->getPeriodStartDate() !== null;
    }

    /**
     * Resolve the selected metric. Throws for an unknown slug: an empty board
     * would read as "nobody has XP yet" rather than "wrong name".
     */
    protected function resolveMetric(): ?GamedMetric
    {
        if ($this->metric === null || $this->metric instanceof GamedMetric) {
            return $this->metric;
        }

        return GamedMetric::findBySlug($this->metric)
            ?? throw new InvalidArgumentException("GamedMetric '{$this->metric}' not found.");
    }

    /**
     * Restrict to one metric's participants and select its XP and level.
     *
     * Served by the (gamed_metric_id, total_xp) and (gamed_metric_id, updated_at)
     * indexes on profile_metrics.
     *
     * @param  Builder<Profile>  $query
     * @return Builder<Profile>
     */
    protected function applyMetricJoin(Builder $query, GamedMetric $metric): Builder
    {
        $profileTable = (new Profile)->getTable();
        $metricsTable = (new ProfileMetric)->getTable();

        $query
            ->select($profileTable.'.*')
            ->addSelect([
                $metricsTable.'.total_xp as metric_xp',
                $metricsTable.'.current_level as metric_level',
            ])
            ->selectRaw('? as metric_slug', [$metric->slug])
            ->join($metricsTable, function ($join) use ($profileTable, $metricsTable, $metric) {
                $join->on($metricsTable.'.profile_id', '=', $profileTable.'.id')
                    ->where($metricsTable.'.gamed_metric_id', '=', $metric->getKey());
            });

        if ($this->isPeriodBoard()) {
            $query
                ->addSelect($metricsTable.'.total_xp as period_xp')
                ->where($metricsTable.'.updated_at', '>=', $this->getPeriodStartDate())
                ->where($metricsTable.'.total_xp', '>', 0);
        }

        return $query;
    }

    /**
     * Apply time-based filtering to the query.
     *
     * For time-based leaderboards, we need to join with profile_metrics and filter by updated_at.
     * This requires a more complex query that aggregates XP within the time period.
     *
     * @param  Builder<Profile>  $query
     * @return Builder<Profile>
     */
    protected function applyTimeFilter(Builder $query): Builder
    {
        $startDate = $this->getPeriodStartDate();

        if ($startDate === null) {
            return $query;
        }

        // For time-based filtering, we need to calculate scores from profile_metrics within the period
        // This requires joining with the profile_metrics table and aggregating
        $profileTable = (new Profile)->getTable();
        $metricsTable = (new ProfileMetric)->getTable();

        // Build a subquery to calculate period-specific scores
        return $query
            ->select($profileTable.'.*')
            ->selectRaw('COALESCE(SUM('.$metricsTable.'.total_xp), 0) as period_xp')
            ->leftJoin($metricsTable, function ($join) use ($profileTable, $metricsTable, $startDate) {
                $join->on($profileTable.'.id', '=', $metricsTable.'.profile_id')
                    ->where($metricsTable.'.updated_at', '>=', $startDate);
            })
            ->groupBy($profileTable.'.id')
            ->havingRaw('COALESCE(SUM('.$metricsTable.'.total_xp), 0) > 0');
    }

    /**
     * Get the start date for the current period.
     */
    protected function getPeriodStartDate(): ?Carbon
    {
        return match ($this->period) {
            'daily' => Carbon::today(),
            'weekly' => Carbon::now()->startOfWeek(),
            'monthly' => Carbon::now()->startOfMonth(),
            default => null,
        };
    }

    /**
     * Apply sorting to the query based on the selected metric.
     *
     * @param  Builder<Profile>  $query
     * @return Builder<Profile>
     */
    protected function applySorting(Builder $query): Builder
    {
        // Columns are qualified: with a metric joined, `total_xp` exists on both
        // tables and an unqualified sort is ambiguous.
        $profileTable = (new Profile)->getTable();

        if ($this->metric !== null) {
            $metricXp = (new ProfileMetric)->getTable().'.total_xp';

            return match ($this->sortBy) {
                'achievements' => $query->orderByDesc($profileTable.'.achievement_count')->orderByDesc($metricXp),
                'prizes' => $query->orderByDesc($profileTable.'.prize_count')->orderByDesc($metricXp),
                default => $query->orderByDesc($metricXp),
            };
        }

        // For time-based filtering, sort by period_xp if it exists
        if ($this->isPeriodBoard()) {
            return match ($this->sortBy) {
                'xp' => $query->orderByDesc('period_xp'),
                'achievements' => $query->orderByDesc($profileTable.'.achievement_count'),
                'prizes' => $query->orderByDesc($profileTable.'.prize_count'),
                default => $query->orderByDesc('period_xp'),
            };
        }

        // For all-time leaderboards, use profile aggregates
        return match ($this->sortBy) {
            'xp' => $query->orderByDesc($profileTable.'.total_xp'),
            'achievements' => $query->orderByDesc($profileTable.'.achievement_count'),
            'prizes' => $query->orderByDesc($profileTable.'.prize_count'),
            default => $query->orderByDesc($profileTable.'.total_xp'),
        };
    }
}
