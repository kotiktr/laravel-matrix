# Matrix Bot — Integration Guide

This document explains how to use the standalone Matrix bot included in this repository and how to integrate a similar bot into a Laravel project. The README is written for another developer who wants to reuse the bot. All private, project-specific values in examples are replaced with generic placeholders.

Important note (READ THIS FIRST)
- Do NOT send direct (one-to-one) messages to the bot from a Matrix client. Instead, create a room, turn OFF encryption for that room, and invite the bot into that room. The bot requires plaintext messages in the room timeline to read and reply. If the room is encrypted the bot will not see plaintext and cannot respond. This is critical.

Table of contents
- Quick overview
- What is included
- Prerequisites
- Configuration (environment variables)
- How to run the standalone bot (one-shot and continuous)
- How to run the bot as a systemd service (production)
- How to integrate into a Laravel project (detailed)
- Example: make the bot append " received" and send back
- Troubleshooting & tips

Quick overview
---------------
The repository contains a small standalone CLI bot that logs into a Matrix homeserver, long-polls `/sync`, and reacts to `m.room.message` events. It is intentionally small and framework-agnostic so you can run it independently or copy the logic into a Laravel app.

What is included
---------------
- `standalone_matrix_bot/read_messages.php` — the standalone CLI bot (login, /sync, reply logic).
- `standalone_matrix_bot/README.md` — short usage notes.
- `standalone_matrix_bot/INTEGRATION_README.md` — (this file) full integration guide.
- `deploy/systemd/run_matrix_bot.sh` and `deploy/systemd/matrix-bot.service` — example systemd wrapper and unit for running the bot continuously.

Prerequisites
-------------
- PHP 8.1+ with `curl` support on the machine that runs the bot.
- A Matrix homeserver URL and a bot account with username and password.
- The bot account must be invited to the room you want it to monitor.
- If you plan to run the bot as a service: systemd (Linux) and appropriate file permissions.

Configuration (environment variables)
------------------------------------
The standalone script reads environment variables from the project `.env` file (or you can export them in the shell). Use these names:

- `MATRIX_HOMESERVER_URL` — e.g. `https://matrix.example.com`
- `MATRIX_BOT_USERNAME` — e.g. `@mybot:example.com`
- `MATRIX_BOT_PASSWORD` — bot account password
- `MATRIX_DEFAULT_ROOM_ID` — example: `!abcdefg:example.com` (room the bot will join/watch)

Example `.env` section (replace with your values):

```
MATRIX_HOMESERVER_URL=https://matrix.example.com
MATRIX_BOT_USERNAME=@mybot:example.com
MATRIX_BOT_PASSWORD="REPLACE_ME@ssw0rd"
MATRIX_DEFAULT_ROOM_ID=!roomid123:example.com
```

Important room setup (very important)
-----------------------------------
1. In your Matrix client create a new room for the bot (public or private as you prefer).
2. In the room settings, disable end-to-end encryption for that room. The bot will not be able to read messages in E2EE rooms.
3. Invite the bot user (for example `@mybot:example.com`) to the room and make sure the bot joins.
4. Do not send one-to-one direct messages to the bot — the bot is designed to read room timelines.

How to run the standalone bot
-----------------------------
From the repository root the script loads `.env` automatically. Examples:

- One-shot test (single /sync then exit):

```bash
php standalone_matrix_bot/read_messages.php --once
```

- Continuous run:

```bash
php standalone_matrix_bot/read_messages.php
```

If you prefer to export env vars inline in your shell (careful with `!` in room ids — quote them):

```bash
MATRIX_HOMESERVER_URL='https://matrix.example.com' \
MATRIX_BOT_USERNAME='@mybot:example.com' \
MATRIX_BOT_PASSWORD='S3cret' \
MATRIX_DEFAULT_ROOM_ID='!roomid123:example.com' \
php standalone_matrix_bot/read_messages.php --once
```

How to run as systemd service (production)
-----------------------------------------
Files provided:

- `deploy/systemd/run_matrix_bot.sh` — wrapper script that sources `.env` and runs the PHP bot
- `deploy/systemd/matrix-bot.service` — example unit

Install steps (example):

```bash
sudo cp deploy/systemd/matrix-bot.service /etc/systemd/system/matrix-bot.service
sudo cp deploy/systemd/run_matrix_bot.sh /usr/local/bin/run_matrix_bot.sh
sudo chmod +x /usr/local/bin/run_matrix_bot.sh
sudo systemctl daemon-reload
sudo systemctl enable --now matrix-bot.service
sudo journalctl -u matrix-bot.service -f
```

Adjust `User=` and `Group=` in the unit file according to your server's permissions. The wrapper will `source` the repository `.env` — ensure the service user can read that file.

Laravel integration (detailed)
-----------------------------
You have two main integration options:

Option A — keep the bot standalone (recommended quick path)
: - Run the standalone bot as a separate process (systemd) and let it send replies directly. This is simplest and isolates the bot from Laravel.

Option B — embed the bot inside the Laravel application (recommended for tight integration)
Steps to embed the logic in Laravel:

1) Create a small Matrix client helper
   - Add a new helper (for example `app/Helpers/MatrixClient.php`) with methods:
     - `login()` — obtain access token via `/login` and cache it
     - `sync($since, $timeout)` — call `/sync` and return JSON
     - `sendMessage($roomId, $message)` — PUT to `/rooms/{roomId}/send/m.room.message/{txnId}`

2) Add a console command to run the long-poll sync loop
   - `php artisan make:command MatrixSyncCommand`
   - Implement `handle()` to:
     - call `MatrixClient::login()` to get token
     - loop calling `MatrixClient::sync()` with `timeout` (ms)
     - iterate `rooms.join`/`timeline.events` and dispatch events to handlers (see next step)

3) Add a small Bot service / handler dispatcher
   - Create `app/Services/MatrixBot.php` that can register handlers (e.g. `on('m.room.message', $callable)`)
   - On every sync event call the registered handler(s) with the event payload

4) Register a default handler that replies to messages
   - Example snippet (put in a service provider or in `MatrixSyncCommand` init):

```php
// Require: MatrixClient and MatrixBot are implemented
$bot->on('m.room.message', function(array $event) use ($bot) {
    // ignore encrypted events
    if (!isset($event['content']['body'])) return;

    $sender = $event['sender'] ?? '';
    // avoid replying to bot's own messages (use your configured bot username)
    if ($sender === config('services.matrix.bot_username')) return;

    $body = $event['content']['body'];

    // skip JSON-only bodies
    $decoded = json_decode($body, true);
    if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) return;

    $roomId = $event['room_id'];
    $reply = $body . ' received'; // example: append " received"
    $bot->send($roomId, $reply); // or MatrixClient::sendMessage($roomId, $reply)
});
```

5) Run the command under a process supervisor
   - For testing: `php artisan matrix:sync --timeout=30000`
   - For production: create a systemd unit that runs `php /path/to/artisan matrix:sync` (similar to the standalone setup).

Example: change suffix to " received"
------------------------------------
If you want the example integration to add ` received` instead of the Turkish phrase used in the repository, change the reply construction to:

```php
$reply = $body . ' received';
```

Then call your send function (for example `$bot->send($roomId, $reply)` or `MatrixClient::sendMessage(...)`).

Security & best practices
-------------------------
- Do not store production secrets in a repository. Use a secret manager or protect the `.env` file.
- Prefer running the bot as a separate service in production so a bug in the bot cannot crash the web app.
- If you embed into Laravel, keep HTTP client timeouts and error handling robust to avoid process crashes.

Troubleshooting
---------------
- If the bot returns `M_FORBIDDEN` or `User ... not in room` errors: invite the bot to the room and ensure room join rules allow it.
- If you see only `m.room.encrypted` events in logs: the room is encrypted; turn encryption off for that room.
- If replies are echoed back multiple times after restarts: consider persistent dedupe (store processed event ids in a small SQLite DB or file).

Wrapping up
-----------
This guide provides both a quick standalone path and a detailed path for embedding the bot logic inside a Laravel app. The most critical operational requirement is the room setup: create a room, disable encryption, and invite the bot — otherwise the bot cannot read plaintext messages and will not function.
