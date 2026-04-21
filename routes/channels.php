<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;
use LaravelFunLab\Support\AwardableTypeResolver;

/**
 * LFL Broadcast Channel Authorization
 *
 * Authorizes subscriptions to private LFL profile channels.
 * Channel name: `lfl.profile.{Awardable.Type.Path}.{id}`
 * (backslashes in the class name are replaced with dots).
 *
 * Reject BEFORE `instanceof`, to prevent attacker-controlled channel names
 * from triggering class autoload on non-allowlisted types.
 *
 * Loaded by LFLServiceProvider only when config('lfl.events.broadcast') is true.
 */
Broadcast::channel('lfl.profile.{typePath}.{id}', function ($user, string $typePath, string|int $id) {
    if ($user === null) {
        return false;
    }

    $awardableType = str_replace('.', '\\', $typePath);

    if (! AwardableTypeResolver::isAllowed($awardableType)) {
        return false;
    }

    if (! ($user instanceof $awardableType)) {
        return false;
    }

    return (string) $user->getKey() === (string) $id;
});
