<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Controllers;

use Illuminate\Http\JsonResponse;
use LaravelFunLab\Http\Concerns\AuthorizesLflActions;
use LaravelFunLab\Http\Requests\OptInRequest;
use LaravelFunLab\Http\Resources\ProfileResource;
use LaravelFunLab\Models\Profile;
use LaravelFunLab\Support\AwardableTypeResolver;

/**
 * OptInController
 *
 * POST /profiles/{type}/{id}/opt-in | opt-out — opt awardable in/out of gamification.
 * Gated by config('lfl.api.writes'), the awardable allowlist, and the
 * config('lfl.authorize.opt') callable.
 */
class OptInController extends Controller
{
    use AuthorizesLflActions;

    public function in(OptInRequest $request, string $type, int|string $id): JsonResponse|ProfileResource
    {
        return $this->toggle($request, $type, $id, true);
    }

    public function out(OptInRequest $request, string $type, int|string $id): JsonResponse|ProfileResource
    {
        return $this->toggle($request, $type, $id, false);
    }

    protected function toggle(OptInRequest $request, string $type, int|string $id, bool $optIn): JsonResponse|ProfileResource
    {
        $resolved = AwardableTypeResolver::resolve($type, $id);
        if (! $resolved['ok']) {
            return response()->json(['message' => $resolved['message']], $resolved['status']);
        }

        $target = $resolved['model'];

        $authDenied = $this->authorizeAction($request, 'opt', [
            'target' => $target,
            'direction' => $optIn ? 'in' : 'out',
        ]);
        if ($authDenied !== null) {
            return $authDenied;
        }

        /** @var Profile $profile */
        $profile = $target->getProfile();
        $optIn ? $profile->optIn() : $profile->optOut();

        return new ProfileResource($profile->fresh());
    }
}
