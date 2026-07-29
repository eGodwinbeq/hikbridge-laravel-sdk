<?php

namespace Nugsoft\HikBridge\Resources;

use Nugsoft\HikBridge\Exceptions\HikBridgeException;
use Nugsoft\HikBridge\HikBridgeClient;
use Nugsoft\HikBridge\PendingOperation;

class PersonResource
{
    public function __construct(private readonly HikBridgeClient $client) {}

    public function list(array $params = []): array
    {
        return $this->client->get('/v1/persons', $params);
    }

    public function get(int $personId): array
    {
        return $this->client->get("/v1/persons/{$personId}");
    }

    /**
     * Create a person.
     *
     * - Without `device_id` in $data: fans out to all active devices (202) → PendingOperation
     * - With `device_id` in $data: syncs to one device (201) → array
     */
    public function create(array $data): array|PendingOperation
    {
        $response = $this->client->postRaw('/v1/persons', $data);

        if ($response->status() === 202) {
            $body = $response->json();
            return new PendingOperation(
                operationId: $body['operation_id'],
                data: $body['data'] ?? [],
                client: $this->client,
            );
        }

        // Anything other than 202 goes through the same decoder get()/post()/etc. use, so a
        // validation or server error raises its typed exception instead of being handed back as
        // though it were a successful person payload.
        return $this->client->decode($response);
    }

    public function update(int $personId, array $data): array
    {
        return $this->client->put("/v1/persons/{$personId}", $data);
    }

    /**
     * Delete a person from all devices (always async → PendingOperation).
     */
    public function delete(int $personId): PendingOperation
    {
        $response = $this->client->deleteRaw("/v1/persons/{$personId}");

        if ($response->status() !== 202) {
            // Always expected to be async — anything else (404 if the person doesn't exist,
            // 403, a server error) is a failure, not a differently-shaped success. Route it
            // through the decoder so an error status raises its typed exception instead of
            // being built into a bogus PendingOperation with a missing operation_id.
            $this->client->decode($response);

            throw new HikBridgeException(
                "Expected an async (202) response deleting person {$personId}, got {$response->status()}.",
                $response->status(),
            );
        }

        $body = $response->json();

        return new PendingOperation(
            operationId: $body['operation_id'],
            data: $body['data'] ?? [],
            client: $this->client,
        );
    }

    /**
     * Delete a person from one specific device (sync).
     */
    public function deleteFromDevice(int $personId, int $deviceId): array
    {
        return $this->client->deleteWithBody("/v1/persons/{$personId}", [
            'device_id' => $deviceId,
        ]);
    }
}
