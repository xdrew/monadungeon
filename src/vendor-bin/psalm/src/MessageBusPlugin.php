<?php

declare(strict_types=1);

namespace App\Psalm;

use App\Infrastructure\MessageBus\Handler\Handler;
use App\Infrastructure\MessageBus\Message;
use Psalm\CodeLocation;
use Psalm\Internal\Type\Comparator\UnionTypeComparator;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterFunctionLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterFunctionLikeAnalysisEvent;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

final class MessageBusPlugin implements PluginEntryPointInterface, AfterFunctionLikeAnalysisInterface
{
    /**
     * @psalm-suppress InternalClass, InternalMethod
     */
    public static function afterStatementAnalysis(AfterFunctionLikeAnalysisEvent $event): ?bool
    {
        $handlerStorage = $event->getFunctionlikeStorage();

        if (!$handlerStorage instanceof MethodStorage) {
            return null;
        }

        if (!self::isHandler($handlerStorage)) {
            return null;
        }

        $handlerReturnType = $handlerStorage->return_type ?? new Union([new TMixed()]);

        foreach (self::handlerMessageClasses($handlerStorage) as $messageClass) {
            $messageClassStorage = $event->getCodebase()->classlike_storage_provider->get($messageClass);

            if (!isset($messageClassStorage->template_extended_params[Message::class]['TResult'])) {
                continue;
            }

            $contractReturnType = $messageClassStorage->template_extended_params[Message::class]['TResult'];

            if (UnionTypeComparator::isContainedBy($event->getCodebase(), $handlerReturnType, $contractReturnType)) {
                continue;
            }

            IssueBuffer::accepts(
                new InvalidHandlerResult(
                    sprintf(
                        'Handler %s::%s return type %s does not satisfy contract %s of %s',
                        $handlerStorage->defining_fqcln ?? '',
                        $handlerStorage->cased_name ?? '',
                        (string) $handlerReturnType,
                        (string) $contractReturnType,
                        $messageClass,
                    ),
                    $handlerStorage->return_type_location ?? new CodeLocation(
                        $event->getStatementsSource(),
                        $event->getStmt(),
                    ),
                ),
                $event->getStatementsSource()->getSuppressedIssues(),
            );
        }

        return null;
    }

    private static function isHandler(MethodStorage $storage): bool
    {
        foreach ($storage->attributes as $attribute) {
            if ($attribute->fq_class_name === Handler::class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return \Generator<string>
     */
    private static function handlerMessageClasses(FunctionLikeStorage $storage): \Generator
    {
        if (!isset($storage->params[0])) {
            return;
        }

        foreach ($storage->params[0]->type?->getAtomicTypes() ?? [] as $type) {
            if ($type instanceof TNamedObject) {
                yield $type->value;
            }
        }
    }

    public function __invoke(RegistrationInterface $registration, ?\SimpleXMLElement $config = null): void
    {
        $registration->registerHooksFromClass(self::class);
    }
}
