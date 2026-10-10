# reges-laravel

[![CI](https://github.com/slash197/reges-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/slash197/reges-laravel/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/slash197/reges-laravel)](https://packagist.org/packages/slash197/reges-laravel)

Laravel bridge for [slash197/reges](https://github.com/slash197/reges), the PHP client for the [REGES Online](https://reges.inspectiamuncii.ro) API, the Romanian general register of employees run by Inspecția Muncii.

> **Status: 0.x.** The API of this package may still change before 1.0. See the [changelog](CHANGELOG.md).

It gives a Laravel application:

- the client in the container, configured from `config/reges.php`, and a factory for applications with an API key per tenant;
- access tokens kept encrypted in the cache;
- the result queue read by a scheduled command and delivered as events;
- a local copy of the nomenclators, so messages are validated against them before they are sent;
- a command that checks the setup, and a facade.

Sending is left to your application: the bridge adds no models, migrations or queued jobs, because REGES needs each contract's messages in the order they were built and only your application knows how it stores and orders them. Everything you do with the client itself is described in the [core package's README](https://github.com/slash197/reges#readme).

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
| `REGES_CONSUMER_ID` | none | Consumer id `reges:results` reads the queue as |
| `REGES_API_URL`, `REGES_TOKEN_URL` | per environment | Overrides for the API and token hosts |

Check the setup with read-only calls (authentication, the profile and a look at the result queue without advancing it):

```bash
php artisan reges:check
```

## One registry

An API key belongs to exactly one employer registry. When your application works on a single registry, put its key in the config and take the client from the container:

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

## A key per tenant

When each tenant has its own API key, leave `REGES_USERNAME` and `REGES_PASSWORD` empty and build a client per key with the factory. It shares the configured environment, OAuth client, HTTP client and logger:

```php
use slash197\Reges\Credentials;
use slash197\Reges\Laravel\RegesFactory;

$reges = app(RegesFactory::class)->make(
    new Credentials($company->reges_username, $company->reges_password),
);
```

Tokens are cached under a key derived from the token URL, the client id and the username, so clients for different keys never share a token.

## Reading results

REGES reports the outcome of each message later, in a queue per registry. `reges:results` reads that queue and dispatches a `ResultReceived` event for each result. Schedule it:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('reges:results')->everyMinute()->withoutOverlapping();
```

and store the outcome in a listener:

```php
use slash197\Reges\Laravel\Events\ResultReceived;

final class RecordRegesResult
{
    public function handle(ResultReceived $event): void
    {
        $result = $event->result;

        // $result->messageId, $result->isSuccess(), $result->ref, $result->description
    }
}
```

The queue moves past a result only after every listener has returned. If a listener throws, the run stops and the same result is delivered again next time, so nothing is lost and one result that cannot be stored holds back the ones behind it. A listener that implements `ShouldQueue` counts as done once it is on your queue.

A run reads at most `reges.results.limit` results (500 by default; `--limit` overrides it). If several applications read the same registry, give each its own consumer id with `REGES_CONSUMER_ID` or `--consumer`.

With a key per tenant, call the reader yourself for each registry. The label you pass comes back in the event as `$event->registry`:

```php
use slash197\Reges\Laravel\ResultReader;

foreach ($companies as $company) {
    $reges = $factory->make(new Credentials($company->reges_username, $company->reges_password));

    app(ResultReader::class)->drain($reges, registry: (string) $company->id);
}
```

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

or pass it for one client:

```php
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
