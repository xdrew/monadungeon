<?php

declare(strict_types=1);

namespace App\Api\Account\Guest;

use App\Account\AccountService;
use App\Account\CurrentAccount;
use App\Account\Session\SessionCookie;
use App\Api\Account\AccountView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A guest account with a name, behind an HttpOnly session cookie. A caller who already has one is renamed instead.
 */
final readonly class Action
{
    public function __construct(
        private AccountService $accounts,
        private CurrentAccount $currentAccount,
    ) {}

    #[Route('/account/guest', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request, HttpRequest $httpRequest): JsonResponse
    {
        try {
            $existing = $this->currentAccount->get($httpRequest);
            if ($existing !== null) {
                $this->accounts->rename($existing, $request->name);

                return new JsonResponse(AccountView::of($existing));
            }
            [$account, $token] = $this->accounts->createGuest($request->name);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['code' => 'account.bad_name', 'message' => $e->getMessage()], 422);
        }
        $this->currentAccount->remember($httpRequest, $account);
        $response = new JsonResponse(AccountView::of($account), 201);
        $response->headers->setCookie(SessionCookie::create($token, $httpRequest->isSecure()));

        return $response;
    }
}
