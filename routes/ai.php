<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateMcpToken;
use App\Mcp\Servers\FergusonLivestockServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/ferguson', FergusonLivestockServer::class)
    ->middleware(['throttle:mcp', AuthenticateMcpToken::class]);
