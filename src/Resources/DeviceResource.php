<?php

namespace Nugsoft\HikBridge\Resources;

use Nugsoft\HikBridge\HikBridgeClient;
use Nugsoft\HikBridge\PendingOperation;

class DeviceResource
{
    public function __construct(private readonly HikBridgeClient $client) {}

    public function list(array $params = []): array
    {
        return $this->client->get('/v1/devices', $params);
    }

    public function get(int $deviceId): array
    {
        return $this->client->get("/v1/devices/{$deviceId}");
    }

    /**
     * Register a new device on the business behind the API key — no prior manual
     * provisioning on the HikBridge dashboard required.
     *
     * Provide the device's local (ISAPI) connection details here (`ip`, `port`,
     * `username`, `password`). A device with only ISAPI details is reachable from an
     * on-site station; call configureIsup() afterwards to also reach it remotely
     * through the ISUP tunnel (required for web-app-triggered enrollment when the
     * device is behind NAT or off the local network).
     *
     * - Without `isup` in $data: the device is created ISAPI-only → 201 → array
     * - With `isup` in $data: the bridge also validates the ISUP registration before
     *   confirming, which may return 202 → PendingOperation
     *
     * @param  array{
     *     name?: string,
     *     ip: string,
     *     port?: int,
     *     username: string,
     *     password: string,
     *     enrollment_mode?: string,
     *     isup?: array{server_host: string, registration_port?: int, http_api_port?: int, device_id: string, isup_key: string},
     * }  $data
     */
    public function create(array $data): array|PendingOperation
    {
        $response = $this->client->postRaw('/v1/devices', $data);

        if ($response->status() === 202) {
            $body = $response->json();

            return new PendingOperation(
                operationId: $body['operation_id'],
                data: $body['data'] ?? [],
                client: $this->client,
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Update a device's own settings — its display name, ISAPI connection details, or
     * which transport (`direct` ISAPI vs `isup`) is used by default for enrollment.
     */
    public function update(int $deviceId, array $data): array
    {
        return $this->client->patch("/v1/devices/{$deviceId}", $data);
    }

    /**
     * Push (or replace) the ISUP tunnel configuration for an already-registered device —
     * the "save the device first, then configure ISUP" step for a device that's behind
     * NAT or otherwise unreachable directly from wherever this call runs (e.g. a web app,
     * as opposed to an on-site enrollment station with LAN access).
     *
     * @param  array{server_host: string, registration_port?: int, http_api_port?: int, device_id: string, isup_key: string}  $data
     */
    public function configureIsup(int $deviceId, array $data): array
    {
        return $this->client->put("/v1/devices/{$deviceId}/isup", $data);
    }

    /**
     * Remove a device from the business. Always async — unenrolling it can mean
     * clearing biometrics tied to it across every synced person, not just deleting a row.
     */
    public function delete(int $deviceId): PendingOperation
    {
        $response = $this->client->deleteRaw("/v1/devices/{$deviceId}");
        $body = $response->json();

        return new PendingOperation(
            operationId: $body['operation_id'],
            data: $body['data'] ?? [],
            client: $this->client,
        );
    }
}
