<?php

declare(strict_types=1);

/** Learning content (Sprint 4). Limits keep lessons light on learners' data. */
return [
    'disk' => env('CONTENT_DISK', 'public'),
    'limits' => [
        // kind => [max KB, allowed MIME types]
        'image' => [400, ['image/jpeg', 'image/png', 'image/webp']],
        'audio' => [1500, ['audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/ogg', 'audio/webm']],
        'video' => [25000, ['video/mp4', 'video/webm']],
    ],
];
