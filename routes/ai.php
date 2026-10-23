<?php

use App\Mcp\Servers\RingMasterServer;
use Laravel\Mcp\Facades\Mcp;

// Local (stdio) server: Claude Code starts it with `php artisan mcp:start ring-master`.
Mcp::local('ring-master', RingMasterServer::class);
