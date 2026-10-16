<?php

declare(strict_types=1);

namespace App\Api\Account\SignOut;

use App\Account\AccountService;
use App\Account\CurrentAccount;
use App\Account\Session\SessionCookie;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Signs this device out of an account kept with Google (a guest would lose its account for good: refused).
 */
final readonly class Action
{
    public function __construct(
        private AccountService $accounts,
        private CurrentAccount $currentAccount,
        private LoggerInterface $logger,
    ) {}

    #[Route('/account/sign-out', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $account = $this->currentAccount->get($request);
        if ($account === null) {
            return new JsonResponse(['code' => 'account.required', 'message' => 'Not signed in'], 401);
        }
        if ($account->getGoogleSub() === null) {
            return new JsonResponse(['code' => 'account.not_kept', 'message' => 'Keep the account with Google before signing out'], 409);
        }
        $this->accounts->closeSession((string) $request->cookies->get(SessionCookie::NAME, ''));
        $this->logger->info('account.signed_out', ['accountId' => $account->getId()->toString()]);
        $response = new JsonResponse(['signedOut' => true]);
        $response->headers->setCookie(Cookie::create(SessionCookie::NAME)->withValue('')->withExpires(1)->withPath('/')->withHttpOnly(true)->withSameSite(Cookie::SAMESITE_LAX));

        return $response;
    }
}
