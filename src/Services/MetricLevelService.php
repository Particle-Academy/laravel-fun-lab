<?php

declare(strict_types=1);

namespace LaravelFunLab\Services;

use Illuminate\Database\Eloquent\Model;
use LaravelFunLab\Events\MetricLevelReached;
use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Models\Achievement;
use LaravelFunLab\Models\GamedMetric;
use LaravelFunLab\Models\MetricLevel;
use LaravelFunLab\Models\Profile;
use LaravelFunLab\Models\ProfileMetric;

/**
 * MetricLevelService
 *
 * Handles level checking and progression for GamedMetrics.
 */
class MetricLevelService
{
    /**
     * Check and update level progression for a ProfileMetric.
     *
     * @param  ProfileMetric  $profileMetric  The ProfileMetric to check
     * @return array<string, mixed> Array with 'level_reached' (bool), 'new_level' (int|null), 'levels_unlocked' (array)
     */
    public function checkProgression(ProfileMetric $profileMetric): array
    {
        $metric = $profileMetric->gamedMetric;

        // Get all levels for this metric, ordered by level
        $levels = MetricLevel::forMetric($metric->id)
            ->ordered()
            ->get();

        $claim = $this->claimLevels($profileMetric, $levels);

        if ($claim === null) {
            return [
                'level_reached' => false,
                'new_level' => null,
                'levels_unlocked' => [],
            ];
        }

        [$newLevel, $levelsUnlocked, $totalXp] = $claim;

        $profile = $profileMetric->profile;
        $awardable = $profile->awardable;
        $dispatch = (bool) config('lfl.events.dispatch', true);

        // Auto-award achievements attached to unlocked levels, then announce
        // each level, in ascending order, with what it granted.
        foreach ($levelsUnlocked as $level) {
            $granted = [];

            foreach ($level->achievements()->active()->get() as $achievement) {
                // Only award if the achievement is for this awardable type or universal
                if ($achievement->awardable_type !== null && $achievement->awardable_type !== get_class($awardable)) {
                    continue;
                }

                // Check if already granted (skip if already has it)
                if ($awardable->hasAchievement($achievement)) {
                    continue;
                }

                $result = LFL::grant($achievement->slug)
                    ->to($awardable)
                    ->because("Reached level {$level->level}: {$level->name}")
                    ->from('metric-level-progression')
                    ->withMeta([
                        'metric_level_id' => $level->id,
                        'gamed_metric_id' => $metric->id,
                        'level' => $level->level,
                    ])
                    ->save();

                if ($result->succeeded()) {
                    $granted[] = $achievement;
                }
            }

            if ($dispatch) {
                event(new MetricLevelReached($profile, $profileMetric, $metric, $level, $awardable, $granted, $totalXp));
            }
        }

        return [
            'level_reached' => true,
            'new_level' => $newLevel,
            'levels_unlocked' => $levelsUnlocked,
        ];
    }

    /**
     * Claim the levels newly reached, exactly once.
     *
     * A compare-and-set on `current_level`: the row moves from the level this
     * copy read to the new one only if nobody else moved it first. If someone
     * did, the copy is refreshed and the levels recomputed from the stored
     * level, so a level is announced by exactly one caller however many race
     * over it, and a stale copy never re-announces a level already recorded.
     *
     * @param  \Illuminate\Support\Collection<int, MetricLevel>  $levels  Ordered ascending
     * @return array{0: int, 1: list<MetricLevel>, 2: int}|null [new level, levels unlocked, total XP], or null
     */
    protected function claimLevels(ProfileMetric $profileMetric, $levels): ?array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $fromLevel = (int) $profileMetric->current_level;
            $totalXp = (int) $profileMetric->total_xp;

            $unlocked = $levels
                ->filter(fn (MetricLevel $level) => $level->isReached($totalXp) && $level->level > $fromLevel)
                ->values()
                ->all();

            if ($unlocked === []) {
                return null;
            }

            $newLevel = (int) end($unlocked)->level;

            $claimed = ProfileMetric::query()
                ->whereKey($profileMetric->getKey())
                ->where('current_level', $fromLevel)
                ->update(['current_level' => $newLevel]);

            if ($claimed === 1) {
                $profileMetric->forceFill(['current_level' => $newLevel])->syncOriginalAttribute('current_level');

                return [$newLevel, $unlocked, $totalXp];
            }

            // Someone else moved the level: read it back and try again.
            $profileMetric->refresh();
        }

        return null;
    }

    /**
     * Check if a profile has reached a specific level in a GamedMetric.
     *
     * @param  Profile  $profile  The profile to check
     * @param  string  $metricSlug  The GamedMetric slug
     * @param  int  $level  The level to check
     * @return bool Whether the profile has reached the level
     */
    public function hasReachedLevel(Profile $profile, string $metricSlug, int $level): bool
    {
        $metric = GamedMetric::findBySlug($metricSlug);
        if ($metric === null) {
            return false;
        }

        $profileMetric = ProfileMetric::where('profile_id', $profile->id)
            ->where('gamed_metric_id', $metric->id)
            ->first();

        if ($profileMetric === null) {
            return false;
        }

        // Check if current level is >= requested level
        if ($profileMetric->current_level >= $level) {
            return true;
        }

        // Also check if XP meets the level threshold (in case level wasn't updated)
        $metricLevel = MetricLevel::forMetric($metric->id)
            ->where('level', $level)
            ->first();

        if ($metricLevel === null) {
            // Level not defined, just check current level
            return $profileMetric->current_level >= $level;
        }

        return $profileMetric->total_xp >= $metricLevel->xp_threshold;
    }

    /**
     * Get the current level for a GamedMetric based on XP.
     *
     * @param  Model  $awardable  The awardable entity
     * @param  string|GamedMetric  $gamedMetric  GamedMetric slug or model instance
     * @return int The current level (1 if no levels defined or XP insufficient)
     */
    public function getCurrentLevel(
        Model $awardable,
        string|GamedMetric $gamedMetric
    ): int {
        $metric = $gamedMetric instanceof GamedMetric
            ? $gamedMetric
            : GamedMetric::findBySlug($gamedMetric);

        if ($metric === null) {
            return 1;
        }

        $profile = Profile::where('awardable_type', get_class($awardable))
            ->where('awardable_id', $awardable->getKey())
            ->first();

        if ($profile === null) {
            return 1;
        }

        $profileMetric = ProfileMetric::where('profile_id', $profile->id)
            ->where('gamed_metric_id', $metric->id)
            ->first();

        if ($profileMetric === null) {
            return 1;
        }

        return $profileMetric->current_level;
    }

    /**
     * Get the next level threshold for a GamedMetric.
     *
     * @param  Model  $awardable  The awardable entity
     * @param  string|GamedMetric  $gamedMetric  GamedMetric slug or model instance
     * @return int|null The next level's XP threshold, or null if max level reached
     */
    public function getNextLevelThreshold(
        Model $awardable,
        string|GamedMetric $gamedMetric
    ): ?int {
        $metric = $gamedMetric instanceof GamedMetric
            ? $gamedMetric
            : GamedMetric::findBySlug($gamedMetric);

        if ($metric === null) {
            return null;
        }

        $profile = Profile::where('awardable_type', get_class($awardable))
            ->where('awardable_id', $awardable->getKey())
            ->first();

        $profileMetric = $profile ? ProfileMetric::where('profile_id', $profile->id)
            ->where('gamed_metric_id', $metric->id)
            ->first() : null;

        $currentLevel = $profileMetric?->current_level ?? 1;

        $nextLevel = MetricLevel::forMetric($metric->id)
            ->where('level', '>', $currentLevel)
            ->ordered()
            ->first();

        return $nextLevel?->xp_threshold;
    }

    /**
     * Get progress percentage towards next level.
     *
     * @param  Model  $awardable  The awardable entity
     * @param  string|GamedMetric  $gamedMetric  GamedMetric slug or model instance
     * @return float Progress percentage (0-100)
     */
    public function getProgressPercentage(
        Model $awardable,
        string|GamedMetric $gamedMetric
    ): float {
        $metric = $gamedMetric instanceof GamedMetric
            ? $gamedMetric
            : GamedMetric::findBySlug($gamedMetric);

        if ($metric === null) {
            return 0.0;
        }

        $profile = Profile::where('awardable_type', get_class($awardable))
            ->where('awardable_id', $awardable->getKey())
            ->first();

        if ($profile === null) {
            return 0.0;
        }

        $profileMetric = ProfileMetric::where('profile_id', $profile->id)
            ->where('gamed_metric_id', $metric->id)
            ->first();

        if ($profileMetric === null) {
            return 0.0;
        }

        $currentXp = $profileMetric->total_xp;
        $currentLevel = $profileMetric->current_level;

        // Get current level threshold
        $currentLevelThreshold = MetricLevel::forMetric($metric->id)
            ->where('level', $currentLevel)
            ->first()?->xp_threshold ?? 0;

        // Get next level threshold
        $nextLevelThreshold = $this->getNextLevelThreshold($awardable, $gamedMetric);

        if ($nextLevelThreshold === null) {
            // Max level reached
            return 100.0;
        }

        $progressXp = $currentXp - $currentLevelThreshold;
        $requiredXp = $nextLevelThreshold - $currentLevelThreshold;

        if ($requiredXp <= 0) {
            return 100.0;
        }

        return min(100.0, ($progressXp / $requiredXp) * 100);
    }
}
