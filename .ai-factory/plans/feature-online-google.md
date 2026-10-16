# Implementation Plan: Online play, part 3 — keep the guest account with Google, sign in on another device

Branch: feature/online-google (created from feature/online-lobby @ 2a9c1a4; the chain is merged to main together)
Created: 2026-10-16

## Original Request
Online play, part 3: bind the guest account to Google to keep it on another device (Делай третью часть)

## Settings
- Testing: yes.
  - PHPUnit for the binding rules, the ID-token claim checks and the sessions.
  - `online.spec.ts` gets the Google flow through the dev/test fake provider; the implementer runs it with every topic spec.
- Logging: standard.
  - `account.google_bound`, `account.google_signed_in`, `account.google_created`, `account.signed_out` at info.
  - OAuth failures (bad state, exchange error, bad claims) at warning, with the reason only.
  - Never log codes, tokens, the PKCE verifier or the session token.
- Docs: yes (CLAUDE.md, README with the Google Cloud setup and the redirect URI, `fe/e2e/README.md`, `.env` keys).

The answers of part 1 stand (2026-10-16): "Binding the account through Google, to keep it on another device, comes in part 3". Settings are as in parts 1 and 2 ("tests/logs/docs as usual").

## Requirements Reconciliation
Authority: user answers > part 1 / part 2 plans > CLAUDE.md > existing code.

| Decision | Source | Verification |
|---|---|---|
| **Server-side OAuth 2.0 authorization-code flow with PKCE and `state`** (no Google JS SDK): `GET /api/account/google/start?return=/path` sets a short-lived HttpOnly cookie `karak_oauth` (state + verifier + return path, 10 minutes, Path `/api/account/google`) and redirects to `accounts.google.com` (scopes `openid email profile`, `prompt=select_account`). `GET /api/account/google/callback` checks the state, exchanges the code with curl (client secret + verifier), and reads the ID token from the token response. The token comes straight from Google over TLS, so per OIDC the claims are checked (`iss`, `aud` = client id, `exp`, `email_verified`) and the signature is not. | part 1 plan; OIDC core 3.1.3.7 | PHPUnit on the claim checks |
| **Config**: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` (empty = Google is off: no buttons, the endpoints answer 404). The redirect URI is `{scheme}://{host}/api/account/google/callback` from the request (nginx passes Host and X-Forwarded-Proto). | — | curl |
| **One account, many devices**: a new `AccountSession` entity (schema `account`: id, accountId, tokenHash unique, createdAt, lastSeenAt). Signing in on a device creates a session there. `CurrentAccount` looks the cookie up in the sessions first, then in `Account.tokenHash` (the guests of part 1). Migration via diff. | needed by the request | PHPUnit |
| **`Account` fields** `googleSub` (unique, nullable), `googleEmail` (for "kept with a…@gmail.com"). | — | migration |
| **The callback cases** (the caller's cookie × Google's `sub`): | | PHPUnit `GoogleSignInTest`, one per row |
| a guest A, `sub` bound to nobody | | binds `sub` to A; back with `?google=bound` |
| a guest A, `sub` bound to A | | nothing changes; `?google=bound` |
| a guest A, `sub` bound to B | | the cookie switches to a new session of B; A stays a separate guest with its own Hall-of-fame rows; `?google=signed_in` |
| nobody (a new device), `sub` bound to B | | a new session of B; `?google=signed_in` |
| nobody, `sub` bound to nobody | | a new account named from Google's given name (made valid by `AccountName`, else the suggestion "Wanderer 42"), bound; `?google=created` |
| an error (state, exchange, claims, Google is off) | | no change; `?google=failed` |
| **Sign out** (`POST /api/account/sign-out`): only for an account kept with Google (a guest would lose its account for good). It deletes this device's session and the cookie. | safety | PHPUnit, e2e |
| **`GET /api/account/me`** adds `google: {email} \| null` and `googleAvailable`. | — | e2e |
| **Start screen**: a guest with a name sees "Keep with Google" beside the pen. A kept account sees "Kept with Google · a…@gmail.com" and "Sign out". The name card offers "I have an account: sign in with Google". `?google=…` shows a one-time notice in the panel, and the query is then dropped. Texts are in en/ru/pl, in Torchlight. | user | e2e; screenshots |
| **Invite links**: the `/table/:id` name card offers Google sign-in as well (`return=/table/{id}`). | part 2 flow | e2e |
| **The fake provider** (dev/test only, for the e2e): with `GOOGLE_CLIENT_ID=fake` and `APP_ENV` dev/test, `start?as={sub}:{email}` skips Google and comes back to the callback with a fake code; the fake exchange returns those claims. A different `GoogleOAuthClient` implementation is chosen by the client id. | e2e | e2e |
| **Return path**: only same-site relative paths (`/…`, not `//…`); anything else falls back to `/`. | security | PHPUnit |

## Commit Plan
- **Commit 1** (tasks 1–3): "feat(account): sessions per device, Google binding and sign-in on the server"
- **Commit 2** (tasks 4–6): "feat(fe): keep the account with Google, sign in on another device; e2e and docs"

## Tasks

### Phase 1: Server
- [ ] Task 1: `src/src/Account/AccountSession.php` (entity) and `AccountService` (session create/find/delete; `findByToken` checks sessions, then the legacy hash); `Account` gets `googleSub`, `googleEmail`, `bindGoogle()`. Migration via diff.
- [ ] Task 2: `src/src/Account/Google/`:
  - `GoogleOAuthClient` (interface: `authorizeUrl(state, challenge, redirectUri)`, `exchange(code, verifier, redirectUri): GoogleIdentity`);
  - `GoogleHttpClient` (curl; token endpoint; claim checks);
  - `FakeGoogleClient` (dev/test);
  - `GoogleIdentity` (sub, email, emailVerified, givenName);
  - `GoogleSignIn` (the case table above → result + the account whose session to set).
  
  DI picks the client by `GOOGLE_CLIENT_ID`. Logs as in Settings.
- [ ] Task 3: API in `src/src/Api/Account/Google/`: `Start`, `Callback`, plus `SignOut`; `Me` adds `google` and `googleAvailable`; the cookie helpers in `SessionCookie`. PHPUnit: `GoogleSignInTest` (every row), claim checks, return-path check, sessions. curl smoke with the fake provider.

### Phase 2: Browser, e2e, docs
- [ ] Task 4: `fe/src/services/account.js` (`googleStartUrl(returnPath, as?)`, `signOut()`); start-screen account line and name card; the `/table/:id` name card; the `?google=` notice; i18n en/ru/pl; the Google mark as a line icon in `gameIcons.js` (no logo image).
- [ ] Task 5: `fe/e2e/tests/online.spec.ts`:
  - keep a guest with Google (fake);
  - a second browser signs in with the same `sub` and gets the same name;
  - a third browser signs in with a new `sub` and gets a created account;
  - sign out;
  - a guest switching to a kept account.
  
  Then run all topic specs; screenshots reviewed.
- [ ] Task 6: Docs:
  - README: Google Cloud console → OAuth client "Web", the redirect URI `https://<host>/api/account/google/callback`, the env keys, the fake provider;
  - CLAUDE.md;
  - `fe/e2e/README.md`;
  - `.env` keys (`GOOGLE_CLIENT_ID=fake` in dev).
