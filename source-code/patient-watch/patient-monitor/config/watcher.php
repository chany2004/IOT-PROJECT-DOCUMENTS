<?php

return [
    // SMS is retained for existing records, but never used as a fallback.
    'channel' => env('WATCHER_NOTIFICATION_CHANNEL', 'telegram'),
    'telegram_enabled' => env('TELEGRAM_ENABLED', true),
    'telegram_poll_timeout' => max(0, min(20, (int) env('TELEGRAM_POLL_TIMEOUT', 5))),
    'telegram_bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    'telegram_bot_username' => env('TELEGRAM_BOT_USERNAME', ''),
];
