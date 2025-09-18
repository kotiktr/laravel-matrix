<?php

return [
    'homeserver_url' => env('MATRIX_HOMESERVER_URL', env('MATRIX_HOMESERVER', null)),
    'bot_username' => env('MATRIX_BOT_USERNAME', null),
    'bot_password' => env('MATRIX_BOT_PASSWORD', null),
    'access_token' => env('MATRIX_ACCESS_TOKEN', null),
    'token_ttl_hours' => env('MATRIX_TOKEN_TTL_HOURS', 6),
];
