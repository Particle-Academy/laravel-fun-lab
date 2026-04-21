<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AwardXpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $cap = (int) config('lfl.defaults.max_points_per_action', 0);
        $amountRules = ['required', 'integer', 'min:1'];
        if ($cap > 0) {
            $amountRules[] = "max:{$cap}";
        }

        return [
            'metric_slug' => ['required', 'string', 'max:255'],
            'awardable_type' => ['required', 'string', 'max:255'],
            'awardable_id' => ['required', 'integer', 'min:1'],
            'amount' => $amountRules,
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'source' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'metric_slug.required' => 'A metric_slug is required to identify the GamedMetric receiving XP.',
            'amount.min' => 'Award amount must be at least 1.',
        ];
    }
}
