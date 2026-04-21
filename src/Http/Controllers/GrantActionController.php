<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Controllers;

use Illuminate\Http\JsonResponse;
use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Http\Concerns\AuthorizesLflActions;
use LaravelFunLab\Http\Requests\GrantRequest;
use LaravelFunLab\Http\Resources\AwardGrantResource;
use LaravelFunLab\Support\AwardableTypeResolver;

/**
 * GrantActionController
 *
 * POST /grants — grant an Achievement or Prize via LFL::grant().
 * The slug auto-resolves to the correct entity type. Gated by
 * config('lfl.api.writes'), the awardable allowlist, and the
 * config('lfl.authorize.grant') callable.
 */
class GrantActionController extends Controller
{
    use AuthorizesLflActions;

    public function store(GrantRequest $request): JsonResponse|AwardGrantResource
    {
        $data = $request->validated();

        $resolved = AwardableTypeResolver::resolve($data['awardable_type'], $data['awardable_id']);
        if (! $resolved['ok']) {
            return response()->json(['message' => $resolved['message']], $resolved['status']);
        }

        $recipient = $resolved['model'];

        $authDenied = $this->authorizeAction($request, 'grant', [
            'recipient' => $recipient,
            'slug' => $data['slug'],
        ]);
        if ($authDenied !== null) {
            return $authDenied;
        }

        $builder = LFL::grant($data['slug'])->to($recipient);

        if (! empty($data['reason'])) {
            $builder->because($data['reason']);
        }
        if (! empty($data['source'])) {
            $builder->from($data['source']);
        }
        if (! empty($data['meta'])) {
            $builder->withMeta($data['meta']);
        }

        $result = $builder->save();

        if ($result->failed()) {
            return response()->json([
                'message' => $result->message,
                'errors' => $result->errors,
            ], 422);
        }

        return new AwardGrantResource($result->award);
    }
}
