<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Name filter
    |--------------------------------------------------------------------------
    |
    | Display names containing one of these words are rejected when joining.
    | Moderators can additionally hide names during the game.
    |
    */

    'name_filter' => [
        'arsch', 'fick', 'fotze', 'hitler', 'hure', 'nazi', 'nutte', 'schlampe',
        'wichser', 'missgeburt', 'spast', 'sieg heil', 'kanake', 'neger',
        'schwuchtel', 'porno',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Player data is deleted this many days after the game has finished.
    |
    */

    'retention_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Activity
    |--------------------------------------------------------------------------
    |
    | Phones send a heartbeat every 45 seconds while the page is visible.
    | Without a heartbeat for this long, a player counts as inactive.
    |
    */

    'inactive_after_seconds' => 120,

    /*
    |--------------------------------------------------------------------------
    | WebSocket address for browsers
    |--------------------------------------------------------------------------
    |
    | Where phones, the screen and the moderator connect to Reverb. Empty
    | values mean "same host and port as the page", for setups where a
    | reverse proxy forwards /app to Reverb (Docker demo, Forge, Cloud).
    |
    */

    'websocket' => [
        'host' => env('REVERB_PUBLIC_HOST', env('VITE_REVERB_HOST')),
        'port' => env('REVERB_PUBLIC_PORT', env('VITE_REVERB_PORT')),
        'scheme' => env('REVERB_PUBLIC_SCHEME', env('VITE_REVERB_SCHEME')),
    ],

];
