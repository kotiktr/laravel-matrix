<?php

namespace Kotiktr\LaravelMatrix\Bot;

use Kotiktr\LaravelMatrix\Client;
use Illuminate\Support\Collection;

class MatrixBot
{
    protected Client $client;
    protected array $handlers = [];

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function on(string|callable $pattern, callable|null $handler = null): void
    {
        if (is_callable($pattern) && $handler === null) {
            $this->handlers[] = $pattern;
            return;
        }

        $this->handlers[] = [
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    public function dispatchEvent(array $event): void
    {
        // simple text message event handling
        $type = $event['type'] ?? null;
        if ($type !== 'm.room.message') {
            return;
        }

        $content = $event['content'] ?? [];
        $body = $content['body'] ?? '';
        $roomId = $event['room_id'] ?? ($event['roomId'] ?? null);

        foreach ($this->handlers as $h) {
            if (is_callable($h)) {
                call_user_func($h, $event);
                continue;
            }

            $pattern = $h['pattern'];
            $handler = $h['handler'];

            if (is_string($pattern) && preg_match($pattern, $body, $m)) {
                call_user_func($handler, $event, $m);
            } elseif ($pattern instanceof \Closure) {
                call_user_func($pattern, $event);
            }
        }
    }

    public function send(string $roomId, string $message): bool
    {
        return $this->client->sendMessage($roomId, $message);
    }
}
