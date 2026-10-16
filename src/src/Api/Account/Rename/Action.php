<?php

declare(strict_types=1);

namespace App\Api\Account\Rename;

use App\Account\AccountService;
use App\Account\CurrentAccount;
use App\Api\Account\AccountView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class Action
{
    public function __construct(
        private AccountService $accounts,
        private CurrentAccount $currentAccount,
    ) {}

    #[Route('/account/me', methods: ['PATCH'])]
    public function __invoke(#[MapRequestPayload] Request $request, HttpRequest $httpRequest): JsonResponse
    {
        $account = $this->currentAccount->get($httpRequest);
        if ($account === null) {
            return new JsonResponse(['code' => 'account.required', 'message' => 'No session'], 401);
        }

        try {
            $this->accounts->rename($account, $request->name);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['code' => 'account.bad_name', 'message' => $e->getMessage()], 422);
        }

        return new JsonResponse(AccountView::of($account));
    }
}
