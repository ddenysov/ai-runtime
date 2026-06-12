<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Diary Storage Driver
    |--------------------------------------------------------------------------
    |
    | Supported: "filesystem". Point filesystem.disk at any Laravel disk,
    | including "s3", to store diary entries remotely.
    |
    */

    'driver' => env('DIARY_DRIVER', 'filesystem'),

    /*
    |--------------------------------------------------------------------------
    | Diary Timezone
    |--------------------------------------------------------------------------
    |
    | Used for "today", file names, and entry timestamps. Falls back to the
    | application timezone when null.
    |
    */

    'timezone' => env('DIARY_TIMEZONE'),

    'filesystem' => [
        'disk' => env('DIARY_DISK', 'diary'),
        'prefix' => env('DIARY_PATH_PREFIX', ''),
    ],

];
