<?php

declare(strict_types=1);

namespace LaravelFunLab\Services;

use Illuminate\Database\Eloquent\Model;
use LaravelFunLab\Models\GamedMetric;
use LaravelFunLab\Models\MetricLevelGroup;
use LaravelFunLab\Models\Profile;
use LaravelFunLab\Models\ProfileMetric;

/**
 * GamedMetricService
 *
 * Handles awarding XP to GamedMetrics and tracking profile progress.
 */
class GamedMetricService
{
    public function __construct(
        protected MetricLevelService $levelService,
        protected MetricLevelGroupService $metricLevelGroupService
    ) {}

    /**
     * Award XP to a specific GamedMetric for an awardable entity.
     *
     * @param  Model  $awardable  The entity receiving XP (must use Awardable trait)
     * @param  string|GamedMetric  $gamedMetric  GamedMetric slug or model instance
     * @param  int  $amount  Amount of XP to award
     * @param  string|null  $reason  Why XP is being awarded
     * @param  string|null  $source  Where the XP came from
     * @param  array<string, mixed>  $meta  Additional metadata
     * @return ProfileMetric The updated ProfileMetric record
     */
    public function awardXp(
        Model $awardable,
        string|GamedMetric $gamedMetric,
        int $amount,
        ?string $reason = null,
        ?string $source = null,
        array $meta = []
    ): ProfileMetric {
        // Resolve GamedMetric
        $metric = $gamedMetric instanceof GamedMetric
            ? $gamedMetric
            : GamedMetric::findBySlug($gamedMetric);

        if ($metric === null) {
            throw new \InvalidArgumentException("GamedMetric '{$gamedMetric}' not found.");
        }

        if (! $metric->active) {
            throw new \InvalidArgumentException("GamedMetric '{$metric->slug}' is not active.");
        }

        // Reject XP awards for opted-out awardables. Matches the opt-out check
        // already enforced in grantAchievementInternal and grantPrizeInternal.
        // Looks at the Profile (not the trait method) so an awardable that
        // didn't define isOptedOut() still gets the check.
        if (method_exists($awardable, 'getProfile')) {
            $existingProfile = \LaravelFunLab\Models\Profile::where('awardable_type', get_class($awardable))
                ->where('awardable_id', $awardable->getKey())
                ->first();
            if ($existingProfile !== null && $existingProfile->isOptedOut()) {
                throw new \LaravelFunLab\Exceptions\AwardRejectedException(
                    'Recipient has opted out of gamification.',
                    'opted_out',
                );
            }
        }

        // Get or create Profile for the awardable
        $profile = $this->getOrCreateProfile($awardable);

        // Get or create ProfileMetric record
        $profileMetric = ProfileMetric::firstOrCreate(
            [
                'profile_id' => $profile->id,
                'gamed_metric_id' => $metric->id,
            ],
            [
                'total_xp' => 0,
                'current_level' => 1,
            ]
        );

        // Add XP
        $profileMetric->addXp($amount);
        $profileMetric->refresh();

        // Update profile's total XP
        $profile->incrementXp($amount);
        $profile->touchActivity();

        // Check for level progression
        $this->levelService->checkProgression($profileMetric);

        // Check group progression for all groups containing this metric
        $this->checkGroupProgression($profile, $metric);

        // Dispatch XP awarded event
        if (config('lfl.events.dispatch', true)) {
            event(new \LaravelFunLab\Events\XpAwarded($profileMetric, $metric, $awardable, $amount, $reason, $source, $meta));
        }

        return $profileMetric;
    }

    /**
     * Get or create a Profile for an awardable entity.
     */
    protected function getOrCreateProfile(Model $awardable): Profile
    {
        return Profile::firstOrCreate(
            [
                'awardable_type' => get_class($awardable),
                'awardable_id' => $awardable->getKey(),
            ],
            [
                'is_opted_in' => true,
                'total_xp' => 0,
                'achievement_count' => 0,
                'prize_count' => 0,
            ]
        );
    }

    /**
     * Get the ProfileMetric record for an awardable entity and GamedMetric.
     *
     * @param  Model  $awardable  The entity
     * @param  string|GamedMetric  $gamedMetric  GamedMetric slug or model instance
     * @return ProfileMetric|null The ProfileMetric record or null if not found
     */
    public function getProfileMetric(
        Model $awardable,
        string|GamedMetric $gamedMetric
    ): ?ProfileMetric {
        $metric = $gamedMetric instanceof GamedMetric
            ? $gamedMetric
            : GamedMetric::findBySlug($gamedMetric);

        if ($metric === null) {
            return null;
        }

        $profile = Profile::where('awardable_type', get_class($awardable))
            ->where('awardable_id', $awardable->getKey())
            ->first();

        if (! $profile) {
            return null;
        }

        return ProfileMetric::where('profile_id', $profile->id)
            ->where('gamed_metric_id', $metric->id)
            ->first();
    }

    /**
     * Get total XP for a GamedMetric for an awardable entity.
     *
     * @param  Model  $awardable  The entity
     * @param  string|GamedMetric  $gamedMetric  GamedMetric slug or model instance
     * @return int Total XP (0 if no record exists)
     */
    public function getTotalXp(
        Model $awardable,
        string|GamedMetric $gamedMetric
    ): int {
        $profileMetric = $this->getProfileMetric($awardable, $gamedMetric);

        return $profileMetric?->total_xp ?? 0;
    }

    /**
     * Get current level for a GamedMetric for an awardable entity.
     *
     * @param  Model  $awardable  The entity
     * @param  string|GamedMetric  $gamedMetric  GamedMetric slug or model instance
     * @return int Current level (1 if no record exists)
     */
    public function getCurrentLevel(
        Model $awardable,
        string|GamedMetric $gamedMetric
    ): int {
        $profileMetric = $this->getProfileMetric($awardable, $gamedMetric);

        return $profileMetric?->current_level ?? 1;
    }

    /**
     * Get the Profile for an awardable entity.
     *
     * @param  Model  $awardable  The entity
     * @return Profile|null The Profile or null if not found
     */
    public function getProfile(Model $awardable): ?Profile
    {
        return Profile::where('awardable_type', get_class($awardable))
            ->where('awardable_id', $awardable->getKey())
            ->first();
    }

    /**
     * Check group progression for all groups containing the given metric.
     *
     * @param  Profile  $profile  The profile
     * @param  GamedMetric  $metric  The metric that was updated
     */
    protected function checkGroupProgression(Profile $profile, GamedMetric $metric): void
    {
        // Find all groups that contain this metric
        $groups = MetricLevelGroup::whereHas('metrics', function ($query) use ($metric) {
            $query->where('gamed_metric_id', $metric->id);
        })->get();

        // Check progression for each group
        foreach ($groups as $group) {
            $profileMetricGroup = $this->metricLevelGroupService->getOrCreateProfileMetricGroup($profile, $group);
            $this->metricLevelGroupService->checkProgression($profileMetricGroup);
        }
    }
}
