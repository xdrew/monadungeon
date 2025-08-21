<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class SerializeJsonControllerResultListener
{
    public function __construct(
        private NormalizerInterface $normalizer,
    ) {}

    #[AsEventListener]
    public function onKernelView(ViewEvent $event): void
    {
        if ($event->getRequest()->getRequestFormat() !== 'json') {
            return;
        }

        $result = $event->getControllerResult();
        $normalized = $this->normalizer->normalize($result);

        $event->setResponse(JsonResponse::fromJsonString(
            data: jsonEncode($normalized, JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_UNICODE),
            status: $result instanceof StatusAwareResult ? $result->statusCode() : Response::HTTP_OK,
        ));
    }
}
