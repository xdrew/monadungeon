<?php

declare(strict_types=1);

namespace App\Api\Account\Me;

use App\Account\CurrentAccount;
use App\Api\Account\AccountView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Who is calling: 200 with the account, 204 when there is no session.
 */
final readonly class Action
{
    public function __construct(
        private CurrentAccount $currentAccount,
    ) {}

    #[Route('/account/me', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $account = $this->currentAccount->get($request);

        return $account === null ? new Response('', 204) : new JsonResponse(AccountView::of($account));
    }
}
