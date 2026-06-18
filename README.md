# Milo PHP SDK

A PHP client for the Milo messaging platform, with an `openai-php`-style
developer experience. One unified client gives you both planes:

- **Control plane** — provision config: create tenants, update their variables,
  create tasks with tools, manage channels / api-clients / secrets, read
  usage & billing. (Admin API, `X-Admin-Token`.)
- **Data plane** — drive an agent at runtime: send a message, poll for the
  reply, close a conversation. (`/v1/*`, `Authorization: Bearer milo_sk_…`.)

Both planes use **one base URL** (the API Gateway invoke URL). Both auth schemes
are a single header — no request signing.

> **Prerequisite:** the Milo stack must be deployed with API Gateway enabled
> (`enable_api_gateway=true`), which exposes `/v1/*` (messaging) **and**
> `/admin/*` (config) on the same host. See `infra/` and the repo `docs/API.md`.

## Installing this SDK

This is an **internal package** — it is not published on Packagist. It is
distributed from its own git repo (`ONEtechLORENZO/milo-sdk-php`), so add that as
a Composer `vcs` repository in the consuming project's `composer.json`:

```jsonc
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/ONEtechLORENZO/milo-sdk-php.git" }
  ],
  "require": {
    "milo/sdk": "^0.1"
  }
}
```

```bash
composer update milo/sdk
```

- **Versioning:** `^0.1` tracks `0.1.x` releases (tags on `milo-sdk-php`). Use
  `"dev-main"` to follow the latest unreleased `main` instead of a pinned tag.
- **Private-repo access:** Composer needs read access to `milo-sdk-php`. Either
  give it a read-only GitHub token —
  `composer config --global --auth github-oauth.github.com <token>` (or an
  `auth.json`) — or use a **deploy key** with the SSH URL
  `git@github.com:ONEtechLORENZO/milo-sdk-php.git`.

Requires PHP 8.1+. No hard HTTP-client dependency — the SDK uses PSR-18/PSR-17
auto-discovery (`php-http/discovery`), preferring Guzzle when installed and
otherwise any PSR-18 client in your project; you can also inject one via the
factory's `withHttpClient(...)`.

> **Maintainers:** this repo is **generated** by a subtree split from
> `ONEtechLORENZO/milo-poc` (`sdk/php/`). Edit the SDK there, not here — direct
> commits to `milo-sdk-php` are overwritten on the next split. See `CLAUDE.md`.

## Quick start

```php
use Milo\Sdk\Milo;

$milo = Milo::client(
    baseUrl: 'https://abc123.execute-api.eu-south-1.amazonaws.com/prod',
    adminToken: getenv('MILO_ADMIN_TOKEN'),          // control plane
    apiClients: ['web_app' => getenv('MILO_API_KEY')], // data plane: milo_sk_…
);
```

Or the full factory (mirrors `OpenAI::factory()`):

```php
use Milo\Sdk\Milo;

$milo = Milo::factory()
    ->withBaseUrl('https://abc123.execute-api.eu-south-1.amazonaws.com/prod')
    ->withAdminToken(getenv('MILO_ADMIN_TOKEN'), actor: 'deploy-bot')
    ->withApiClient('web_app', getenv('MILO_API_KEY'))
    ->withTimeout(30.0)
    ->make();
```

## Provision an agent (control plane)

```php
// 1) A tenant + its prompt variables
$milo->tenants()->create([
    'tenant_id' => 'acme',
    'display_name' => 'Acme Inc.',
    'status' => 'active',
    'prompt_variables' => ['brand' => 'Acme'],
]);

// update variables later (optimistic-locking aware)
$milo->tenants()->setVariables('acme', ['brand' => 'Acme Corp']);

// 2) A tool (self-contained HTTP tool)
$milo->tools('acme')->builder('order_lookup')
    ->http('GET', 'https://api.acme.test/orders/{{order_id}}')
    ->capability('read')->sideEffect('none')
    ->inputSchema(['type' => 'object', 'properties' => ['order_id' => ['type' => 'string']]])
    ->enable()
    ->create();

// 3) A task that uses the tool, published as v1
$milo->tasks('acme')->builder('support')
    ->displayName('Support agent')
    ->inlinePrompt('You are {{brand}} support. Be concise and friendly.')
    ->model('eu.amazon.nova-micro-v1:0')
    ->withTool('order_lookup')          // tool-enabled => inline/direct model
    ->history(true, 20)
    ->enable()
    ->publish();

// 4) An api-client (ingress credential), with a generated bearer key.
//    Needs a provisioner/admin/owner credential; a tenant-scoped provisioner
//    mints only for its own tenant (least-privilege automation key).
$client = $milo->apiClients('acme')->create([
    'client_id' => 'web_app',
    'allowed_task_ids' => ['support'],
]);
$apiKey = $client->get('bearer_token'); // milo_sk_… shown once — store it
// Rotate later (old key stops working immediately):
$apiKey = $milo->apiClients('acme')->rotateBearer('web_app')->get('bearer_token');
```

## Drive the agent (data plane)

```php
$chat = $milo->messaging('acme', 'web_app', apiKey: $apiKey);

$send = $chat->send('Where is my order #1234?', [
    'task_id' => 'support',
    'external_sender_id' => 'user-42',   // stable end-user identity
]);

// Poll until the reply is ready (poll delivery mode, the prod default).
$result = $send->poll();
echo $result->reply->text;               // typed response object

// Continuing the conversation: the same external_sender_id continues it.
// If it had been closed, $send->wasReopened() is true and $send->conversationId
// is the fresh id to use going forward.

$chat->close($send->conversationId, reason: 'resolved', taskId: 'support');
```

### Conversation export + purge (Milo is not the archive)

Closing seals the conversation, hands the transcript back, then purges Milo's
content copy. For `poll` / `client_storage` export modes the client pulls the
package and acknowledges it (webhook mode delivers + acks automatically):

```php
// Configure on the task: ->conversationExport('poll', maxRetentionHours: 24)
$export = $chat->export($send->conversationId, taskId: 'support');

if ($export->notReady()) { /* still building — poll again */ }
if ($export->isPurged()) { /* already handed back + deleted */ }
if ($export->isReady()) {
    $package = $export->package();         // transcript + metadata
    // ... persist it on the client side (the client owns long-term storage) ...
    $chat->acknowledgeExport($send->conversationId, taskId: 'support'); // → purge now
}
```

## Errors

Every failure is a typed exception under `Milo\Sdk\Exception\` extending
`MiloException`:

| Exception | HTTP | Notes |
| --- | --- | --- |
| `ValidationException` | 400 / 422 | `->fieldErrors()` for admin field errors |
| `AuthException` | 401 / 403 | bad/missing key, disabled, tenant mismatch, not allowed |
| `NotFoundException` | 404 | unknown id |
| `ConflictException` | 409 | `->currentConfigVersion()` to refetch + retry |
| `RateLimitedException` | 429 | `->retryAfter()`; transport already retried |
| `ServerException` | 5xx | safe to retry sends with the same `external_message_id` |
| `TransportException` | — | no HTTP response (network/DNS/TLS) |

The transport retries per-minute `429`/`5xx` a bounded number of times with
backoff (honouring `Retry-After`) before throwing; a monthly-quota `429`
(`QuotaExceededException`, a `RateLimitedException` subtype) is **not** retried.
Writes are never auto-retried — to retry a failed `send()` safely, reuse the
same `external_message_id` (the server dedupes on it). The SDK generates one if
you don't pass it and surfaces it on the thrown exception as
`$e->externalMessageId`, so a retry stays idempotent.

## Laravel

The package auto-registers `MiloServiceProvider` and the `Milo` facade. Publish
the config:

```bash
php artisan vendor:publish --tag=milo-config
```

Set in `.env`:

```dotenv
MILO_BASE_URL=https://abc123.execute-api.eu-south-1.amazonaws.com/prod
MILO_ADMIN_TOKEN=...
MILO_API_CLIENT_ID=web_app
MILO_API_KEY=milo_sk_K3yId-s3cret… (opaque)
```

Then:

```php
use Milo\Sdk\Laravel\Facades\Milo;

Milo::tenants()->create([...]);
$reply = Milo::messaging('acme', 'web_app')->send('hi', [
    'task_id' => 'support', 'external_sender_id' => 'user-42',
])->poll();
```

Or inject the client: `public function __construct(private \Milo\Sdk\Client $milo) {}`.

## Auth

Control-plane calls (`/admin/*`) send `X-Admin-Token` (role `admin`/`owner` for
writes) + an audit `X-Admin-Actor`. Data-plane calls (`/v1/*`) send
`Authorization: Bearer milo_sk_{key_id}-{secret}` — the same key for writes and
reads. The key is **opaque** (both halves random; it reveals nothing about the
tenant/client); the server resolves the owner from `key_id` and constant-time
compares the key's SHA-256 against the stored hash. There is no per-request
signature, timestamp, or replay window (bearer-over-TLS), so send it only over
HTTPS. The key is shown once on issue/rotate — store it.

## Provisioning CLI (`vendor/bin/milo`)

Paste a **provisioner** (or admin/owner) token into your environment and mint keys
/ onboard tenants from the terminal — no code:

```bash
export MILO_BASE_URL=https://abc123.execute-api.eu-south-1.amazonaws.com/prod
export MILO_ADMIN_TOKEN=<provisioner token>     # X-Admin-Token
export MILO_ADMIN_ACTOR=ops@acme.test           # optional audit actor

vendor/bin/milo tenant:create acme --display-name="Acme Inc."
vendor/bin/milo key:create acme web_app --tasks=support --rpm=120 --monthly-messages=10000
# → prints the milo_sk_… key ONCE (store it)
vendor/bin/milo key:rotate  acme web_app        # new key; old stops working
vendor/bin/milo client:list acme                # client ids + key last4
vendor/bin/milo client:disable acme web_app     # revoke
vendor/bin/milo help
```

A platform (unscoped) provisioner mints for any tenant; a tenant-scoped one only
for its own. The key is printed once and the token is never logged. Limits/quotas
are set at mint time (`--rpm`, `--monthly-messages`, `--monthly-tokens`).

## Tests

```bash
composer install
vendor/bin/phpunit
```
