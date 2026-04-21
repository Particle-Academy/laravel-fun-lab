<?php

declare(strict_types=1);

namespace LaravelFunLab\Services;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LaravelFunLab\Enums\AwardType;
use LaravelFunLab\Exceptions\AwardRejectedException;
use LaravelFunLab\Models\ProfileMetric;
use LaravelFunLab\Pipelines\AwardValidationPipeline;

/**
 * AwardXpBuilder
 *
 * Fluent builder for awarding XP to a GamedMetric.
 * Created via LFL::award('metric-slug').
 *
 * @example LFL::award('combat-xp')->to($user)->amount(50)->because('defeated boss')->save()
 */
class AwardXpBuilder
{
    protected ?Model $recipient = null;

    protected int $amount = 0;

    protected ?string $reason = null;

    protected ?string $source = null;

    protected array $meta = [];

    public function __construct(
        protected string $metricSlug,
        protected GamedMetricService $gamedMetricService
    ) {}

    /**
     * Set the recipient of the XP award.
     *
     * @param  Model  $recipient  The awardable entity (must use Awardable trait)
     * @return $this
     */
    public function to(Model $recipient): self
    {
        $this->recipient = $recipient;

        return $this;
    }

    /**
     * Set the amount of XP to award.
     *
     * @param  int  $amount  The XP amount
     * @return $this
     */
    public function amount(int $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * Set the reason for the XP award.
     *
     * @param  string  $reason  Why XP is being awarded
     * @return $this
     */
    public function because(string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    /**
     * Alias for because().
     *
     * @param  string  $reason  Why XP is being awarded
     * @return $this
     */
    public function for(string $reason): self
    {
        return $this->because($reason);
    }

    /**
     * Set the source of the XP award.
     *
     * @param  string  $source  Where the XP came from
     * @return $this
     */
    public function from(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    /**
     * Set additional metadata for the award.
     *
     * @param  array<string, mixed>  $meta  Additional data
     * @return $this
     */
    public function withMeta(array $meta): self
    {
        $this->meta = $meta;

        return $this;
    }

    /**
     * Execute the XP award and return the ProfileMetric.
     *
     * @return ProfileMetric The updated ProfileMetric record
     *
     * @throws InvalidArgumentException If recipient is not set or amount is invalid
     */
    public function save(): ProfileMetric
    {
        if ($this->recipient === null) {
            throw new InvalidArgumentException('Recipient is required. Use ->to($user) to set the recipient.');
        }

        if ($this->amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than 0. Use ->amount(50) to set the XP amount.');
        }

        $cap = (int) config('lfl.defaults.max_points_per_action', 0);
        if ($cap > 0 && $this->amount > $cap) {
            throw new AwardRejectedException(
                "Award amount {$this->amount} exceeds the configured per-action cap of {$cap}.",
                'cap_exceeded',
            );
        }

        $validation = AwardValidationPipeline::validate(
            awardable: $this->recipient,
            type: AwardType::Points,
            amount: $this->amount,
            reason: $this->reason,
            source: $this->source,
            meta: $this->meta,
        );

        if (isset($validation['valid']) && $validation['valid'] === false) {
            throw new AwardRejectedException(
                $validation['message'] ?? 'Award rejected by validation pipeline.',
                'pipeline_rejected',
            );
        }

        return $this->gamedMetricService->awardXp(
            $this->recipient,
            $this->metricSlug,
            $this->amount,
            $this->reason,
            $this->source,
            $this->meta
        );
    }

    /**
     * Alias for save().
     */
    public function grant(): ProfileMetric
    {
        return $this->save();
    }
}
