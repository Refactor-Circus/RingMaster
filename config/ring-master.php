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

    /*
    |--------------------------------------------------------------------------
    | Claims
    |--------------------------------------------------------------------------
    |
    | A Claude session that claims a task holds it for lease_seconds and
    | renews the lease with each heartbeat. A lease that runs out before a
    | spec exists puts the task back in the queue, so a session that dies
    | never strands work. Once the task has a spec, its status follows the
    | spec instead.
    |
    */

    'claims' => [
        'lease_seconds' => (int) env('RING_MASTER_LEASE_SECONDS', 900),
    ],

];
