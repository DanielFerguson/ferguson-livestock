<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\Support\Presenter;
use App\Models\Drop;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('current-drop')]
#[Uri('ferguson://current-drop')]
#[MimeType('application/json')]
#[Description('The drop customers can order from right now, or the next scheduled one: items, prices and what is left.')]
final class CurrentDropResource extends Resource
{
    public function handle(Request $request): Response
    {
        $drop = Drop::featured();

        return Response::text(json_encode($drop instanceof Drop ? Presenter::drop($drop, withItems: true) : ['drop' => null], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }
}
