<?php

namespace Kotiktr\LaravelMatrix\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Kotiktr\LaravelMatrix\Bot\MatrixBot;
use Kotiktr\LaravelMatrix\Client;

class MatrixSyncCommand extends Command
{
    protected $signature = 'matrix:sync {--timeout=30}';
    protected $description = 'Run long-poll sync with Matrix homeserver and dispatch events.';

    protected MatrixBot $bot;
    protected Client $client;

    public function __construct(MatrixBot $bot, Client $client)
    {
        parent::__construct();
        $this->bot = $bot;
        $this->client = $client;
    }

    public function handle()
    {
        $this->info('Starting Matrix sync loop...');

        $since = Cache::get('matrix_sync_since');

        while (true) {
            $timeout = (int) $this->option('timeout');
            $homeserver = rtrim(config('matrix.homeserver_url') ?? config('services.matrix.homeserver_url', ''), '/');
            $token = $this->client->accessToken();
            if (!$token) {
                $this->error('Could not get access token');
                sleep(5);
                continue;
            }

            $url = $homeserver . '/_matrix/client/v3/sync';
            $params = [];
            if ($since) {
                $params['since'] = $since;
            }
            $params['timeout'] = $timeout * 1000;

            $resp = \Illuminate\Support\Facades\Http::withToken($token)
                ->timeout($timeout + 5)
                ->get($url, $params);

            if ($resp->failed()) {
                $this->error('Sync failed: ' . $resp->status());
                sleep(2);
                continue;
            }

            $json = $resp->json();
            $since = $json['next_batch'] ?? $since;
            Cache::put('matrix_sync_since', $since);

            $rooms = $json['rooms']['join'] ?? [];
            foreach ($rooms as $roomId => $roomData) {
                $events = $roomData['timeline']['events'] ?? [];
                foreach ($events as $event) {
                    $event['room_id'] = $roomId;
                    $this->bot->dispatchEvent($event);
                }
            }
        }
    }
}
