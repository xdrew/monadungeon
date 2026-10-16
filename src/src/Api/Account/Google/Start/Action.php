<?php

declare(strict_types=1);

namespace App\Api\Account\Google\Start;

use App\Account\Google\GoogleClientChooser;
use App\Api\Account\Google\OAuthCookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Off to Google: a fresh state and PKCE verifier in the OAuth cookie, then the consent screen.
 * `return` is where to come back (a path on this site); `as` picks who signs in with the dev/test fake.
 */
final readonly class Action
{
    public function __construct(
        private GoogleClientChooser $google,
    ) {}

    #[Route('/account/google/start', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $client = $this->google->client();
        if (!$client->isAvailable()) {
            return new Response('Google sign-in is off on this server', 404);
        }
        $flow = OAuthCookie::begin((string) $request->query->get('return', '/'));
        $hint = $this->google->isFake() ? (string) $request->query->get('as', '') : null;
        $response = new RedirectResponse($client->authorizeUrl(
            $flow['state'],
            OAuthCookie::challenge($flow['verifier']),
            OAuthCookie::redirectUri($request),
            $hint !== '' ? $hint : null,
        ));
        $response->headers->setCookie(OAuthCookie::create($flow, $request->isSecure() || $request->headers->get('X-Forwarded-Proto') === 'https'));

        return $response;
    }
}
