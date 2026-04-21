<?php

declare(strict_types=1);

namespace LaravelFunLab\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * AwardableTypeResolver
 *
 * Gatekeeper for all request-supplied awardable type strings. Rejects any
 * type not explicitly listed in `config('lfl.awardables')` BEFORE triggering
 * the class autoloader, preventing reconnaissance and side-effect attacks via
 * attacker-controlled class names.
 */
class AwardableTypeResolver
{
    /**
     * Resolve a request-supplied type string into an awardable Model instance.
     *
     * @return array{ok: true, model: Model}|array{ok: false, status: int, message: string}
     */
    public static function resolve(string $type, int|string $id): array
    {
        $check = self::validateType($type);
        if ($check !== null) {
            return $check;
        }

        /** @var class-string<Model> $type */
        $model = $type::query()->find($id);

        if ($model === null) {
            return [
                'ok' => false,
                'status' => 404,
                'message' => "Awardable not found for {$type}#{$id}.",
            ];
        }

        if (! method_exists($model, 'getProfile')) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => "Model {$type} does not use the Awardable trait.",
            ];
        }

        return ['ok' => true, 'model' => $model];
    }

    /**
     * Validate a type string against the configured allowlist.
     *
     * @return array{ok: false, status: int, message: string}|null null means valid
     */
    public static function validateType(string $type): ?array
    {
        $allowed = (array) config('lfl.awardables', []);

        if ($allowed === []) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'No awardable types are configured. Add your model classes to config("lfl.awardables").',
            ];
        }

        if (! in_array($type, $allowed, true)) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => "Awardable type '{$type}' is not permitted.",
            ];
        }

        return null;
    }

    /**
     * Check whether the given type is in the allowlist.
     */
    public static function isAllowed(string $type): bool
    {
        return in_array($type, (array) config('lfl.awardables', []), true);
    }
}
