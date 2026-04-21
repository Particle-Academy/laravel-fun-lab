<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OptInRequest extends FormRequest
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
        return [];
    }
}
