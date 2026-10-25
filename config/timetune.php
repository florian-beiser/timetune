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

];
