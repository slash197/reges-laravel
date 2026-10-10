# reges-laravel

[![CI](https://github.com/slash197/reges-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/slash197/reges-laravel/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/slash197/reges-laravel)](https://packagist.org/packages/slash197/reges-laravel)

Laravel bridge for [slash197/reges](https://github.com/slash197/reges), the PHP client for the [REGES Online](https://reges.inspectiamuncii.ro) API, the Romanian general register of employees run by Inspecția Muncii.

> **Status: 0.x.** The API of this package may still change before 1.0. See the [changelog](CHANGELOG.md).

It gives a Laravel application:

- the client in the container, configured from `config/reges.php`, for one registry or for one per tenant;
- an outbox: messages are stored, sent in order, retried, and matched to the result REGES reports later;
- events for accepted, rejected and undeliverable messages;
- a local copy of the nomenclators, so messages are validated against them before they are stored or sent;
- access tokens kept encrypted in the cache, a command that checks the setup, and a facade.

What stays in your application is what only it knows: turning your employees and contracts into messages, deciding which operation a change amounts to, and what to do when a message is accepted or refused. Building messages is described in the [core package's README](https://github.com/slash197/reges#readme).

## Requirements

- PHP 8.2 or newer
- Laravel 12 or 13

## Installation

```bash
composer require slash197/reges-laravel
```

The service provider is discovered automatically. Publish the config file if you want to change more than the environment variables allow:

```bash
php artisan vendor:publish --tag=reges-config
```

## Configuration

```dotenv
REGES_ENVIRONMENT=test
REGES_CLIENT_ID=reges-api
REGES_CLIENT_SECRET=
REGES_CLIENT_APPLICATION="My Application"
REGES_CLIENT_VERSION=1.0

# API key pair from the REGES employer application: Setari -> Acces -> Chei API
REGES_USERNAME=
REGES_PASSWORD=
```

| Variable | Default | Meaning |
|---|---|---|
| `REGES_ENVIRONMENT` | `test` | `test` or `production` |
| `REGES_CLIENT_ID`, `REGES_CLIENT_SECRET` | none | OAuth client issued by REGES for your software |
| `REGES_CLIENT_APPLICATION` | `APP_NAME` | Name of the software, reported in every message header |
| `REGES_CLIENT_VERSION` | `1.0` | Version of the software |
| `REGES_USER` | none | Default human user reported in the header |
| `REGES_USERNAME`, `REGES_PASSWORD` | none | API key of the registry the container-bound client works on |
| `REGES_CACHE_STORE` | default store | Cache store that keeps the access tokens |
| `REGES_LOG_CHANNEL` | default channel | Log channel that receives request metadata |
| `REGES_NOMENCLATOR_CACHE_STORE` | `REGES_CACHE_STORE` | Cache store that keeps the nomenclator copy |
| `REGES_OUTBOX` | `true` | Whether the package keeps the outbox table and sends from it |
| `REGES_CONSUMER_ID` | none | Consumer id `reges:results` reads the queue as |
| `REGES_API_URL`, `REGES_TOKEN_URL` | per environment | Overrides for the API and token hosts |

Check the setup with read-only calls (authentication, the profile and a look at the result queue without advancing it):

```bash
php artisan reges:check
```

## One registry

When your application reports for a single company, put its API key in the config and take the client from the container:

```php
use slash197\Reges\Reges;

final class ShowRegesProfile
{
    public function __invoke(Reges $reges): string
    {
        return $reges->profile()->angajatorId ?? 'unknown';
    }
}
```

The same client is behind the `Reges` facade (`slash197\Reges\Laravel\Facades\Reges`):

```php
use slash197\Reges\Laravel\Facades\Reges;

$receipt = Reges::send($message);
```

## Several registries

An API key belongs to one registry, so an application that reports for several companies has one key per company. Tell the bridge where they are by binding a `CredentialsProvider`; the registry key is whatever identifies a company to you:

```php
use slash197\Reges\Credentials;
use slash197\Reges\Laravel\CredentialsProvider;

final class CompanyCredentials implements CredentialsProvider
{
    public function credentials(string $registry): Credentials
    {
        $company = Company::findOrFail($registry);

        return new Credentials($company->reges_username, $company->reges_password);
    }
}

// in a service provider
$this->app->singleton(CredentialsProvider::class, CompanyCredentials::class);
```

Leave `REGES_USERNAME` and `REGES_PASSWORD` empty in that case. Everything below then takes a registry key, and you can get a client for one directly:

```php
use slash197\Reges\Laravel\Registries;

$reges = app(Registries::class)->client((string) $company->id);
```

Tokens are cached under a key derived from the token URL, the client id and the username, so registries never share a token. Without a provider there is one registry, called `default`, with the key from the config.

## Sending messages

REGES answers a submission with a receipt and reports the outcome later. It also refuses a contract's messages when they arrive out of order. The outbox takes care of both. Store a message instead of sending it:

```php
use slash197\Reges\Laravel\Outbox\Outbox;

$queued = app(Outbox::class)->queue(
    $message,                           // a slash197\Reges\Message
    sequence: "contract:{$contract->id}",
    registry: (string) $company->id,    // leave out with a single registry
    subject: $contract,                 // optional: the model the message is about
    meta: ['addendum_id' => $addendum->id],
    user: $request->user()->name,       // falls back to REGES_USER
);
```

`queue()` validates the message and stores it in its final form; a `ValidationException` means nothing was stored. Then let the scheduler send what is due and read the results:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('reges:send')->everyMinute()->withoutOverlapping();
Schedule::command('reges:results')->everyMinute()->withoutOverlapping();
```

and listen for the outcome:

```php
use slash197\Reges\Laravel\Events\MessageAccepted;

final class StoreRegesId
{
    public function handle(MessageAccepted $event): void
    {
        $message = $event->message;   // the stored message, with your subject and meta

        if ($message->operation === 'AdaugareContract') {
            $message->subject->update(['reges_id' => $message->ref]);
        }
    }
}
```

| Event | When |
|---|---|
| `MessageAccepted` | REGES carried the operation out. `$message->ref` is the id of what it created |
| `MessageRejected` | REGES refused the message, on submission or in its result. `$message->error` says why |
| `MessageFailed` | The message could not be delivered within the retry window |

**Sequences.** Messages queued with the same `sequence` are sent one at a time, in the order they were queued, each waiting for the result of the one before. Use one sequence per employee and one per contract. A rejected or failed message does not hold back the ones after it. Messages without a sequence are sent independently.

**Messages that depend on an earlier result.** A contract needs the REGES id of its employee, which only arrives with the result of the registration. Queue the contract from your `MessageAccepted` listener, once you have that id.

**Retries.** A transient failure (no response, a 5xx, 408 or 429, refused credentials) is retried every minute, more slowly after an hour, and given up on after three days. A message REGES refuses outright is not retried. Both are configurable under `reges.outbox`.

**Stored messages** live in the `reges_messages` table, created by the package's migration when you run `php artisan migrate`. The message body is encrypted with the application key, because it contains personal identification numbers. A pending message can be withdrawn with `$queued->cancel()`.

To keep your own outbox instead, set `REGES_OUTBOX=false`: no table is created, `reges:send` is not registered, and you send through the client yourself. If the table name clashes with one of yours, change `reges.outbox.table` before migrating.

## Reading results

`reges:results` reads the result queue of the configured registry and of every registry with a message awaiting its result, and dispatches a `ResultReceived` event for each result. The outbox listens to it; you only need to if you send messages by other means, or want every result, including those of messages another application sent:

```php
use slash197\Reges\Laravel\Events\ResultReceived;

final class RecordRegesResult
{
    public function handle(ResultReceived $event): void
    {
        $result = $event->result;   // slash197\Reges\Results\Result
        $registry = $event->registry;
    }
}
```

The queue moves past a result only after every listener has returned, including your `MessageAccepted` and `MessageRejected` listeners. If one throws, that registry's run stops and the same result is delivered again next time, so nothing is lost, but one result that cannot be handled holds back the ones behind it. A listener that implements `ShouldQueue` counts as done once it is on your queue.

A run reads at most `reges.results.limit` results per registry (500 by default; `--limit` overrides it). `--registry=KEY` reads only the registries named. If several applications read the same registry, give each its own consumer id with `REGES_CONSUMER_ID` or `--consumer`.

## Nomenclators

The client can check country names, identity document types and locality codes against the REGES nomenclators before a message is sent, but only if it has a local copy of them. Download one, and refresh it on a schedule, since the lists change:

```bash
php artisan reges:nomenclators
```

```php
Schedule::command('reges:nomenclators')->weekly();
```

Until the first download those checks are skipped. The copy lives in the cache, so `cache:clear` removes it and the checks are skipped again until the next download; set `REGES_NOMENCLATOR_CACHE_STORE` to a store you do not clear if that matters to you. The nomenclators are public, so the command works without an API key.

To check against your own copy instead, bind `slash197\Reges\Validation\NomenclatorLookup` in the container.

## Token storage

Access tokens are kept in the cache, encrypted with the application key, until they expire. To keep them somewhere else, implement `slash197\Reges\Auth\TokenStore` and either bind it for every client:

```php
$this->app->singleton(\slash197\Reges\Auth\TokenStore::class, DatabaseTokenStore::class);
```

or build one client with a store of its own through the factory, outside the registries above:

```php
use slash197\Reges\Laravel\RegesFactory;

$reges = app(RegesFactory::class)->make($credentials, new DatabaseTokenStore($company));
```

## HTTP client and logging

Requests go through a Guzzle client with the timeouts from `reges.http`. If your application binds `Psr\Http\Client\ClientInterface` in the container, that client is used instead and the timeouts are yours to configure.

The client logs request metadata only, never message contents, because messages contain personal identification numbers.

## Development

Nothing needs to be installed on the host besides Docker:

```bash
make build
make install
make check   # composer validate, PHPStan and PHPUnit
```

## Licence

MIT. See [LICENSE](LICENSE).
