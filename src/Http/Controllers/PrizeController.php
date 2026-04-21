<?php

declare(strict_types=1);

namespace LaravelFunLab\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use LaravelFunLab\Facades\LFL;
use LaravelFunLab\Http\Concerns\AuthorizesLflActions;
use LaravelFunLab\Http\Resources\PrizeResource;
use LaravelFunLab\Models\Prize;

class PrizeController extends Controller
{
    use AuthorizesLflActions;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Prize::query();

        if ($request->has('active')) {
            if ($request->boolean('active')) {
                $query->active();
            } else {
                $query->where('is_active', false);
            }
        } else {
            $query->active();
        }

        return PrizeResource::collection($query->ordered()->get());
    }

    public function store(Request $request): JsonResponse|PrizeResource
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'type' => ['sometimes', 'string'],
            'cost' => ['sometimes', 'numeric', 'min:0'],
            'inventory' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'meta' => ['sometimes', 'nullable', 'array'],
            'active' => ['sometimes', 'boolean'],
            'order' => ['sometimes', 'integer'],
        ]);

        $authDenied = $this->authorizeAction($request, 'setup', [
            'entity_type' => 'prize',
            'slug' => $data['slug'] ?? null,
        ]);
        if ($authDenied !== null) {
            return $authDenied;
        }

        $prize = LFL::setup('prize', $data);

        return new PrizeResource($prize);
    }
}
