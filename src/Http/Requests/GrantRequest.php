<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GrantRequest extends FormRequest
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
        return [
            'slug' => ['required', 'string', 'max:255'],
            'awardable_type' => ['required', 'string', 'max:255'],
            'awardable_id' => ['required', 'integer', 'min:1'],
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
            'slug.required' => 'An Achievement or Prize slug is required.',
        ];
    }
}
