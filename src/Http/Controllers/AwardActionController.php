<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Controllers;

use Illuminate\Http\JsonResponse;
use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Http\Concerns\AuthorizesLflActions;
use LaravelFunLab\Http\Requests\AwardXpRequest;
use LaravelFunLab\Http\Resources\AwardResource;
use LaravelFunLab\Models\ProfileMetric;
use LaravelFunLab\Support\AwardableTypeResolver;

/**
 * AwardActionController
 *
 * POST /awards — award XP to an awardable model via LFL::award().
 * Gated by config('lfl.api.writes') in the route definition. Additionally:
 *  - awardable_type must be in config('lfl.awardables'),
 *  - config('lfl.authorize.award') must approve the call.
 */
class AwardActionController extends Controller
{
    use AuthorizesLflActions;

    public function store(AwardXpRequest $request): JsonResponse|AwardResource
    {
        $data = $request->validated();

        $resolved = AwardableTypeResolver::resolve($data['awardable_type'], $data['awardable_id']);
        if (! $resolved['ok']) {
            return response()->json(['message' => $resolved['message']], $resolved['status']);
        }

        $recipient = $resolved['model'];

        $authDenied = $this->authorizeAction($request, 'award', [
            'recipient' => $recipient,
            'metric_slug' => $data['metric_slug'],
            'amount' => (int) $data['amount'],
        ]);
        if ($authDenied !== null) {
            return $authDenied;
        }

        $builder = LFL::award($data['metric_slug'])
            ->to($recipient)
            ->amount((int) $data['amount']);

        if (! empty($data['reason'])) {
            $builder->because($data['reason']);
        }
        if (! empty($data['source'])) {
            $builder->from($data['source']);
        }
        if (! empty($data['meta'])) {
            $builder->withMeta($data['meta']);
        }

        try {
            $profileMetric = $builder->save();
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! $profileMetric instanceof ProfileMetric) {
            return response()->json(['message' => 'Award did not persist.'], 422);
        }

        return new AwardResource($profileMetric->loadMissing('gamedMetric'));
    }
}
