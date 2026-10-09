<?php

return [
    'offline_after_seconds' => 90,
    'camera_connect_timeout_seconds' => 2,
    // A frame timeout plus one firmware fallback can take over eight seconds.
    // Evidence capture runs in the queue; the emergency alert is not delayed.
    'camera_timeout_seconds' => 12,
    'camera_capture_attempts' => 2,
    'camera_retry_delay_ms' => 150,
    'camera_queue_window_seconds' => 20,
    // Enable only after camera firmware supports authenticated outbound JPEG uploads.
    'camera_push_enabled' => env('CAMERA_PUSH_ENABLED', false),
    // Give a slow OV2640 frame and one upload retry time to complete; the
    // emergency alert itself is visible immediately and never waits here.
    'camera_push_window_seconds' => 45,
    'camera_verifier_url' => env('CAMERA_VERIFIER_URL', ''),
    'camera_verifier_key' => env('CAMERA_VERIFIER_KEY', ''),
    'camera_max_image_bytes' => 500000,
    'display_timezone' => 'Asia/Manila',
];
