<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LaravelFunLab\Http\Resources\ProfileResource;
use LaravelFunLab\Models\Profile;

/**
 * ProfileController
 *
 * GET /profiles/{type}/{id} — profile by awardable polymorph.
 *
 * Respects Profile::visibility_settings:
 *   - null  or []               → public (default, backward-compatible)
 *   - ['public' => false]       → deny anonymous reads with 403; require that
 *                                 the authenticated user own the profile
 */
class ProfileController extends Controller
{
    public function show(Request $request, string $type, int|string $id): JsonResponse|ProfileResource
    {
        $profile = Profile::query()
            ->where('awardable_type', $type)
            ->where('awardable_id', $id)
            ->first();

        if ($profile === null) {
            return response()->json(['message' => 'Profile not found'], 404);
        }

        if (! $this->isVisibleTo($profile, $request)) {
            return response()->json([
                'message' => 'This profile is private.',
            ], 403);
        }

        return new ProfileResource($profile);
    }

    /**
     * Visibility gate.
     *
     * The profile owner can always see their own profile. Non-owners can see
     * it only if visibility_settings.public !== false (default true).
     */
    protected function isVisibleTo(Profile $profile, Request $request): bool
    {
        $settings = $profile->visibility_settings ?? [];
        $public = ! array_key_exists('public', $settings) || (bool) $settings['public'];

        if ($public) {
            return true;
        }

        $user = $request->user();
        if ($user === null) {
            return false;
        }

        // Owner exception — the profile's awardable is the same user.
        return get_class($user) === $profile->awardable_type
            && (string) $user->getKey() === (string) $profile->awardable_id;
    }
}
