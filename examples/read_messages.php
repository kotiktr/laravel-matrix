<?php
// Basit, bağımsız Matrix long-poll okuyucu
// PHP 8.1+ ile çalışır. Bu dosya Laravel projesiyle entegre değildir — salt CLI bir araçtır.

// Kullanım: php read_messages.php
// Öncelikle proje kökündeki .env dosyasını yüklemeye çalışır (opsiyonel).

function loadDotEnv(string $path): void
{
    if (!is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        // KEY=VALUE or KEY="VALUE"
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);
        // remove surrounding quotes
        if ((str_starts_with($val, '"') && str_ends_with($val, '"')) || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
            $val = substr($val, 1, -1);
        }
        // ignore empty keys
        if ($key === '') continue;
        // set both env and superglobals for this script
        putenv("{$key}={$val}");
        $_ENV[$key] = $val;
        $_SERVER[$key] = $val;
    }
}

// try to load project .env (one level up from this script)
$projectEnv = realpath(__DIR__ . '/../.env');
if ($projectEnv) {
    loadDotEnv($projectEnv);
}

$homeserver = getenv('MATRIX_HOMESERVER_URL') ?: getenv('MATRIX_HOMESERVER') ?: getenv('MATRIX_HOST') ?: 'https://matrix.example.com';
$user = getenv('MATRIX_BOT_USERNAME') ?: getenv('MATRIX_BOT_USER') ?: getenv('MATRIX_USER') ?: '@fnbot:matrix.example.com';
$password = getenv('MATRIX_BOT_PASSWORD') ?: getenv('MATRIX_PASSWORD') ?: '';
$roomId = getenv('MATRIX_DEFAULT_ROOM_ID') ?: getenv('MATRIX_ROOM') ?: '!JhwPnLoBosjmCQOqVZ:matrix.example.com';
$timeoutSeconds = (int)(getenv('MATRIX_TIMEOUT') ?: 30);

function http_post($url, $data, $headers = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(['Content-Type: application/json'], $headers));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $result = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $result];
}

function http_get($url, $headers = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $result = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $result];
}

// 1) Login
[$status, $body] = http_post($homeserver . '/_matrix/client/v3/login', [
    'type' => 'm.login.password',
    'user' => $user,
    'password' => $password
]);
if ($status !== 200) {
    fwrite(STDERR, "Login failed: HTTP $status -- $body\n");
    exit(1);
}
$data = json_decode($body, true);
$accessToken = $data['access_token'] ?? null;
if (!$accessToken) {
    fwrite(STDERR, "Login response missing access_token\n");
    exit(1);
}
fwrite(STDOUT, "Logged in, access token obtained.\n");

// 2) Keep a since token (optional) — we will long-poll /sync
$since = null;

// runtime dedupe to avoid replying same event multiple times during this run
$processedEvents = [];

// support --once flag for a single sync iteration (useful for testing)
$runOnce = in_array('--once', $argv ?? []);

while (true) {
    $params = ['timeout' => $timeoutSeconds * 1000];
    if ($since) $params['since'] = $since;
    $qs = http_build_query($params);
    $url = rtrim($homeserver, '/') . '/_matrix/client/v3/sync?' . $qs;
    fwrite(STDOUT, "Polling sync... since=" . ($since ?: 'null') . "\n");
    [$status, $body] = http_get($url, ['Authorization: Bearer ' . $accessToken]);
    if ($status !== 200) {
        fwrite(STDERR, "Sync failed: HTTP $status -- $body\n");
        sleep(5);
        continue;
    }
    $data = json_decode($body, true);
    if (!$data) {
        fwrite(STDERR, "Invalid JSON from sync\n");
        sleep(1);
        continue;
    }
    $since = $data['next_batch'] ?? $since;

    // inspect joined rooms
    $rooms = $data['rooms']['join'] ?? [];
    foreach ($rooms as $rid => $roomData) {
        if (!isset($roomData['timeline']['events'])) continue;
        foreach ($roomData['timeline']['events'] as $event) {
            if (($event['type'] ?? '') !== 'm.room.message') continue;
            $eventId = $event['event_id'] ?? null;
            $body = $event['content']['body'] ?? '';
            $sender = $event['sender'] ?? '';

            // skip if already processed in this runtime
            if ($eventId && in_array($eventId, $processedEvents, true)) {
                continue;
            }

            // print only plaintext messages
            if (!(is_string($body) && strlen(trim($body)) > 0)) {
                // mark as processed to avoid repeated work
                if ($eventId) $processedEvents[] = $eventId;
                continue;
            }

            $ts = $event['origin_server_ts'] ?? time()*1000;
            $dt = date('c', (int)($ts/1000));
            echo "[$dt] {$rid} <{$sender}>: {$body}\n";

            // skip messages sent by the bot itself to avoid loops
            if ($sender === $user) {
                if ($eventId) $processedEvents[] = $eventId;
                continue;
            }

            // skip JSON-only bodies (eg. {"ok":true})
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
                if ($eventId) $processedEvents[] = $eventId;
                continue;
            }

            // prepare reply and send
            $reply = $body . ' devamina alindi';
            fwrite(STDOUT, "Sending reply to {$rid}: {$reply}\n");
            // send with retry and token-refresh on 401
            $attempts = 0;
            $sent = false;
            while ($attempts < 3 && !$sent) {
                $attempts++;
                [$s, $b] = sendMessage($homeserver, $rid, $reply, $accessToken);
                if ($s === 200 || $s === 201) {
                    fwrite(STDOUT, "Reply sent (attempt {$attempts}).\n");
                    $sent = true;
                    break;
                }
                // if unauthorized, try to re-login and retry
                if ($s === 401) {
                    fwrite(STDERR, "Access token expired, re-login...\n");
                    [$ls, $lb] = http_post($homeserver . '/_matrix/client/v3/login', [
                        'type' => 'm.login.password',
                        'user' => $user,
                        'password' => $password
                    ]);
                    if ($ls === 200) {
                        $ldata = json_decode($lb, true);
                        $accessToken = $ldata['access_token'] ?? $accessToken;
                        continue; // retry send
                    }
                }
                fwrite(STDERR, "sendMessage failed (HTTP {$s}): {$b}\n");
                sleep(1);
            }

            if ($eventId) $processedEvents[] = $eventId;
        }
    }

    // small pause to avoid busy-loop (optional)
    usleep(200000);

    if ($runOnce) {
        fwrite(STDOUT, "--once mode: exiting after single sync.\n");
        break;
    }
}


// helper: send plain text message to room (m.text)
function sendMessage(string $homeserver, string $roomId, string $message, string $accessToken): array
{
    $txn = uniqid('txn_', true);
    $urlRoom = rawurlencode($roomId);
    // matrix spec: PUT /_matrix/client/v3/rooms/{roomId}/send/m.room.message/{txnId}
    $url = rtrim($homeserver, '/') . "/_matrix/client/v3/rooms/{$roomId}/send/m.room.message/{$txn}";

    $payload = [
        'msgtype' => 'm.text',
        'body' => $message,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $result = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $result];
}
