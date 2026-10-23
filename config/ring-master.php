<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Workspaces
    |--------------------------------------------------------------------------
    |
    | Folders RingMaster scans for packages, comma-separated. A folder with a
    | ring-master.yml listing workspace.packages contributes itself and those
    | packages; a folder with its own composer.json is one project; any other
    | folder contributes each subfolder that has a composer.json. "~" expands
    | to your home folder. Folders added on the Workspaces page are kept in
    | the database; the ones listed here are always present and can only be
    | disabled there.
    |
    */

    'workspaces' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('RING_MASTER_WORKSPACES', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Scanning
    |--------------------------------------------------------------------------
    |
    | Spec folders are the source of truth; RingMaster mirrors them into the
    | database. An open board rescans when the last scan is older than
    | stale_after seconds, and the scheduler rescans every minute.
    |
    */

    'scan' => [
        'stale_after' => (int) env('RING_MASTER_SCAN_STALE_AFTER', 3),
    ],

];
