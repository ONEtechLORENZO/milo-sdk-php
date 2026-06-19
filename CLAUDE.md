# CLAUDE.md — Milo PHP SDK

Working notes for the Milo PHP SDK (`milo/sdk`). This file is **committed on
purpose** (unlike the rest of the monorepo's Claude notes) so it travels to the
standalone `milo-sdk-php` repo via the subtree split — a session opening either
place gets context immediately.

## ⚠️ This package is split out of a monorepo — edit UPSTREAM

The source of truth is **`ONEtechLORENZO/milo-poc` at `sdk/php/`**. The standalone
**`ONEtechLORENZO/milo-sdk-php`** repo is **generated**: a GitHub Action
(`.github/workflows/sdk-split.yml` in the monorepo) subtree-splits `sdk/php/` →
`milo-sdk-php`'s `main` on every push to the monorepo `main`, and that push
**overwrites** the target. So:

- **If you are in `milo-sdk-php`: do NOT commit here.** Your commits are clobbered
  on the next split. Make the change in `milo-poc/sdk/php/`; it mirrors over.
- Releases are SemVer **tags cut on `milo-sdk-php`** (e.g. `v0.1.0`); versioning is
  deliberately decoupled from monorepo tags.
- Consumers add a Composer `vcs` repository pointing at `milo-sdk-php` and
  `require milo/sdk`.

## What this is

An OpenAI-client-style PHP SDK that drives the Milo messaging platform's **two
planes** through one client (`Milo\Sdk\Client`):

- **Control plane** (admin, `X-Admin-Token`): provision tenants/tasks/
  api-clients/channels/secrets, read usage/billing/audit/conversations. Accessors:
  `tenants()`, `tasks($t)`, `apiClients($t)`, `channels($t)`,
  `secrets($t)`, `usage($t)`, `billing($t)`, `audit($t)`, `conversations($t)`,
  `metrics()`. **Tools are CLIENT-executed** — declared inline on the task
  (`TaskBuilder::withClientTool()` / `clientTools()`), not as separate tenant/
  catalog items, so there is **no `tools()` / `catalog()` accessor**.
- **Data plane** (runtime, `Authorization: Bearer milo_sk_…`): `messaging($tenant,
  $clientId, $apiKey, $defaultTaskId)` → `send`, `result`, `conversation`,
  `messages`, `submitToolResults`, `close`, `export`, `acknowledgeExport`.
  Client-tool loop: `send(...)->runTools($executor)` (or poll
  `conversation()->pendingToolCalls()` + `submitToolResults()` yourself).

Both planes hit **one base URL** (the API Gateway invoke URL incl. the stage);
`Config` appends `/admin/…` or `/v1/…`. One auth header per plane (`X-Admin-Token`
/ `Authorization: Bearer`) — no request signing; the bearer is the sole credential.
An optional API Gateway usage-plan key (`x-api-key`) is supported via
`withApiGatewayKey()` / `MILO_API_GATEWAY_KEY` (the `Transporter` adds it to every
request when set) — needed ONLY if a stage runs `api_require_api_key=true` (OFF by
default). It is an edge-quota credential, NOT auth.

## Layout

- `src/Client.php` — unified client; `Factory.php` / `Milo.php` (static entry) /
  `Config.php` build it.
- `src/Resources/` — one class per area (admin + `Messaging` for the data plane);
  `Resource.php` is the base with `adminGet/adminPost/…`.
- `src/Builder/` — fluent config builder (`TaskBuilder`; tools are declared on the
  task via `withClientTool()`/`clientTools()`).
- `src/Responses/` — typed response objects over a generic `Response` base
  (magic property + array access + `toArray()`); `Item` is the untyped fallback.
- `src/Transport/` — `Transporter` (PSR-18 via discovery), `Response`,
  `ErrorMapper` (status → typed `Exception/`).
- `src/Laravel/` — service provider + facade (auto-discovered).
- `bin/milo` — provisioning CLI.

## Conventions (match these when editing)

- **Builders are generic accumulators, not DTOs.** A nested config block is just a
  key in the `$config` array, persisted as-is; the **server validates**. Add a
  convenience method only for parity (mirror `delivery()` /
  `conversationExport()`), or use `->set($key, $value)` for anything uncovered.
  Each block helper drops nulls: `array_filter([...], fn($v) => $v !== null)`.
- **Responses are read-only.** Add a typed subclass with a `hydrate()` + helpers
  (see `MessageResult`, `ExportResult`) only when callers benefit; otherwise the
  generic `Item`/magic access already exposes any field — a NEW server response
  shape (e.g. a purged conversation `{status:"purged",…}`) needs no SDK change.
- **Reads vs writes:** `signedRead()` (GET, `retry:true`, forwards a `$query`),
  `signedWrite()` (POST, `retry:false` — the caller re-sends with the same
  `external_message_id` for idempotency). Mirror `result()`/`close()`.
- **Map expected non-200s to status, not exceptions** where it's a normal flow:
  `export()` catches 404/410 → `not_ready`/`purged` on `ExportResult`.
- Errors: `ErrorMapper` → typed `MiloException` subclasses (404 `NotFound`, 409
  `Conflict`, 422/400 `Validation`, etc.); 410 falls through to `ApiException`.

## Commands

```bash
composer install
composer test          # or: php vendor/bin/phpunit  (PHPUnit 10; tests/*.php)
php -l src/<File>.php   # quick syntax check
composer validate --strict
```

Tests use a `RecordingClient` (PSR-18 fake) — no network. Assert request
method/URL/headers/body shape + typed-response decoding (see
`tests/ClientTest.php`, `tests/BuilderTest.php`).
