<?php

declare(strict_types=1);

namespace LaravelFunLab\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LaravelFunLab\Contracts\LflEvent;
use LaravelFunLab\Enums\AwardType;
use LaravelFunLab\Models\PrizeGrant;

/**
 * PrizeAwarded Event
 *
 * Dispatched whenever a prize is awarded to an entity.
 * Contains full context for prize fulfillment and analytics.
 *
 * Broadcasts on private channel `lfl.profile.{awardable_type}.{awardable_id}`
 * when config('lfl.events.broadcast') is true. Transport driver is the
 * consumer's responsibility.
 */
class PrizeAwarded implements LflEvent, ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  Model  $recipient  The entity that received the prize
     * @param  PrizeGrant|Model  $award  The PrizeGrant record that was created
     * @param  string|null  $reason  Why the prize was awarded
     * @param  string|null  $source  Where the prize came from
     * @param  array<string, mixed>  $meta  Additional prize metadata
     */
    public function __construct(
        public Model $recipient,
        public PrizeGrant|Model $award,
        public ?string $reason = null,
        public ?string $source = null,
        public array $meta = [],
    ) {}

    /**
     * Get the award type for this event.
     */
    public function getAwardType(): AwardType
    {
        return AwardType::Prize;
    }

    /**
     * Get the recipient/awardable entity.
     */
    public function getRecipient(): Model
    {
        return $this->recipient;
    }

    /**
     * Get the reason for the award.
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * Get the source of the award.
     */
    public function getSource(): ?string
    {
        return $this->source;
    }

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
        return 'prize.awarded';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $grant = $this->award instanceof PrizeGrant ? $this->award : null;

        return [
            'grant_type' => 'prize',
            'id' => $this->award->id ?? null,
            'profile_id' => $grant?->profile_id,
            'prize_id' => $grant?->prize_id,
            'prize_slug' => $grant?->prize?->slug,
            'prize_name' => $grant?->prize?->name,
            'status' => $grant?->status?->value,
            'reason' => $this->reason,
            'source' => $this->source,
            'granted_at' => $grant?->granted_at?->toIso8601String(),
        ];
    }

    /**
     * Convert the event to an array for logging/analytics.
     *
     * @return array<string, mixed>
     */
    public function toLogArray(): array
    {
        return [
            'event_type' => 'prize_awarded',
            'award_type' => AwardType::Prize->value,
            'recipient_type' => get_class($this->recipient),
            'recipient_id' => $this->recipient->getKey(),
            'award_id' => $this->award->id,
            'reason' => $this->reason,
            'source' => $this->source,
            'meta' => $this->meta,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
