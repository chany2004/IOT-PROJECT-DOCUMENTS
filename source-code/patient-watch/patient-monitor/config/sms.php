<?php

return [
    'enabled' => env('SMS_ENABLED', false),
    'semaphore_api_key' => env('SEMAPHORE_API_KEY', ''),
    'semaphore_sender_name' => env('SEMAPHORE_SENDER_NAME', ''),
];
