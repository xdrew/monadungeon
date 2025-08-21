<?php

declare(strict_types=1);

namespace App\Api\Game\Create;

use App\Infrastructure\Http\StatusAwareResult;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final readonly class Response implements StatusAwareResult
{
    public function __construct(
        public Uuid $gameId,
    ) {}

    public function statusCode(): int
    {
        return HttpResponse::HTTP_CREATED;
    }
}
