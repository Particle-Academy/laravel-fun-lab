# Broadcasting

LFL can broadcast key lifecycle events over Laravel's `ShouldBroadcast` contract so real-time UIs can react as XP is earned, achievements are unlocked, and prizes are awarded.

**Important:** LFL ships no broadcasting driver. Install and configure Reverb, Pusher, Ably, or a custom driver yourself. LFL only implements the broadcast contract; delivery is the consumer's responsibility.

## Enabling broadcasting

Broadcasting is opt-in via config:

```php
// config/lfl.php
'events' => [
    'broadcast' => env('LFL_EVENTS_BROADCAST', false),
],
```

Or in `.env`:

```
LFL_EVENTS_BROADCAST=true
```

When enabled, LFL's events implement `ShouldBroadcast` and emit on private channels. Channel authorization is registered automatically when the flag is on.

## Events that broadcast

| Event                   | `broadcastAs`             |
|-------------------------|---------------------------|
| `XpAwarded`             | `xp.awarded`              |
| `MetricLevelReached`    | `metric.level-reached`    |
| `AchievementUnlocked`   | `achievement.unlocked`    |
| `PrizeAwarded`          | `prize.awarded`           |

Deprecated events (`PointsAwarded`, `BadgeAwarded`) do **not** broadcast.

## Channel naming

Events broadcast on a private channel scoped to the awardable polymorph:

```
private-lfl.profile.{awardable_type}.{awardable_id}
```

Backslashes in the awardable class name are replaced with dots, so `App\Models\User` becomes `App.Models.User`.

### Example

```
private-lfl.profile.App.Models.User.42
```

## Channel authorization

The package registers a `Broadcast::channel(...)` callback that authorizes subscriptions only when the authenticated user:

1. Is an instance of the `awardable_type` decoded from the channel name, and
2. Has the same primary key as `awardable_id`.

Authorization uses whatever auth guard the consumer has bound to the broadcasting routes. LFL ships no auth — point `BroadcastServiceProvider`'s broadcast middleware (typically `auth:sanctum` for SPA setups) at your preferred guard.

## Event payloads

### `xp.awarded`

```json
{
  "id": 12,
  "profile_id": 1,
  "gamed_metric_id": 3,
  "gamed_metric_slug": "combat-xp",
  "gamed_metric_name": "Combat XP",
  "amount": 50,
  "total_xp": 500,
  "current_level": 3,
  "reason": "defeated boss",
  "source": "combat-system",
  "occurred_at": "2026-04-20T12:00:00Z"
}
```

### `metric.level-reached`

One event per level crossed, in ascending order: an award that takes a metric
from level 1 to level 4 sends three. A level is never announced twice, however
often progression is recomputed or however many requests race over the same
threshold. `achievements` lists what this level granted on this crossing, not
what the profile already held.

```json
{
  "profile_id": 1,
  "gamed_metric_id": 3,
  "gamed_metric_slug": "combat-xp",
  "gamed_metric_name": "Combat XP",
  "level": 3,
  "level_name": "Adept",
  "xp_threshold": 200,
  "total_xp": 250,
  "achievements": [
    { "id": 4, "slug": "adept", "name": "Adept", "icon": "sword" }
  ],
  "occurred_at": "2026-11-05T12:00:00Z"
}
```

On the server, listen for `LaravelFunLab\Events\MetricLevelReached`. It is not an
award, so it is not written to the event log.

### `achievement.unlocked`

```json
{
  "grant_type": "achievement",
  "id": 7,
  "profile_id": 1,
  "achievement_id": 4,
  "achievement_slug": "first-login",
  "achievement_name": "First Login",
  "achievement_icon": "star",
  "reason": "completed onboarding",
  "source": "registration-flow",
  "granted_at": "2026-04-20T12:00:00Z"
}
```

### `prize.awarded`

```json
{
  "grant_type": "prize",
  "id": 9,
  "profile_id": 1,
  "prize_id": 2,
  "prize_slug": "premium-access",
  "prize_name": "1 Month Premium",
  "status": "pending",
  "reason": "won monthly contest",
  "source": null,
  "granted_at": "2026-04-20T12:00:00Z"
}
```

These payloads match the fields returned by the corresponding REST Resources (`AwardResource`, `AwardGrantResource`) so SDK consumers see a single shape across REST and Echo.

## Consumer setup (example: Reverb)

The package assumes the consumer has installed a broadcasting driver. For Reverb:

```bash
composer require laravel/reverb
php artisan reverb:install
```

Then in the consumer's `.env`:

```
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_HOST=...
REVERB_PORT=...
```

And enable LFL broadcasting:

```
LFL_EVENTS_BROADCAST=true
```

Any Pusher-protocol driver (Reverb, Pusher, Soketi) works. Ably requires an extra Ably connector but the payloads are identical.

## Subscribing from JavaScript

See [React / SPA integration](react.md#real-time-events) for an end-to-end `laravel-echo` example, including authorization headers and event listener patterns.

## Payload trust model

**Treat every field in a broadcast payload as untrusted.** `reason`, `source`, and `meta` fields originate in HTTP requests (e.g. `POST /api/lfl/awards`) and flow through the system verbatim into the `broadcastWith()` payload. Consumers rendering these fields in a browser or mobile UI must sanitize/escape them — LFL does not.

Specifically:
- Length is capped in the Form Requests (`reason` ≤ 1000 chars, `source` ≤ 255 chars, `meta` is a validated array). No HTML or script sanitization is performed.
- The authenticated identity that caused the award is NOT included in the payload by default. If you need attribution in the UI, fetch it separately via your own API.
- The award and profile IDs in the payload are not guessable and are scoped to the private channel's subscriber, but anyone with that user's session can read them.

## Queueing

Broadcast events use the queue configured in `config('lfl.events.queue')` (default: `null`, sync). For production, set it to a dedicated queue:

```php
'events' => [
    'queue' => 'broadcasts',
],
```

## Disabling broadcasting per-event

Override an event on the consumer side if you want finer control: extend it, override `broadcastWhen()` or `broadcastOn()`, and bind your subclass in a service provider. LFL does not lock down the event classes beyond the public contract.

## Next Steps

- [React / SPA integration](react.md)
- [API Reference](api.md#rest-api-endpoints)
- [Configuration](configuration.md)
