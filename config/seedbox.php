<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MyAnonamouse session
    |--------------------------------------------------------------------------
    |
    | The mam_id cookie of a MAM session created for "dynamic seedbox" use.
    | Optional: it can also be set from the web UI. A value given here is
    | applied when the app starts and whenever the value itself changes, so
    | it never overwrites a newer cookie saved in the UI or rotated by MAM.
    | The lowercase `mam_id` name matches the official myanonamouse/seedboxapi
    | container.
    |
    */

    'mam_id' => env('MAM_ID', env('mam_id')),

    /*
    |--------------------------------------------------------------------------
    | Update interval
    |--------------------------------------------------------------------------
    |
    | Default seconds between automatic updates (INTERVAL, like the official
    | image). It can be changed in the web UI; a changed INTERVAL replaces the
    | UI value on the next start. The scheduler ticks once a minute, so the
    | effective interval is rounded up to whole minutes (minimum 60 seconds).
    | MAM answers "No change" when the IP is the same, so frequent checks are
    | harmless.
    |
    */

    'interval' => (int) env('INTERVAL', 600),

    'auto_sync' => (bool) env('MAM_AUTO_SYNC', true),

    'timeout' => (int) env('MAM_TIMEOUT', 15),

    'endpoints' => [
        'update' => env('MAM_UPDATE_URL', 'https://t.myanonamouse.net/json/dynamicSeedbox.php'),
        'ip' => env('MAM_IP_URL', 'https://t.myanonamouse.net/json/jsonIp.php'),
    ],

    // How long contact history is kept.
    'history_days' => (int) env('MAM_HISTORY_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Web UI protection
    |--------------------------------------------------------------------------
    |
    | Set both to require HTTP Basic auth for the dashboard. Recommended when
    | the port is reachable by anyone other than you: the page can replace
    | your mam_id.
    |
    */

    'ui_username' => env('UI_USERNAME'),
    'ui_password' => env('UI_PASSWORD'),

];
