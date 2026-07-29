<?php

namespace Nugsoft\HikBridge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Nugsoft\HikBridge\Exceptions\AuthenticationException;
use Nugsoft\HikBridge\Exceptions\ForbiddenException;
use Nugsoft\HikBridge\Exceptions\HikBridgeException;
use Nugsoft\HikBridge\Exceptions\NotFoundException;
use Nugsoft\HikBridge\Exceptions\RateLimitException;
use Nugsoft\HikBridge\Exceptions\ServerException;
use Nugsoft\HikBridge\Exceptions\ValidationException;

class HikBridgeClient
{
    public function __construct(private readonly array $config) {}

    public function get(string $path, array $query = []): array
    {
        $response = $this->pending()
            ->get($this->url($path), $query);

        return $this->decode($response);
    }

    public function post(string $path, array $body = []): array
    {
        $response = $this->pending()
            ->post($this->url($path), $body);

        return $this->decode($response);
    }

    public function put(string $path, array $body = []): array
    {
        $response = $this->pending()
            ->put($this->url($path), $body);

        return $this->decode($response);
    }

    public function patch(string $path, array $body = []): array
    {
        $response = $this->pending()
            ->patch($this->url($path), $body);

        return $this->decode($response);
    }

    public function delete(string $path): array
    {
        $response = $this->pending()
            ->delete($this->url($path));

        return $this->decode($response);
    }

    public function deleteWithBody(string $path, array $body = []): array
    {
        $response = $this->pending()
            ->withBody(json_encode($body), 'application/json')
            ->delete($this->url($path));

        return $this->decode($response);
    }

    // Returns the raw Response so callers can inspect the status code themselves.
    public function postRaw(string $path, array $body = []): Response
    {
        return $this->pending()->post($this->url($path), $body);
    }

    public function deleteRaw(string $path): Response
    {
        return $this->pending()->delete($this->url($path));
    }

    private function pending()
    {
        $retry = $this->config['retry'] ?? ['times' => 3, 'sleep' => 100];
        $times = (int) ($retry['times'] ?? 3);

        $pending = Http::withToken($this->config['api_key'])
            ->acceptJson()
            ->timeout($this->config['timeout'] ?? 30);

        if ($times > 0) {
            // Retry only on connection-level exceptions, not on HTTP error
            // responses, and never let the retry helper throw — error responses
            // must flow through to decode() so they map to typed exceptions.
            $pending = $pending->retry(
                $times,
                (int) ($retry['sleep'] ?? 100),
                fn ($exception) => $exception instanceof ConnectionException,
                throw: false,
            );
        }

        return $pending;
    }

    private function url(string $path): string
    {
        return rtrim($this->config['base_url'], '/') . '/' . ltrim($path, '/');
    }

    /**
     * Decode a response already fetched via postRaw()/deleteRaw() — used by resources that
     * branch on status code themselves (e.g. 202 → PendingOperation) before falling through to
     * ordinary decoding for every other status, so a non-2xx/202 response still raises the same
     * typed exception get()/post()/etc. would raise, instead of being returned as if it were data.
     */
    public function decode(Response $response): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $status  = $response->status();
        $message = $response->json('message') ?? $response->body();

        throw match (true) {
            $status === 401 => new AuthenticationException($message, $status),
            $status === 403 => new ForbiddenException($message, $status),
            $status === 404 => new NotFoundException($message, $status),
            $status === 422 => new ValidationException($message, $response->json('errors') ?? [], $status),
            $status === 429 => new RateLimitException($message, $status, ($h = $response->header('Retry-After')) !== '' ? (int) $h : null),
            $status >= 500  => new ServerException($message, $status),
            default         => new HikBridgeException($message, $status),
        };
    }
}
