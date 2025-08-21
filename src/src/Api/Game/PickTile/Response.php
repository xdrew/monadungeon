<?php

declare(strict_types=1);

namespace App\Api\Game\PickTile;

use App\Game\Field\Tile;
use App\Infrastructure\Http\StatusAwareResult;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final readonly class Response implements StatusAwareResult
{
    public function __construct(
        public Tile $tile,
    ) {}

    public function statusCode(): int
    {
        return HttpResponse::HTTP_CREATED;
    }
}
