<?php

declare(strict_types=1);

namespace LaravelFunLab\Listeners;

use Illuminate\Events\Dispatcher;
use LaravelFunLab\Contracts\LflEvent;
use LaravelFunLab\Events\AchievementUnlocked;
use LaravelFunLab\Events\BadgeAwarded;
use LaravelFunLab\Events\CatalogMutated;
use LaravelFunLab\Events\PointsAwarded;
use LaravelFunLab\Events\PrizeAwarded;
use LaravelFunLab\Events\XpAwarded;
use LaravelFunLab\Models\EventLog;

/**
 * EventLogSubscriber
 *
 * Listens to all LFL award events and logs them to the EventLog model
 * for analytics and auditing purposes. Can be disabled via config.
 */
class EventLogSubscriber
{
    /**
     * Handle a PointsAwarded event.
     */
    public function handlePointsAwarded(PointsAwarded $event): void
    {
        $this->logEvent($event);
    }

    /**
     * Handle an AchievementUnlocked event.
     */
    public function handleAchievementUnlocked(AchievementUnlocked $event): void
    {
        $this->logEvent($event);
    }

    /**
     * Handle a PrizeAwarded event.
     */
    public function handlePrizeAwarded(PrizeAwarded $event): void
    {
        $this->logEvent($event);
    }

    /**
     * Handle a BadgeAwarded event.
     */
    public function handleBadgeAwarded(BadgeAwarded $event): void
    {
        $this->logEvent($event);
    }

    /**
     * Handle an XpAwarded event (distinct from the deprecated PointsAwarded).
     */
    public function handleXpAwarded(XpAwarded $event): void
    {
        $this->logEvent($event);
    }

    /**
     * Handle a CatalogMutated event — audit trail for LFL::setup() writes.
     */
    public function handleCatalogMutated(CatalogMutated $event): void
    {
        $this->logEvent($event);
    }

    /**
     * Log an LFL event to the EventLog model.
     */
    protected function logEvent(LflEvent $event): void
    {
        // Check if event logging is enabled
        if (! config('lfl.events.log_to_database', true)) {
            return;
        }

        EventLog::fromEvent($event);
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            PointsAwarded::class => 'handlePointsAwarded',
            AchievementUnlocked::class => 'handleAchievementUnlocked',
            PrizeAwarded::class => 'handlePrizeAwarded',
            BadgeAwarded::class => 'handleBadgeAwarded',
            XpAwarded::class => 'handleXpAwarded',
            CatalogMutated::class => 'handleCatalogMutated',
        ];
    }
}
