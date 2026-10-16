<?php

declare(strict_types=1);

namespace App\Api\Table\Create;

use App\Api\Table\Tables;
use App\Game\GameLifecycle\AddPlayer;
use App\Game\GameLifecycle\CreateTable;
use App\Game\Testing\TestMode;
use App\Infrastructure\Uuid\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Telephantast\MessageBus\MessageBus;

/**
 * Opens a table; the host takes the first seat.
 */
final readonly class Action
{
    public function __construct(
        private MessageBus $messageBus,
        private Tables $tables,
        private LoggerInterface $logger,
    ) {}

    #[Route('/tables', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request, HttpRequest $httpRequest): JsonResponse
    {
        $account = $this->tables->account($httpRequest);
        if ($account === null) {
            return Tables::anonymous();
        }

        $gameId = Uuid::v7();
        $playerId = Uuid::v7();
        $seeded = $this->seedTestMode($gameId, $request->test);

        try {
            // Two dispatches: the new table is findable only after its factory returns
            $this->messageBus->dispatch(new CreateTable(
                gameId: $gameId,
                hostAccountId: $account->getId()->toString(),
                seats: $request->seats,
                regent: $request->regent,
                lordKarak: $request->lordKarak,
                randomHeroes: $request->randomHeroes,
            ));
            $this->messageBus->dispatch(new AddPlayer(
                gameId: $gameId,
                playerId: $playerId,
                externalId: $account->getId()->toString(),
                username: $account->getName(),
            ));
        } catch (\Throwable $e) {
            return Tables::refusal($e);
        } finally {
            if ($seeded) {
                TestMode::getInstance()->disable();
            }
        }
        $this->logger->info('table.created', [
            'gameId' => $gameId->toString(),
            'accountId' => $account->getId()->toString(),
            'seats' => $request->seats,
            'regent' => $request->regent,
            'lordKarak' => $request->lordKarak,
            'randomHeroes' => $request->randomHeroes,
        ]);

        return new JsonResponse(['gameId' => $gameId->toString(), 'playerId' => $playerId->toString()], 201);
    }

    /**
     * The e2e specs script a table like a test-mode game, dev/test only: the deck, the bag and the dice are
     * written into the new table during this request (as /test/setup-game does), then the test mode is as it was.
     *
     * @param array{tileSequence?: list<mixed>, itemSequence?: list<mixed>, diceRolls?: list<int>}|null $test
     */
    private function seedTestMode(Uuid $gameId, ?array $test): bool
    {
        $appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'prod';
        $testMode = TestMode::getInstance();
        if ($test === null || !\in_array($appEnv, ['dev', 'test'], true)) {
            return false;
        }
        $wasOn = $testMode->isEnabled();
        $testMode->enable();
        if (($test['tileSequence'] ?? []) !== []) {
            $testMode->setFixedDeck($gameId->toString(), $test['tileSequence']);
        }
        if (($test['itemSequence'] ?? []) !== []) {
            $testMode->setFixedBag($gameId->toString(), $test['itemSequence']);
        }
        if (($test['diceRolls'] ?? []) !== []) {
            $testMode->setDiceRolls($test['diceRolls']);
        }
        $this->logger->info('table.test_seeded', ['gameId' => $gameId->toString()]);

        // switched on for this request only: off again after the table is created
        return !$wasOn;
    }
}
