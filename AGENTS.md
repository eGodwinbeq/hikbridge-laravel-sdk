# HikBridge Laravel SDK — Agent Instructions

This file provides context for AI coding agents (OpenAI Codex, Gemini CLI, and others that read `AGENTS.md`).

## What this repo is

`nugsoft/hikbridge-laravel` — a Laravel 11+ client SDK for the **HikBridge External Integration API**, a REST API that manages persons, biometrics, and access events across Hikvision access-control devices. This is a **library package**, not an application.

## Package identity

| | |
|---|---|
| Composer package | `nugsoft/hikbridge-laravel` |
| PHP namespace | `Nugsoft\HikBridge` |
| Laravel facade | `HikBridge::` (via `Nugsoft\HikBridge\Facades\HikBridge`) |
| Config | `config/hikbridge.php` |
| Env vars | `HIKBRIDGE_BASE_URL`, `HIKBRIDGE_API_KEY`, `HIKBRIDGE_TIMEOUT` |
| Minimum requirements | PHP 8.2+, Laravel 11+ |

## Auth model

The API key (`hbk_...`) is sent as `Authorization: Bearer` on every request. Keys have scoped abilities:
`organization:read`, `devices:read`, `persons:read`, `persons:write`, `biometrics:read`, `biometrics:write`, `events:read`, `webhooks:manage`.

`ForbiddenException` (403) = the key lacks the ability required by that endpoint.

## Return conventions

- All methods return plain PHP arrays (decoded JSON).
- Single resource responses: `{ "data": { ... } }`
- List responses: `{ "data": [...], "meta": { "next_cursor": "..." } }` (cursor-paginated)
- Async responses (202): method returns a `PendingOperation` object, not an array.

## Full method reference

### Organization
```php
HikBridge::organization()->get(): array
```

### Devices
```php
HikBridge::devices()->list(array $params = []): array
HikBridge::devices()->get(int $deviceId): array
HikBridge::devices()->create(array $data): array|PendingOperation
  // Registers a new device from scratch — no prior dashboard provisioning needed.
  // Required: ip, username, password. Optional: name, port, enrollment_mode.
  // Without inline `isup` details       → registered ISAPI-only    → 201 → array
  // With inline `isup` details          → bridge validates ISUP too → may be 202 → PendingOperation
HikBridge::devices()->update(int $deviceId, array $data): array
  // Rename, change ISAPI credentials, or switch enrollment_mode ('direct' | 'isup').
HikBridge::devices()->configureIsup(int $deviceId, array $data): array
  // Push/replace ISUP tunnel config: server_host, registration_port, http_api_port, device_id, isup_key.
  // Required so a device behind NAT (or reached from a web app rather than an on-site
  // LAN station) can be enrolled at all — 'direct' ISAPI only works on the local network.
HikBridge::devices()->delete(int $deviceId): PendingOperation     // always async
```

Devices:write ability required for create/update/configureIsup/delete; devices:read covers list/get.

### Persons
```php
HikBridge::persons()->list(array $params = []): array
HikBridge::persons()->get(int $personId): array
HikBridge::persons()->create(array $data): array|PendingOperation
  // Without device_id → fans out to all active devices → 202 → PendingOperation
  // With device_id    → syncs to that device only → 201 → array
HikBridge::persons()->update(int $personId, array $data): array
HikBridge::persons()->delete(int $personId): PendingOperation     // always async
HikBridge::persons()->deleteFromDevice(int $personId, int $deviceId): array
```

Person fields: `person_code` (required, unique), `first_name`, `last_name`, `status` (`active`|`inactive`).

### Biometrics
```php
HikBridge::biometrics(int $personId)->summary(int $deviceId): array
HikBridge::biometrics(int $personId)->uploadFace(int $deviceId, string $base64Image): array
HikBridge::biometrics(int $personId)->captureFace(int $deviceId): array
HikBridge::biometrics(int $personId)->faceCaptureProgress(int $deviceId): array
HikBridge::biometrics(int $personId)->deleteFace(int $deviceId): array
HikBridge::biometrics(int $personId)->storeFingerprint(int $deviceId, int $fingerIndex, ?string $template = null): array
HikBridge::biometrics(int $personId)->captureFingerprint(int $deviceId, int $fingerIndex = 0): array
HikBridge::biometrics(int $personId)->fingerprintCaptureProgress(int $deviceId): array
HikBridge::biometrics(int $personId)->deleteFingerprint(int $deviceId, int $fingerIndex): array
HikBridge::biometrics(int $personId)->addAccessCard(int $deviceId, string $cardNo, int $cardType = 1): array
HikBridge::biometrics(int $personId)->deleteAccessCard(int $deviceId, string $cardNo): array
```

### Events
```php
HikBridge::events()->list(array $params = []): array
  // params: per_page, cursor, from (ISO8601), to (ISO8601), person_code, event_type, device_id
HikBridge::events()->triggerSync(?string $from = null, ?string $to = null): array
```

### Webhooks
```php
HikBridge::webhooks()->list(): array
HikBridge::webhooks()->get(int $webhookId): array
HikBridge::webhooks()->create(array $data): array   // signing secret returned exactly once
HikBridge::webhooks()->update(int $webhookId, array $data): array
HikBridge::webhooks()->delete(int $webhookId): void
HikBridge::webhooks()->sendTestPing(int $webhookId): array
HikBridge::webhooks()->deliveries(int $webhookId): array
```

Supported event types: `access.event`, `person.synced`, `*`. The `secret` (`whsec_...`) from `create()` is shown once — it cannot be retrieved again.

### Operations (async polling)
```php
HikBridge::operations()->get(string $operationId): array
  // data.status: pending | completed | failed
  // data.devices[]: per-device progress
```

## PendingOperation

Returned by any 202 response.

```php
$op->operationId                                               // string
$op->data                                                      // array
$op->isPending()                                               // always true
$op->waitUntilDone(int $timeout = 60, int $interval = 2): array
  // Polls GET /v1/operations/{id} every $interval seconds.
  // Returns final operation array on 'completed'.
  // Throws HikBridgeException on 'failed' or timeout.
```

## Exceptions

All extend `Nugsoft\HikBridge\Exceptions\HikBridgeException`.

| Exception class | HTTP status | Notes |
|---|---|---|
| `AuthenticationException` | 401 | Invalid / missing API key |
| `ForbiddenException` | 403 | Key lacks required ability |
| `NotFoundException` | 404 | Resource not found |
| `ValidationException` | 422 | Call `->errors()` for field-level messages |
| `RateLimitException` | 429 | Too many requests |
| `ServerException` | 5xx | HikBridge server error |

## Testing guidelines

- Use `Http::fake()` — the SDK wraps Laravel's `Http` facade, so no custom mock layer is needed.
- Always assert `Http::assertSentCount()` to verify exactly one (or N) requests were made.
- For async tests, fake both the initial 202 endpoint and the polling `*/v1/operations/*` endpoint.

```php
// Sync example
Http::fake([
    '*/v1/persons/57*' => Http::response(['data' => ['id' => 57, 'first_name' => 'Amina']], 200),
]);
$person = HikBridge::persons()->get(57);
expect($person['data']['id'])->toBe(57);
Http::assertSentCount(1);

// Async example
Http::fake([
    '*/v1/persons'         => Http::response(['operation_id' => 'op_1', 'data' => []], 202),
    '*/v1/operations/op_1' => Http::response(['data' => ['status' => 'completed']], 200),
]);
$op = HikBridge::persons()->create(['person_code' => 'EMP001', 'first_name' => 'Amina']);
$result = $op->waitUntilDone(timeout: 10, interval: 0);
expect($result['data']['status'])->toBe('completed');
```

## Source layout

```
src/
  HikBridgeClient.php          ← HTTP transport + exception mapping
  HikBridgeManager.php         ← facade target, creates resource objects
  HikBridgeServiceProvider.php ← auto-discovered by Laravel
  PendingOperation.php         ← wraps 202 responses, provides waitUntilDone()
  Facades/HikBridge.php
  Resources/
    OrganizationResource.php
    DeviceResource.php
    PersonResource.php         ← sync vs async determined by response status
    BiometricResource.php
    EventResource.php
    WebhookResource.php
    OperationResource.php
  Exceptions/
    HikBridgeException.php     ← base
    AuthenticationException.php
    ForbiddenException.php
    NotFoundException.php
    ValidationException.php    ← has ->errors()
    RateLimitException.php
    ServerException.php
config/hikbridge.php
tests/
  Pest.php
  TestCase.php
  HikBridgeClientTest.php
  PendingOperationTest.php
  Resources/
    PersonResourceTest.php
    WebhookResourceTest.php
```

## How to add a new endpoint

1. Find the resource class in `src/Resources/` for that group.
2. Add a method calling `$this->client->get/post/put/delete($path, $params)`.
3. If the endpoint may return 202, use `postRaw()`/`deleteRaw()`, check `$response->status() === 202`, and return `new PendingOperation($response->json())`.
4. Write a Pest test using `Http::fake()`.
