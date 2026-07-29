# HikBridge Laravel SDK

[![Tests](https://github.com/nugsoft/hikbridge-laravel-sdk/actions/workflows/tests.yml/badge.svg)](https://github.com/nugsoft/hikbridge-laravel-sdk/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11%2B-red)](https://laravel.com)

A Laravel SDK for the **HikBridge External Integration API** — a brand-agnostic REST API that sits between external business applications (HR, POS, clinic) and physical Hikvision access-control devices. This package abstracts the HikBridge API behind a clean, fluent PHP interface so your application never speaks the underlying device protocol directly.

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [How It Works](#how-it-works)
- [Demo Project](#demo-project)
- [Authentication & Scopes](#authentication--scopes)
- [Resources](#resources)
  - [Business](#business)
  - [Devices](#devices)
  - [Persons](#persons)
  - [Biometrics](#biometrics)
  - [Events](#events)
  - [Webhooks](#webhooks)
  - [Operations](#operations)
- [Async Operations & PendingOperation](#async-operations--pendingoperation)
- [Exception Handling](#exception-handling)
- [Testing](#testing)
- [Source Layout](#source-layout)

---

## Requirements

- PHP 8.2+
- Laravel 11+

---

## Installation

```bash
composer require nugsoft/hikbridge-laravel-sdk
```

The service provider and `HikBridge` facade are auto-discovered by Laravel — no manual registration is needed.

Publish the config file:

```bash
php artisan vendor:publish --tag=hikbridge-config
```

---

## Configuration

Add the following to your `.env` file:

```env
HIKBRIDGE_API_KEY=hbk_...
HIKBRIDGE_TIMEOUT=30
```

The published `config/hikbridge.php` exposes additional options:

```php
return [
    // Base URL of the HikBridge API (no trailing slash needed)
    'base_url' => env('HIKBRIDGE_BASE_URL', 'https://hikbridge.clocknesthr.com/api'),

    // Per-business API key (hbk_...) — sent as Authorization: Bearer on every request
    'api_key' => env('HIKBRIDGE_API_KEY'),

    // HTTP timeout in seconds
    'timeout' => (int) env('HIKBRIDGE_TIMEOUT', 30),

    // Automatic retry on transient connection-level failures (timeouts,
    // DNS/refused connections). HTTP error responses (4xx/5xx) are never
    // retried — they are surfaced immediately as typed exceptions (see below).
    // Set 'times' to 0 to disable retries entirely
    'retry' => [
        'times' => 3,
        'sleep' => 100, // milliseconds between retries
    ],
];
```

> **Retries vs. error responses.** Retries apply only to `ConnectionException`
> (the request never reached the server). Any HTTP response the server returns —
> including `5xx` — flows straight through to the SDK's exception mapping, so a
> `500`/`503` becomes a `ServerException` you can catch, not a raw HTTP-client
> exception.

---

## How It Works

The SDK exposes a single `HikBridge` facade. Every method group is accessed through a resource accessor:

```php
use Nugsoft\HikBridge\Facades\HikBridge;

HikBridge::business()->get();
HikBridge::devices()->list();
HikBridge::persons()->get(57);
HikBridge::biometrics(57)->uploadFace(35, $base64);
HikBridge::events()->list(['per_page' => 50]);
HikBridge::webhooks()->create([...]);
HikBridge::operations()->get('op_abc123');
```

**Return types:**

- Most methods return a **plain PHP array** (decoded JSON).
- Single-resource responses are wrapped: `['data' => [...]]`
- List responses are cursor-paginated: `['data' => [...], 'meta' => ['next_cursor' => '...']]`
- Endpoints that fan out to all devices return a `PendingOperation` object (HTTP 202) instead of an array — see [Async Operations](#async-operations--pendingoperation).

---

## Demo Project

Get hands-on with the SDK through our demo project. You can either explore the source code or try the live hosted application:

- **Source Repository:** [https://github.com/nugsoft/hikbridge-demo](https://github.com/nugsoft/hikbridge-demo) — a complete Laravel application showcasing the SDK in action
- **Live Demo:** [https://hikbridge-demo.nugsoftstagging.com/](https://hikbridge-demo.nugsoftstagging.com/) — test the SDK features in a running environment

The demo covers all major workflows including person management, biometric enrollment, access card registration, and webhook integration.

---

## Authentication & Scopes

Every request to `/v1/*` is authenticated with the API key set in `HIKBRIDGE_API_KEY`. The key is sent as `Authorization: Bearer hbk_...` automatically.

Keys are scoped to a set of **abilities**. Attempting an endpoint the key is not scoped for returns a `ForbiddenException` (403).

| Ability             | Grants access to                               |
| ------------------- | ---------------------------------------------- |
| `business:read`     | `GET /v1/business`                             |
| `devices:read`      | `GET /v1/devices`, `GET /v1/devices/:id`       |
| `devices:write`     | Register, update, configure ISUP, and delete devices |
| `persons:read`      | List, get, and poll operations                 |
| `persons:write`     | Create, update, delete persons                 |
| `biometrics:read`   | Biometric summaries, capture progress          |
| `biometrics:write`  | Upload/delete face, fingerprints, access cards |
| `events:read`       | List and trigger event sync                    |
| `webhooks:manage`   | Full CRUD on webhook subscriptions             |

---

## Resources

### Business

Returns the single business the API key belongs to. Requires ability `business:read`.

```php
$business = HikBridge::business()->get();
// $business['data']['id'], $business['data']['name'], ...
```

---

### Devices

Devices are the access-control units registered to the business. Every biometric operation requires a `device_id` from this list.

```php
// List all devices in the business
$devices = HikBridge::devices()->list();

foreach ($devices['data'] as $device) {
    echo $device['id'] . ' — ' . $device['name'];
}

// Get a single device
$device = HikBridge::devices()->get(35);
```

**Device object includes:** `id`, `name`, `integration_mode`, `capabilities` (e.g. `face_capture`, `fingerprint_capture`, `card_enrollment`).

#### Register a device from scratch

Nothing has to be provisioned on the HikBridge dashboard first — `create()` registers a new
device directly against the business behind your API key. Give it its local (ISAPI) connection
details: the device's IP, port, and the credentials of an operator account on the device itself.

```php
$device = HikBridge::devices()->create([
    'name'     => 'Main Entrance',
    'ip'       => '192.168.1.64',
    'port'     => 80,
    'username' => 'admin',
    'password' => 'device-operator-password',
]);

$deviceId = $device['data']['id'];
```

A device registered this way is reachable **Direct (ISAPI)** — over the local network, e.g. from
an on-site enrollment station. Requires the `devices:write` ability.

#### Reach a device remotely (ISUP)

A device behind NAT, or simply not on the same network as wherever your code runs (a web app,
for instance), needs the ISUP tunnel instead of a direct LAN connection. Save the device first,
then push its ISUP configuration:

```php
HikBridge::devices()->configureIsup($deviceId, [
    'server_host'        => '161.97.104.204', // your ISUP server's IP/hostname
    'registration_port'  => 7660,
    'http_api_port'      => 8089,
    'device_id'          => 'TEST01', // must match exactly what is registered in the ISUP service
    'isup_key'           => 'isup_secret',
]);
```

Once configured, set which transport enrollment calls use by default — a web app can only reach a
device through **Remote (ISUP)**; **Direct (ISAPI)** is only reachable from an on-site station on
the same network as the device:

```php
HikBridge::devices()->update($deviceId, ['enrollment_mode' => 'isup']);
```

`create()` returns a plain array (device registered synchronously, 201) unless you pass `isup`
details inline, in which case the bridge also validates the ISUP registration before confirming
and may respond 202 with a `PendingOperation` — see [Async Operations](#async-operations--pendingoperation).
A validation error (a malformed IP, credentials the device rejects, etc.) still raises the usual
typed exception rather than being returned as though it were a registered device.

#### Update or remove a device

```php
// Rename it, or change its ISAPI credentials
HikBridge::devices()->update($deviceId, ['name' => 'Front Gate']);

// Remove it entirely — always async, since unenrolling a device can mean clearing
// biometrics tied to it across every person synced against it.
$op = HikBridge::devices()->delete($deviceId);
$op->waitUntilDone();
```

---

### Persons

Persons are the people managed across the business's devices. `person_code` is the cross-system identifier — it must be unique, alphanumeric, max 16 characters, and is **immutable after creation**.

#### List persons

```php
$persons = HikBridge::persons()->list([
    'per_page' => 25,
    'search'   => 'john',      // matches person_code, first_name, last_name
    'status'   => 'active',    // active | inactive
    'cursor'   => $nextCursor, // for pagination
]);

$nextCursor = $persons['meta']['next_cursor'] ?? null;
```

#### Get one person

```php
$person = HikBridge::persons()->get(57);
echo $person['data']['first_name'];
```

#### Create a person

Person creation has two modes depending on whether you include `device_id`:

**Async (no `device_id`) — fans out to all active devices:**

```php
$op = HikBridge::persons()->create([
    'person_code' => 'EMP1003',
    'first_name'  => 'Amina',
    'last_name'   => 'Nakato',
    'status'      => 'active',
]);
// Returns PendingOperation (HTTP 202) — poll until all devices finish
$result = $op->waitUntilDone(timeout: 60);
```

**Sync (with `device_id`) — syncs to one device only:**

```php
$person = HikBridge::persons()->create([
    'person_code' => 'EMP1003',
    'first_name'  => 'Amina',
    'last_name'   => 'Nakato',
    'status'      => 'active',
    'device_id'   => 35,
]);
// Returns array (HTTP 201) — person + device_sync_status
echo $person['data']['device_sync_status'];
```

A sync create() still raises the usual typed exception (e.g. `ValidationException` for a
malformed `person_code`) rather than silently returning HikBridge's error body as if it were a
person — the 202-vs-array branching only decides *how success* is represented, not whether a
failure is.

#### Update a person

```php
$person = HikBridge::persons()->update(57, [
    'first_name' => 'Amina',
    'last_name'  => 'Nakato-Smith',
    'status'     => 'active',
]);
// person_code cannot be updated
// If name changes on a synced person, device_sync_status resets to 'pending'
```

#### Delete a person from all devices (async)

Always returns a `PendingOperation` — the deletion fans out to every device.

```php
$op = HikBridge::persons()->delete(57);
$result = $op->waitUntilDone(timeout: 60);
```

#### Delete a person from one device (sync)

```php
$result = HikBridge::persons()->deleteFromDevice(personId: 57, deviceId: 35);
// Local record is soft-deleted even if the device is unreachable
```

---

### Biometrics

Biometric operations are always device-specific. Access them through `HikBridge::biometrics(int $personId)`.

> **Capability check:** Live capture endpoints (face capture, fingerprint capture) return `422` early if the device does not support that capability. Check `$device['data']['capabilities']` beforehand if needed.

#### Summary

```php
$summary = HikBridge::biometrics(57)->summary(deviceId: 35);
// Returns face, fingerprint, and access card status for the person on that device
```

#### Face

**Upload a face photo:**

```php
$base64 = base64_encode(file_get_contents('/path/to/photo.jpg'));
// data-URI prefix (data:image/jpeg;base64,...) is accepted but not required
$result = HikBridge::biometrics(57)->uploadFace(deviceId: 35, base64Image: $base64);

if (isset($result['warning'])) {
    // Photo saved locally but could not be pushed to the device
    // $result['warning']['pushed_to_device'] === false
    logger()->warning('Face not pushed to device', $result['warning']);
}
```

**Live face capture** (person stands in front of the device camera):

```php
// Trigger capture
HikBridge::biometrics(57)->captureFace(deviceId: 35);

// Poll until done
do {
    sleep(2);
    $progress = HikBridge::biometrics(57)->faceCaptureProgress(deviceId: 35);
} while ($progress['data']['status'] === 'capturing');
```

**Delete face:**

```php
HikBridge::biometrics(57)->deleteFace(deviceId: 35);
```

#### Fingerprint

`finger_index` uses the convention: 0 = right thumb, 1–4 = right index to right little, 5 = left thumb, 6–9 = left index to left little.

**Store a fingerprint template:**

```php
// With a base64 template — downloaded to the device immediately
HikBridge::biometrics(57)->storeFingerprint(
    deviceId: 35,
    fingerIndex: 1,
    template: $base64Template,
);

// With template: null — enrollment recorded locally, no device push
HikBridge::biometrics(57)->storeFingerprint(deviceId: 35, fingerIndex: 1);
```

**Live fingerprint capture** (person places finger on the scanner):

```php
// Trigger capture — specify which finger
HikBridge::biometrics(57)->captureFingerprint(deviceId: 35, fingerIndex: 1);

// Poll until done
do {
    sleep(2);
    $progress = HikBridge::biometrics(57)->fingerprintCaptureProgress(deviceId: 35);
} while ($progress['data']['status'] === 'capturing');
```

**Delete a fingerprint:**

```php
HikBridge::biometrics(57)->deleteFingerprint(deviceId: 35, fingerIndex: 1);
```

#### Access Card

**Register a card:**

```php
HikBridge::biometrics(57)->addAccessCard(
    deviceId: 35,
    cardNo: '1234567890',
    cardType: 1, // 1 = normal; device-defined, up to 4
);
```

**Remove a card:**

```php
HikBridge::biometrics(57)->deleteAccessCard(deviceId: 35, cardNo: '1234567890');
```

---

### Events

Access events are aggregated from all devices and stored in HikBridge. They are returned newest-first and are cursor-paginated.

#### List events

```php
$page = HikBridge::events()->list([
    'per_page'    => 50,
    'from'        => '2026-06-01T00:00:00',  // ISO 8601
    'to'          => '2026-06-13T23:59:59',  // ISO 8601
    'person_code' => 'EMP1001',
    'event_type'  => 'face',                 // face | card | fingerprint
    'device_id'   => 35,
    'cursor'      => $nextCursor,
]);

$nextCursor = $page['meta']['next_cursor'] ?? null;
```

**Paginate through all events:**

```php
$cursor = null;

do {
    $page   = HikBridge::events()->list(['per_page' => 100, 'cursor' => $cursor]);
    $cursor = $page['meta']['next_cursor'] ?? null;

    foreach ($page['data'] as $event) {
        // process each event
    }
} while ($cursor);
```

#### Trigger a manual sync

HikBridge polls devices on a 5-minute schedule. Call `triggerSync()` for an on-demand pull. Both parameters are optional — the server defaults to the last 24 hours.

```php
$result = HikBridge::events()->triggerSync(
    from: '2026-06-12T00:00:00',
    to:   '2026-06-13T23:59:59',
);
// Returns 202 — requires the queue worker to be running
```

---

### Webhooks

Webhooks push signed payloads to an external URL when events occur in HikBridge.

Supported event types:

| Event type      | When it fires                     |
| --------------- | --------------------------------- |
| `access.event`  | A person accesses a device        |
| `person.synced` | A person sync operation completes |
| `*`             | All event types                   |

#### Create a subscription

```php
$webhook = HikBridge::webhooks()->create([
    'url'         => 'https://yourapp.com/webhooks/hikbridge',
    'event_types' => ['access.event', 'person.synced'],
    'is_active'   => true,
]);

// The signing secret is returned EXACTLY ONCE — store it immediately
$secret = $webhook['data']['secret']; // whsec_...
```

#### List, get, update, delete

```php
$webhooks = HikBridge::webhooks()->list();

$webhook = HikBridge::webhooks()->get(1);

HikBridge::webhooks()->update(1, [
    'event_types' => ['*'],
    'is_active'   => true,
    // 'url' can also be updated; 'secret' cannot be changed
]);

HikBridge::webhooks()->delete(1); // returns void, HTTP 204
```

#### Send a test ping

Dispatches a signed test payload to the subscription URL. Useful for verifying your endpoint is reachable. Requires the queue worker running.

```php
$result = HikBridge::webhooks()->sendTestPing(1);
// Returns 202 with a delivery_id — check deliveries() for the result
```

#### View delivery history

```php
$deliveries = HikBridge::webhooks()->deliveries(1);
// Paginated log of payloads, HTTP response codes, and retry status
```

#### Verifying incoming webhook signatures

Every webhook delivery includes an `X-HikBridge-Signature` header signed with HMAC-SHA256.

```php
$payload   = $request->getContent();
$signature = $request->header('X-HikBridge-Signature');
$secret    = config('services.hikbridge_webhook_secret'); // the whsec_... you stored

$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (! hash_equals($expected, $signature)) {
    abort(401, 'Invalid webhook signature');
}

$event = $request->json()->all();
// $event['type'], $event['data'], ...
```

---

### Operations

Any endpoint that fans out to all devices returns HTTP 202 with an `operation_id`. Poll the operation to track per-device progress.

```php
$operation = HikBridge::operations()->get('op_abc123');

// $operation['data']['status']      → pending | completed | failed
// $operation['data']['devices'][]   → per-device results
```

In practice, prefer `PendingOperation::waitUntilDone()` over manual polling — see the next section.

---

## Async Operations & PendingOperation

Operations that target **all active devices** (create person, delete person, event sync) return a `PendingOperation` instead of an array. This object wraps the 202 response and lets you either poll manually or block until done.

### Properties

```php
$op->operationId; // string — the operation ID, e.g. "op_abc123"
$op->data;        // array — the entity being created or deleted
$op->isPending(); // bool — always true (the operation was just created)
```

### waitUntilDone()

Blocks the current process, polling `GET /v1/operations/{id}` at regular intervals until the operation completes or the timeout expires.

```php
$op = HikBridge::persons()->create([
    'person_code' => 'EMP1003',
    'first_name'  => 'Amina',
    'last_name'   => 'Nakato',
    'status'      => 'active',
]);

try {
    $result = $op->waitUntilDone(timeout: 60, interval: 2);
    // $result['data']['status'] === 'completed'
    // $result['data']['devices'] → per-device sync results
} catch (\Nugsoft\HikBridge\Exceptions\HikBridgeException $e) {
    // Operation failed or timed out
    logger()->error('Sync failed: ' . $e->getMessage());
}
```

| Parameter   | Default | Description                             |
| ----------- | ------- | --------------------------------------- |
| `$timeout`  | `60`    | Maximum seconds to wait before throwing |
| `$interval` | `2`     | Seconds between each poll               |

> **Queues:** Async operations require the Laravel queue worker to be running. In local development, `php artisan queue:work` (or `composer dev` if configured) handles this.

---

## Exception Handling

All exceptions extend `Nugsoft\HikBridge\Exceptions\HikBridgeException`, so you can catch them individually or with the base class.

| Exception                 | HTTP status | Notes                                                |
| ------------------------- | ----------- | ---------------------------------------------------- |
| `AuthenticationException` | 401         | Invalid or missing API key                           |
| `ForbiddenException`      | 403         | Key lacks the required ability                       |
| `NotFoundException`       | 404         | Resource does not exist or belongs to another business |
| `ValidationException`     | 422         | Invalid input — call `->errors()` for field details  |
| `RateLimitException`      | 429         | Too many requests                                    |
| `ServerException`         | 5xx         | HikBridge server error                               |
| `HikBridgeException`      | any         | Base class; also thrown on operation failure/timeout |

```php
use Nugsoft\HikBridge\Exceptions\NotFoundException;
use Nugsoft\HikBridge\Exceptions\ValidationException;
use Nugsoft\HikBridge\Exceptions\ForbiddenException;
use Nugsoft\HikBridge\Exceptions\HikBridgeException;

try {
    $person = HikBridge::persons()->get($id);

} catch (NotFoundException $e) {
    return response()->json(['error' => 'Person not found'], 404);

} catch (ValidationException $e) {
    // $e->errors() returns ['field' => ['message', ...], ...]
    return response()->json(['errors' => $e->errors()], 422);

} catch (ForbiddenException $e) {
    // API key does not have the required ability for this endpoint
    return response()->json(['error' => 'Insufficient permissions'], 403);

} catch (HikBridgeException $e) {
    // Covers auth errors, rate limits, server errors, and async failures
    report($e);
    return response()->json(['error' => 'HikBridge error'], 500);
}
```

**ValidationException field errors:**

```php
try {
    HikBridge::persons()->create(['first_name' => 'Amina']); // missing person_code
} catch (ValidationException $e) {
    $errors = $e->errors();
    // ['person_code' => ['The person code field is required.']]
}
```

---

## Testing

The SDK wraps Laravel's `Http` facade, so `Http::fake()` is all you need — no custom mock layer or test doubles required.

### Faking a successful response

```php
use Illuminate\Support\Facades\Http;
use Nugsoft\HikBridge\Facades\HikBridge;

Http::fake([
    '*/v1/persons/57*' => Http::response([
        'data' => ['id' => 57, 'first_name' => 'Amina', 'person_code' => 'EMP001'],
    ], 200),
]);

$person = HikBridge::persons()->get(57);

expect($person['data']['id'])->toBe(57);
Http::assertSentCount(1);
```

### Faking an async (202) create

```php
Http::fake([
    '*/v1/persons'           => Http::response([
        'operation_id' => 'op_abc123',
        'data'         => ['id' => 10, 'person_code' => 'EMP001'],
    ], 202),
    '*/v1/operations/op_abc123' => Http::response([
        'data' => ['status' => 'completed', 'devices' => []],
    ], 200),
]);

$op = HikBridge::persons()->create([
    'person_code' => 'EMP001',
    'first_name'  => 'Amina',
    'last_name'   => 'Nakato',
    'status'      => 'active',
]);

expect($op)->toBeInstanceOf(\Nugsoft\HikBridge\PendingOperation::class)
    ->and($op->operationId)->toBe('op_abc123');

$result = $op->waitUntilDone(timeout: 10, interval: 0);
expect($result['data']['status'])->toBe('completed');
```

### Faking an error response

```php
use Nugsoft\HikBridge\Exceptions\NotFoundException;

Http::fake([
    '*/v1/persons/999*' => Http::response(['message' => 'Not found'], 404),
]);

expect(fn () => HikBridge::persons()->get(999))
    ->toThrow(NotFoundException::class);
```

### Faking a validation error

```php
use Nugsoft\HikBridge\Exceptions\ValidationException;

Http::fake([
    '*/v1/persons' => Http::response([
        'message' => 'The given data was invalid.',
        'errors'  => ['person_code' => ['The person code field is required.']],
    ], 422),
]);

try {
    HikBridge::persons()->create(['first_name' => 'Amina']);
} catch (ValidationException $e) {
    expect($e->errors())->toHaveKey('person_code');
}
```

### Asserting request details

```php
Http::fake(['*/v1/persons*' => Http::response(['data' => []], 200)]);

HikBridge::persons()->list(['per_page' => 10, 'status' => 'active']);

Http::assertSent(function ($request) {
    return str_contains($request->url(), 'per_page=10')
        && str_contains($request->url(), 'status=active');
});
```

---

## License

MIT License. See [LICENSE](LICENSE) for details.
