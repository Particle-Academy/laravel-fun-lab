# React / SPA Integration

This guide covers integrating the LFL REST API with a React single-page application (or any similar SPA framework). It describes the auth middleware contract, example authentication flows, CORS setup, and real-time event handling.

**Important:** LFL ships no authentication, no SDK, and no transport driver. The package provides a gamification API surface; consumers wrap their own security and infrastructure at the application layer.

## Table of Contents

1. [The auth contract](#the-auth-contract)
2. [CORS](#cors)
3. [Auth flow: bearer tokens](#auth-flow-bearer-tokens)
4. [Auth flow: session cookies](#auth-flow-session-cookies)
5. [Consuming endpoints](#consuming-endpoints)
6. [Real-time events](#real-time-events)
7. [Typed clients from OpenAPI](#typed-clients-from-openapi)

## The auth contract

LFL expects the consumer to register a middleware via `config('lfl.api.auth.middleware')`. That middleware must:

1. Resolve a model instance onto the request via `$request->user()`.
2. For `/me/*` endpoints, the resolved model must use the `Awardable` trait. Otherwise the endpoint returns 422.
3. For all other endpoints the middleware can optionally apply — it's up to the consumer whether, for example, public profile reads are allowed without auth.

Any Laravel-compatible guard works: Sanctum, Passport, custom guards. Two common flows follow.

## CORS

For a cross-origin SPA you must configure CORS in the host application. Publish the config if it does not exist, then allow the SPA origin:

```bash
php artisan vendor:publish --tag=cors
```

```php
// config/cors.php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/*'],
    'allowed_origins' => ['http://localhost:5174'],
    'allowed_methods' => ['*'],
    'allowed_headers' => ['*'],
    'supports_credentials' => true, // required for cookie flow
];
```

## Auth flow: bearer tokens

Simplest for cross-origin demos. The consumer's host app issues a token; the SPA stores it and sends it as `Authorization: Bearer <token>`.

**Host side** (example using Sanctum PAT):

```php
// routes/api.php (consumer app, not LFL)
Route::post('/auth/token', function (Request $request) {
    $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required'],
        'device_name' => ['required'],
    ]);

    $user = User::where('email', $request->email)->first();
    if (! $user || ! Hash::check($request->password, $user->password)) {
        throw ValidationException::withMessages(['email' => ['Invalid credentials.']]);
    }

    return ['token' => $user->createToken($request->device_name)->plainTextToken];
});
```

```php
// config/lfl.php
'api' => [
    'auth' => ['middleware' => 'auth:sanctum'],
],
```

**SPA side:**

```ts
const res = await fetch("https://api.example.com/api/auth/token", {
  method: "POST",
  headers: { "Content-Type": "application/json", "Accept": "application/json" },
  body: JSON.stringify({ email, password, device_name: "web" }),
});
const { token } = await res.json();
localStorage.setItem("lfl_token", token);

// Subsequent requests
await fetch("https://api.example.com/api/lfl/me/profile", {
  headers: {
    "Authorization": `Bearer ${token}`,
    "Accept": "application/json",
  },
});
```

## Auth flow: session cookies

Stateful for same-site or configured cross-site SPAs (using Sanctum's SPA cookie mode):

```env
SANCTUM_STATEFUL_DOMAINS=localhost:5174
```

**SPA side:**

```ts
// 1. Seed CSRF cookie
await fetch("https://api.example.com/sanctum/csrf-cookie", { credentials: "include" });

// 2. Log in (host's own /login or similar)
await fetch("https://api.example.com/login", {
  method: "POST",
  credentials: "include",
  headers: { "Content-Type": "application/json", "X-XSRF-TOKEN": readCookie("XSRF-TOKEN") },
  body: JSON.stringify({ email, password }),
});

// 3. Subsequent LFL API calls
await fetch("https://api.example.com/api/lfl/me/profile", {
  credentials: "include",
  headers: { "Accept": "application/json", "X-XSRF-TOKEN": readCookie("XSRF-TOKEN") },
});
```

Cookies require `supports_credentials: true` in CORS and exact-match `allowed_origins` (no wildcards).

## Consuming endpoints

All responses are wrapped in a `{ data, meta?, links?, profile? }` envelope. Example SPA helper:

```ts
async function lfl<T>(path: string, init?: RequestInit): Promise<{ data: T }> {
  const token = localStorage.getItem("lfl_token");
  const res = await fetch(`/api/lfl${path}`, {
    ...init,
    headers: {
      "Accept": "application/json",
      "Content-Type": "application/json",
      ...(token ? { "Authorization": `Bearer ${token}` } : {}),
      ...(init?.headers ?? {}),
    },
  });
  if (!res.ok) throw new Error(`LFL ${res.status}`);
  return res.json();
}

// Award XP
await lfl("/awards", {
  method: "POST",
  body: JSON.stringify({
    metric_slug: "combat-xp",
    awardable_type: "App\\Models\\User",
    awardable_id: 1,
    amount: 50,
  }),
});
```

## Real-time events

When `config('lfl.events.broadcast')` is `true` and a broadcasting driver is configured by the consumer, LFL broadcasts `XpAwarded`, `AchievementUnlocked`, and `PrizeAwarded` on `private-lfl.profile.{Awardable.Class.Path}.{id}` (backslashes in the class name become dots).

```ts
import Echo from "laravel-echo";
import Pusher from "pusher-js";

window.Pusher = Pusher;
const echo = new Echo({
  broadcaster: "reverb",
  key: import.meta.env.VITE_REVERB_APP_KEY,
  wsHost: import.meta.env.VITE_REVERB_HOST,
  wsPort: import.meta.env.VITE_REVERB_PORT,
  forceTLS: import.meta.env.VITE_REVERB_SCHEME === "https",
  authorizer: (channel) => ({
    authorize: async (socketId, callback) => {
      const res = await fetch("/broadcasting/auth", {
        method: "POST",
        credentials: "include",
        headers: {
          "Content-Type": "application/json",
          "Authorization": `Bearer ${localStorage.getItem("lfl_token")}`,
        },
        body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
      });
      callback(null, await res.json());
    },
  }),
});

echo
  .private(`lfl.profile.App.Models.User.${userId}`)
  .listen(".xp.awarded", (event) => {
    console.log("XP!", event.amount, event.total_xp);
  })
  .listen(".achievement.unlocked", (event) => {
    console.log("Unlocked", event.achievement_name);
  })
  .listen(".prize.awarded", (event) => {
    console.log("Prize", event.prize_name);
  });
```

See [Broadcasting](broadcasting.md) for the full event payload reference.

## Typed clients from OpenAPI

The package ships a machine-readable OpenAPI 3.0 spec at `/api/lfl/openapi.json` and (when published) at `resources/openapi/lfl.yaml`. Generate a typed client with any tool that consumes OpenAPI:

```bash
npx openapi-typescript /api/lfl/openapi.json -o src/api/lfl.d.ts
```

Or import the published YAML directly into an SDK generator of your choice. LFL does not ship a prebuilt SDK — the client layer is always the consumer's responsibility.

## Next Steps

- [REST API Reference](api.md#rest-api-endpoints) - Full endpoint reference
- [Broadcasting](broadcasting.md) - Real-time event contract
- [Configuration](configuration.md) - `api.*` and `events.*` config keys
