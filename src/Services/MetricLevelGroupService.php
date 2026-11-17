<?php

declare(strict_types=1);

namespace LaravelFunLab\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Models\MetricLevelGroup;
use LaravelFunLab\Models\MetricLevelGroupLevel;
use LaravelFunLab\Models\Profile;
use LaravelFunLab\Models\ProfileMetric;
use LaravelFunLab\Models\ProfileMetricGroup;

/**
 * MetricLevelGroupService
 *
 * Handles aggregation of multiple GamedMetrics and group level calculations.
 */
class MetricLevelGroupService
{
    /**
     * Check if a profile has reached a specific level in a MetricLevelGroup.
     *
     * @param  Profile  $profile  The profile to check
     * @param  string  $groupSlug  The MetricLevelGroup slug
     * @param  int  $level  The level to check
     * @return bool Whether the profile has reached the level
     */
    public function hasReachedLevel(Profile $profile, string $groupSlug, int $level): bool
    {
        $groupModel = MetricLevelGroup::findBySlug($groupSlug);
        if ($groupModel === null) {
            return false;
        }

        // Calculate total XP from all metrics in the group
        $totalXp = 0;
        foreach ($groupModel->metrics as $groupMetric) {
            $profileMetric = ProfileMetric::where('profile_id', $profile->id)
                ->where('gamed_metric_id', $groupMetric->gamed_metric_id)
                ->first();

            if ($profileMetric !== null) {
                $weightedXp = (int) ($profileMetric->total_xp * $groupMetric->weight);
                $totalXp += $weightedXp;
            }
        }

        // Check if XP meets the level threshold
        $groupLevel = MetricLevelGroupLevel::forGroup($groupModel->id)
            ->where('level', $level)
            ->first();

        if ($groupLevel === null) {
            // Level not defined, return false
            return false;
        }

        return $totalXp >= $groupLevel->xp_threshold;
    }

    /**
     * Calculate total combined XP for a MetricLevelGroup for an awardable entity.
     *
     * @param  Model  $awardable  The awardable entity
     * @param  string|MetricLevelGroup  $group  Group slug or model instance
     * @return int Total combined XP (sum of weighted XP from all metrics in group)
     */
    public function getTotalXp(
        Model $awardable,
        string|MetricLevelGroup $group
    ): int {
        $groupModel = $group instanceof MetricLevelGroup
            ? $group
            : MetricLevelGroup::findBySlug($group);

        if ($groupModel === null) {
            return 0;
        }

        // Get the profile for this awardable
        $profile = Profile::where('awardable_type', get_class($awardable))
            ->where('awardable_id', $awardable->getKey())
            ->first();

        if ($profile === null) {
            return 0;
        }

        $totalXp = 0;

        // Sum XP from all metrics in the group with their weights
        foreach ($groupModel->metrics as $groupMetric) {
            $profileMetric = ProfileMetric::where('profile_id', $profile->id)
                ->where('gamed_metric_id', $groupMetric->gamed_metric_id)
                ->first();

            if ($profileMetric !== null) {
                // Apply weight to XP
                $weightedXp = (int) ($profileMetric->total_xp * $groupMetric->weight);
                $totalXp += $weightedXp;
            }
        }

        return $totalXp;
    }

    /**
     * Get current level for a MetricLevelGroup based on stored ProfileMetricGroup.
     *
     * @param  Model  $awardable  The awardable entity
     * @param  string|MetricLevelGroup  $group  Group slug or model instance
     * @return int Current level (1 if no levels defined or XP insufficient)
     */
    public function getCurrentLevel(
        Model $awardable,
        string|MetricLevelGroup $group
    ): int {
        $groupModel = $group instanceof MetricLevelGroup
            ? $group
            : MetricLevelGroup::findBySlug($group);

        if ($groupModel === null) {
            return 1;
        }

        $profile = Profile::where('awardable_type', get_class($awardable))
            ->where('awardable_id', $awardable->getKey())
            ->first();

        if ($profile === null) {
            return 1;
        }

        $profileMetricGroup = ProfileMetricGroup::where('profile_id', $profile->id)
            ->where('metric_level_group_id', $groupModel->id)
            ->first();

        if ($profileMetricGroup === null) {
            // Fallback to calculation if no stored record exists
            $totalXp = $this->getTotalXp($awardable, $groupModel);
            $levels = $groupModel->levels()->ordered()->get();
            $currentLevel = 1;

            foreach ($levels as $level) {
                if ($level->isReached($totalXp)) {
                    $currentLevel = $level->level;
                } else {
                    break;
                }
            }

            return $currentLevel;
        }

        return $profileMetricGroup->current_level;
    }

    /**
     * Get the next level threshold for a MetricLevelGroup.
     *
     * @param  Model  $awardable  The awardable entity
     * @param  string|MetricLevelGroup  $group  Group slug or model instance
     * @return int|null The next level's XP threshold, or null if max level reached
     */
    public function getNextLevelThreshold(
        Model $awardable,
        string|MetricLevelGroup $group
    ): ?int {
        $groupModel = $group instanceof MetricLevelGroup
            ? $group
            : MetricLevelGroup::findBySlug($group);

        if ($groupModel === null) {
            return null;
        }

        $currentLevel = $this->getCurrentLevel($awardable, $groupModel);

        $nextLevel = MetricLevelGroupLevel::forGroup($groupModel->id)
            ->where('level', '>', $currentLevel)
            ->ordered()
            ->first();

        return $nextLevel?->xp_threshold;
    }

    /**
     * Get progress percentage towards next level for a group.
     *
     * @param  Model  $awardable  The awardable entity
     * @param  string|MetricLevelGroup  $group  Group slug or model instance
     * @return float Progress percentage (0-100)
     */
    public function getProgressPercentage(
        Model $awardable,
        string|MetricLevelGroup $group
    ): float {
        $groupModel = $group instanceof MetricLevelGroup
            ? $group
            : MetricLevelGroup::findBySlug($group);

        if ($groupModel === null) {
            return 0.0;
        }

        $totalXp = $this->getTotalXp($awardable, $groupModel);
        $currentLevel = $this->getCurrentLevel($awardable, $groupModel);

        // Get current level threshold
        $currentLevelThreshold = MetricLevelGroupLevel::forGroup($groupModel->id)
            ->where('level', $currentLevel)
            ->first()?->xp_threshold ?? 0;

        // Get next level threshold
        $nextLevelThreshold = $this->getNextLevelThreshold($awardable, $groupModel);

        if ($nextLevelThreshold === null) {
            // Max level reached
            return 100.0;
        }

        $progressXp = $totalXp - $currentLevelThreshold;
        $requiredXp = $nextLevelThreshold - $currentLevelThreshold;

        if ($requiredXp <= 0) {
            return 100.0;
        }

        return min(100.0, ($progressXp / $requiredXp) * 100);
    }

    /**
     * Check and return level progression for a group.
     *
     * @param  Model  $awardable  The awardable entity
     * @param  string|MetricLevelGroup  $group  Group slug or model instance
     * @return array<string, mixed> Array with level information
     */
    public function getLevelInfo(
        Model $awardable,
        string|MetricLevelGroup $group
    ): array {
        $groupModel = $group instanceof MetricLevelGroup
            ? $group
            : MetricLevelGroup::findBySlug($group);

        if ($groupModel === null) {
            return [
                'current_level' => 1,
                'total_xp' => 0,
                'next_level_threshold' => null,
                'progress_percentage' => 0.0,
            ];
        }

        $totalXp = $this->getTotalXp($awardable, $groupModel);
        $currentLevel = $this->getCurrentLevel($awardable, $groupModel);
        $nextThreshold = $this->getNextLevelThreshold($awardable, $groupModel);
        $progress = $this->getProgressPercentage($awardable, $groupModel);

        return [
            'current_level' => $currentLevel,
            'total_xp' => $totalXp,
            'next_level_threshold' => $nextThreshold,
            'progress_percentage' => $progress,
        ];
    }

    /**
     * Claim the group levels newly reached, exactly once.
     *
     * The same compare-and-set as MetricLevelService::claimLevels(): the row
     * moves from the level this copy read to the new one only if nobody moved
     * it first. If someone did, the copy is refreshed and the levels recomputed
     * from the stored level, so a group level is claimed (and its achievements
     * granted) by exactly one caller, and a stale copy never re-claims a level
     * already recorded. Before 0.8.0 this read, decided and wrote back.
     *
     * @param  Collection<int, MetricLevelGroupLevel>  $levels  Ordered ascending
     * @return array{0: int, 1: list<MetricLevelGroupLevel>}|null [new level, levels unlocked], or null
     */
    protected function claimLevels(ProfileMetricGroup $profileMetricGroup, $levels, int $totalXp): ?array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $fromLevel = (int) $profileMetricGroup->current_level;

            $unlocked = $levels
                ->filter(fn (MetricLevelGroupLevel $level) => $level->isReached($totalXp) && $level->level > $fromLevel)
                ->values()
                ->all();

            if ($unlocked === []) {
                return null;
            }

            $newLevel = (int) end($unlocked)->level;

            $claimed = ProfileMetricGroup::query()
                ->whereKey($profileMetricGroup->getKey())
                ->where('current_level', $fromLevel)
                ->update(['current_level' => $newLevel]);

            if ($claimed === 1) {
                $profileMetricGroup->forceFill(['current_level' => $newLevel])->syncOriginalAttribute('current_level');

                return [$newLevel, $unlocked];
            }

            // Someone else moved the level: read it back and try again.
            $profileMetricGroup->refresh();
        }

        return null;
    }

    /**
     * Check and update level progression for a ProfileMetricGroup.
     *
     * @param  ProfileMetricGroup  $profileMetricGroup  The ProfileMetricGroup to check
     * @return array<string, mixed> Array with 'level_reached' (bool), 'new_level' (int|null), 'levels_unlocked' (array)
     */
    public function checkProgression(ProfileMetricGroup $profileMetricGroup): array
    {
        $group = $profileMetricGroup->metricLevelGroup;
        $profile = $profileMetricGroup->profile;
        $totalXp = $this->getTotalXp($profile->awardable, $group);

        // Get all levels for this group, ordered by level
        $levels = MetricLevelGroupLevel::forGroup($group->id)
            ->ordered()
            ->get();

        $claim = $this->claimLevels($profileMetricGroup, $levels, $totalXp);
        [$newLevel, $levelsUnlocked] = $claim ?? [null, []];

        if ($newLevel !== null) {
            // Auto-award achievements attached to unlocked levels
            foreach ($levelsUnlocked as $level) {
                $achievements = $level->achievements()->active()->get();
                $awardable = $profile->awardable;

                foreach ($achievements as $achievement) {
                    // Only award if the achievement is for this awardable type or universal
                    if ($achievement->awardable_type === null || $achievement->awardable_type === get_class($awardable)) {
                        // Check if already granted (skip if already has it)
                        if (! $awardable->hasAchievement($achievement)) {
                            LFL::grant($achievement->slug)
                                ->to($awardable)
                                ->because("Reached level {$level->level}: {$level->name}")
                                ->from('metric-level-group-progression')
                                ->withMeta([
                                    'metric_level_group_level_id' => $level->id,
                                    'metric_level_group_id' => $group->id,
                                    'level' => $level->level,
                                ])
                                ->save();
                        }
                    }
                }
            }
        }

        return [
            'level_reached' => $newLevel !== null,
            'new_level' => $newLevel,
            'levels_unlocked' => $levelsUnlocked,
        ];
    }

    /**
     * Get or create a ProfileMetricGroup for a profile and group.
     *
     * @param  Profile  $profile  The profile
     * @param  MetricLevelGroup  $group  The metric level group
     * @return ProfileMetricGroup The ProfileMetricGroup instance
     */
    public function getOrCreateProfileMetricGroup(Profile $profile, MetricLevelGroup $group): ProfileMetricGroup
    {
        return ProfileMetricGroup::firstOrCreate(
            [
                'profile_id' => $profile->id,
                'metric_level_group_id' => $group->id,
            ],
            [
                'current_level' => 1,
            ]
        );
    }
}
