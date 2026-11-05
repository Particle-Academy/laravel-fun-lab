<?php

declare(strict_types=1);

namespace LaravelFunLab\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LaravelFunLab\Models\Achievement;
use LaravelFunLab\Models\GamedMetric;
use LaravelFunLab\Models\MetricLevel;
use LaravelFunLab\Models\Profile;
use LaravelFunLab\Models\ProfileMetric;

/**
 * MetricLevelReached Event
 *
 * Dispatched once for every level of a GamedMetric a profile crosses. An award
 * that crosses several levels dispatches one event per level, in ascending
 * order. A level already recorded never dispatches again, however often
 * progression is recomputed: the level is claimed with a compare-and-set on
 * `current_level`, so two requests racing over the same threshold produce one
 * event between them.
 *
 * `achievements` lists the achievements this level granted on this crossing,
 * not ones the profile already held.
 *
 * Broadcasts as `metric.level-reached` on the same private channel as
 * XpAwarded, `lfl.profile.{awardable_type}.{awardable_id}`, when
 * config('lfl.events.broadcast') is true. It is not an award, so it is not
 * written to the event log; the achievements it granted are, as usual.
 */
class MetricLevelReached implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  Profile  $profile  The profile that reached the level
     * @param  ProfileMetric  $profileMetric  The profile's record for the metric
     * @param  GamedMetric  $gamedMetric  The metric
     * @param  MetricLevel  $level  The level reached
     * @param  Model  $recipient  The awardable entity
     * @param  list<Achievement>  $achievements  Achievements granted for reaching this level
     * @param  int  $totalXp  The metric's XP when the level was reached
     */
    public function __construct(
        public Profile $profile,
        public ProfileMetric $profileMetric,
        public GamedMetric $gamedMetric,
        public MetricLevel $level,
        public Model $recipient,
        public array $achievements = [],
        public int $totalXp = 0,
    ) {}

    public function getRecipient(): Model
    {
        return $this->recipient;
    }

    public function getMetricSlug(): string
    {
        return $this->gamedMetric->slug;
    }

    public function getLevel(): int
    {
        return (int) $this->level->level;
    }

    /**
     * Opt-in broadcasting, as for XpAwarded.
     */
    public function broadcastWhen(): bool
    {
        return (bool) config('lfl.events.broadcast', false);
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $type = str_replace('\\', '.', get_class($this->recipient));
        $id = $this->recipient->getKey();

        return [new PrivateChannel("lfl.profile.{$type}.{$id}")];
    }

    public function broadcastAs(): string
    {
        return 'metric.level-reached';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'profile_id' => $this->profile->id,
            'gamed_metric_id' => $this->gamedMetric->id,
            'gamed_metric_slug' => $this->gamedMetric->slug,
            'gamed_metric_name' => $this->gamedMetric->name,
            'level' => (int) $this->level->level,
            'level_name' => $this->level->name,
            'xp_threshold' => (int) $this->level->xp_threshold,
            'total_xp' => $this->totalXp,
            'achievements' => array_map(fn (Achievement $a) => [
                'id' => $a->id,
                'slug' => $a->slug,
                'name' => $a->name,
                'icon' => $a->icon,
            ], $this->achievements),
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
