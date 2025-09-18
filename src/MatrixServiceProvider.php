<?php

namespace Kotiktr\LaravelMatrix;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MatrixServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/matrix.php' => config_path('matrix.php'),
        ], 'config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\MatrixSyncCommand::class,
            ]);
        }
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/matrix.php', 'matrix');

        $this->app->singleton('matrix.client', function ($app) {
            return new Client($app['config']->get('matrix'));
        });

        $this->app->singleton('matrix.bot', function ($app) {
            return new Bot\MatrixBot($app->make('matrix.client'));
        });

        // Attach a default handler when the bot is resolved. This makes the
        // existing matrix:sync command act as a low-resource daemon that
        // replies to plaintext messages by appending ' devamina alindi'.
        $this->app->afterResolving('matrix.bot', function ($bot) {
            // Use a generic handler to filter message events, dedupe and reply.
            $bot->on(function (array $event) use ($bot) {
                $type = $event['type'] ?? null;
                if ($type !== 'm.room.message') {
                    return;
                }

                $content = $event['content'] ?? [];
                $body = $content['body'] ?? null;
                if (!$body || !is_string($body)) {
                    return;
                }

                $eventId = $event['event_id'] ?? $event['unsigned']['event_id'] ?? null;
                if (!$eventId) {
                    return;
                }

                $cacheKey = 'matrix_event_' . $eventId;
                if (Cache::has($cacheKey)) {
                    Log::info('MatrixServiceProvider: skipping already processed event', ['event_id' => $eventId]);
                    return;
                }

                $sender = $event['sender'] ?? null;
                $botUsername = config('matrix.bot_username') ?? config('services.matrix.bot_username');
                if ($sender && $botUsername && $sender === $botUsername) {
                    // mark as processed to avoid loops
                    Cache::put($cacheKey, true, now()->addDay());
                    Log::info('MatrixServiceProvider: skipping bot message', ['sender' => $sender, 'event_id' => $eventId]);
                    return;
                }

                $roomId = $event['room_id'] ?? $event['roomId'] ?? null;
                if (!$roomId) {
                    return;
                }

                // Reply by appending the required text. Keep reply simple.
                $reply = $body . ' devamina alindi';

                try {
                    $sent = $bot->send($roomId, $reply);
                    if ($sent) {
                        Log::info('MatrixServiceProvider: replied to event', ['roomId' => $roomId, 'reply' => $reply, 'event_id' => $eventId]);
                    } else {
                        Log::error('MatrixServiceProvider: send returned false', ['roomId' => $roomId, 'event_id' => $eventId]);
                    }
                } catch (\Throwable $e) {
                    Log::error('MatrixServiceProvider: reply failed', ['error' => $e->getMessage(), 'event' => $event]);
                }

                // mark processed to avoid duplicate replies
                Cache::put($cacheKey, true, now()->addDay());
            });
        });
    }
}
