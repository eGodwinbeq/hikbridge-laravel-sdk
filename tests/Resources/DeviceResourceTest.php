<?php

use Illuminate\Support\Facades\Http;
use Nugsoft\HikBridge\Facades\HikBridge;
use Nugsoft\HikBridge\PendingOperation;

it('lists devices and forwards query params', function () {
    Http::fake(['*/v1/devices*' => Http::response(['data' => []], 200)]);

    $result = HikBridge::devices()->list(['per_page' => 10]);

    expect($result)->toBeArray();
    Http::assertSent(fn ($req) => str_contains($req->url(), 'per_page=10'));
});

it('gets a single device by id', function () {
    Http::fake(['*/v1/devices/35*' => Http::response(['data' => ['id' => 35]], 200)]);

    $result = HikBridge::devices()->get(35);

    expect($result['data']['id'])->toBe(35);
});

it('creates a device with ISAPI details and returns an array on sync (201)', function () {
    Http::fake(['*/v1/devices' => Http::response(['data' => ['id' => 35, 'name' => 'Main Entrance']], 201)]);

    $result = HikBridge::devices()->create([
        'name' => 'Main Entrance',
        'ip' => '192.168.1.64',
        'port' => 80,
        'username' => 'admin',
        'password' => 'secret',
    ]);

    expect($result)->toBeArray()
        ->and($result['data']['id'])->toBe(35);

    Http::assertSent(function ($req) {
        return $req->method() === 'POST'
            && $req['ip'] === '192.168.1.64'
            && $req['username'] === 'admin';
    });
});

it('returns a PendingOperation when creating a device with ISUP details (async 202)', function () {
    Http::fake(['*/v1/devices' => Http::response([
        'operation_id' => 'op_dev123',
        'data' => ['id' => 36],
    ], 202)]);

    $result = HikBridge::devices()->create([
        'name' => 'Back Gate',
        'ip' => '192.168.1.65',
        'username' => 'admin',
        'password' => 'secret',
        'isup' => [
            'server_host' => '161.97.104.204',
            'registration_port' => 7660,
            'http_api_port' => 8089,
            'device_id' => 'TEST01',
            'isup_key' => 'isup_secret',
        ],
    ]);

    expect($result)
        ->toBeInstanceOf(PendingOperation::class)
        ->and($result->operationId)->toBe('op_dev123');
});

it('sends a PATCH request on update', function () {
    Http::fake(['*/v1/devices/35' => Http::response(['data' => ['id' => 35]], 200)]);

    HikBridge::devices()->update(35, ['name' => 'Front Gate']);

    Http::assertSent(fn ($req) => $req->method() === 'PATCH');
});

it('pushes ISUP configuration to an existing device via PUT', function () {
    Http::fake(['*/v1/devices/35/isup' => Http::response(['data' => ['id' => 35, 'integration_mode' => 'isup']], 200)]);

    $result = HikBridge::devices()->configureIsup(35, [
        'server_host' => '161.97.104.204',
        'registration_port' => 7660,
        'http_api_port' => 8089,
        'device_id' => 'TEST01',
        'isup_key' => 'isup_secret',
    ]);

    expect($result['data']['integration_mode'])->toBe('isup');
    Http::assertSent(fn ($req) => $req->method() === 'PUT' && $req['device_id'] === 'TEST01');
});

it('returns a PendingOperation on delete (async unenrollment fan-out)', function () {
    Http::fake(['*/v1/devices/35' => Http::response([
        'operation_id' => 'op_deldev123',
        'data' => ['id' => 35],
    ], 202)]);

    $result = HikBridge::devices()->delete(35);

    expect($result)
        ->toBeInstanceOf(PendingOperation::class)
        ->and($result->operationId)->toBe('op_deldev123');
});
