<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Admin Operational Tools
    |--------------------------------------------------------------------------
    |
    | These routes expose high-impact maintenance capabilities such as env
    | editing, backups, queue controls, phpinfo, Horizon, and database actions.
    | Keep them disabled in production unless access is isolated by VPN/IP/MFA.
    |
    */

    'operational_tools_enabled' => env('ADMIN_OPERATIONAL_TOOLS_ENABLED', env('APP_ENV', 'production') !== 'production'),

    /*
    |--------------------------------------------------------------------------
    | Admin Interface Version
    |--------------------------------------------------------------------------
    |
    | v2 is the redesigned admin (its own layout, 8-section navigation and
    | search). v1 keeps the previous layout for one release; each admin can
    | also switch back to it from the account menu.
    |
    */

    'ui' => env('ADMIN_UI', 'v2'),
];
