<?php

namespace Kotiktr\LaravelMatrix;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

class Client
{
    protected array $config;
    protected LoggerInterface $logger;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->logger = app()->make(LoggerInterface::class);
    }

    protected function homeserver(): string
    {
        return rtrim($this->config['homeserver_url'] ?? config('services.matrix.homeserver_url', ''), '/');
    }

    protected function cacheKey(): string
    {
        return 'matrix_access_token_' . md5($this->config['bot_username'] ?? config('services.matrix.bot_username', ''));
    }

    public function accessToken(): ?string
    {
        $key = $this->cacheKey();
        $token = Cache::get($key);
        if ($token) {
            return $token;
        }

        $token = $this->login();
        if ($token) {
            Cache::put($key, $token, now()->addHours(6));
        }

        return $token;
    }

    public function login(): ?string
    {
        $username = $this->config['bot_username'] ?? config('services.matrix.bot_username');
        $password = $this->config['bot_password'] ?? config('services.matrix.bot_password');
        $homeserver = $this->homeserver();

        if (!$username || !$password || !$homeserver) {
            $this->logger->error('Matrix client config missing');
            return null;
        }

        $resp = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post($homeserver . '/_matrix/client/v3/login', [
                'type' => 'm.login.password',
                'identifier' => [
                    'type' => 'm.id.user',
                    'user' => $username,
                ],
                'password' => $password,
            ]);

        if ($resp->failed()) {
            $this->logger->error('Matrix login failed', ['status' => $resp->status(), 'body' => $resp->body()]);
            return null;
        }

        $json = $resp->json();
        return $json['access_token'] ?? null;
    }

    public function sendMessage(string $roomId, string $message): bool
    {
        $token = $this->accessToken();
        if (!$token) {
            return false;
        }

    $txnId = Str::uuid()->toString();
    // Room IDs may contain characters like '!' and ':' which must be URL-encoded when used in a path segment
    $encodedRoom = rawurlencode($roomId);
    $url = $this->homeserver() . "/_matrix/client/v3/rooms/{$encodedRoom}/send/m.room.message/{$txnId}";

        $payload = [
            'msgtype' => 'm.text',
            'body' => $message,
        ];

        // Log the outgoing request details for debugging (token partially masked)
        try {
            $this->logger->debug('Matrix send request', [
                'url' => $url,
                'txn_id' => $txnId,
                'payload' => $payload,
                'token_masked' => substr($token, 0, 8) . '...'
            ]);
        } catch (\Throwable $e) {
            // ignore logger failures
        }

        $resp = Http::withToken($token)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->put($url, $payload);

        // Log the raw response for diagnosis
        try {
            $this->logger->debug('Matrix send response', [
                'status' => $resp->status(),
                'headers' => $resp->headers(),
                'body' => $resp->body(),
            ]);
        } catch (\Throwable $e) {
            // ignore logger failures
        }

        if ($resp->successful()) {
            return true;
        }

        // Handle 401 -> clear cache and retry once
        if ($resp->status() === 401) {
            Cache::forget($this->cacheKey());
            $token = $this->accessToken();
            if ($token) {
                // retry with a fresh token
                $this->logger->debug('Matrix send retrying after 401', ['url' => $url, 'txn_id' => $txnId]);

                $resp = Http::withToken($token)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->put($url, $payload);

                try {
                    $this->logger->debug('Matrix send retry response', [
                        'status' => $resp->status(),
                        'headers' => $resp->headers(),
                        'body' => $resp->body(),
                    ]);
                } catch (\Throwable $e) {
                    // ignore logger failures
                }

                return $resp->successful();
            }
        }
        // include request details in the error log to help diagnose servers that return M_UNRECOGNIZED
        $this->logger->error('Matrix send message failed', [
            'status' => $resp->status(),
            'request_url' => $url,
            'payload' => $payload,
            'headers' => $resp->headers(),
            'body' => $resp->body(),
        ]);
        return false;
    }
}
