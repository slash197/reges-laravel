# reges-laravel

[![CI](https://github.com/slash197/reges-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/slash197/reges-laravel/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/slash197/reges-laravel)](https://packagist.org/packages/slash197/reges-laravel)

Laravel bridge for [slash197/reges](https://github.com/slash197/reges), the PHP client for the [REGES Online](https://reges.inspectiamuncii.ro) API, the Romanian general register of employees run by Inspecția Muncii.

> **Status: 0.x.** The API of this package may still change before 1.0. See the [changelog](CHANGELOG.md).

The bridge only wires the client into Laravel: configuration, the container, the cache, the logger and the HTTP client. It adds no models, migrations, jobs or queues; how messages are stored, queued and retried is left to your application. Everything you do with the client itself is described in the [core package's README](https://github.com/slash197/reges#readme).

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
| `REGES_API_URL`, `REGES_TOKEN_URL` | per environment | Overrides for the API and token hosts |

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

If you bind `slash197\Reges\Validation\NomenclatorLookup`, the client checks values against your copy of the nomenclators.

## Development

Nothing needs to be installed on the host besides Docker:

```bash
make build
make install
make check   # composer validate, PHPStan and PHPUnit
```

## Licence

MIT. See [LICENSE](LICENSE).
