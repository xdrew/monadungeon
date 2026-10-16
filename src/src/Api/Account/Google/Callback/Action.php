<?php

declare(strict_types=1);

namespace App\Api\Account\Google\Callback;

use App\Account\AccountService;
use App\Account\CurrentAccount;
use App\Account\Google\GoogleClientChooser;
use App\Account\Google\GoogleSignIn;
use App\Account\Google\GoogleSignInFailed;
use App\Account\Session\SessionCookie;
use App\Api\Account\Google\OAuthCookie;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Back from Google: check the state, trade the code for who signed in, then keep / sign in / create
 * (GoogleSignIn), and go back where the player came from with ?google=bound|signed_in|created|failed.
 */
final readonly class Action
{
    public function __construct(
        private GoogleClientChooser $google,
        private GoogleSignIn $signIn,
        private AccountService $accounts,
        private CurrentAccount $currentAccount,
        private LoggerInterface $logger,
    ) {}

    private static function withOutcome(string $path, string $outcome): string
    {
        return $path . (str_contains($path, '?') ? '&' : '?') . 'google=' . $outcome;
    }

    #[Route('/account/google/callback', methods: ['GET'])]
    public function __invoke(Request $request): RedirectResponse
    {
        $secure = $request->isSecure() || $request->headers->get('X-Forwarded-Proto') === 'https';
        $flow = OAuthCookie::read($request);
        $return = $flow['return'] ?? '/';

        try {
            if ($flow === null || !hash_equals($flow['state'], (string) $request->query->get('state', ''))) {
                throw new GoogleSignInFailed('state');
            }
            if ($request->query->has('error')) {
                throw new GoogleSignInFailed('denied');
            }
            $client = $this->google->client();
            if (!$client->isAvailable()) {
                throw new GoogleSignInFailed('off');
            }
            $identity = $client->exchange((string) $request->query->get('code', ''), $flow['verifier'], OAuthCookie::redirectUri($request));
            [$outcome, $account, $needsSession] = $this->signIn->signIn($this->currentAccount->get($request), $identity);
        } catch (GoogleSignInFailed $e) {
            $this->logger->warning('account.google_failed', ['reason' => $e->reason]);
            $response = new RedirectResponse(self::withOutcome($return, 'failed'));
            $response->headers->setCookie(OAuthCookie::clear($secure));

            return $response;
        }

        $response = new RedirectResponse(self::withOutcome($return, $outcome));
        $response->headers->setCookie(OAuthCookie::clear($secure));
        if ($needsSession) {
            $response->headers->setCookie(SessionCookie::create($this->accounts->openSession($account), $secure));
        }

        return $response;
    }
}
