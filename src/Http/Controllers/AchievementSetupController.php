<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Http\Concerns\AuthorizesLflActions;
use LaravelFunLab\Http\Resources\AchievementResource;

/**
 * AchievementSetupController
 *
 * POST /achievements — create an Achievement via LFL::setup('achievement', ...).
 * Read-side GET /achievements is still served by AchievementController.
 */
class AchievementSetupController extends Controller
{
    use AuthorizesLflActions;

    public function store(Request $request): JsonResponse|AchievementResource
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:255'],
            'for' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:255'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'active' => ['sometimes', 'boolean'],
            'order' => ['sometimes', 'integer'],
        ]);

        $authDenied = $this->authorizeAction($request, 'setup', [
            'entity_type' => 'achievement',
            'slug' => $data['slug'] ?? null,
        ]);
        if ($authDenied !== null) {
            return $authDenied;
        }

        $achievement = LFL::setup('achievement', $data);

        return new AchievementResource($achievement);
    }
}
