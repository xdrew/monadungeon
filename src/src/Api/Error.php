<?php

declare(strict_types=1);

namespace App\Api;

use App\Infrastructure\Http\StatusAwareResult;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final readonly class Error implements StatusAwareResult
{
    /**
     * @param non-empty-string $message
     * @param positive-int $statusCode
     */
    public function __construct(
        public Uuid $code,
        public string $message,
        public int $statusCode = HttpResponse::HTTP_INTERNAL_SERVER_ERROR,
    ) {}

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
