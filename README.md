# reges-laravel

[![CI](https://github.com/slash197/reges-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/slash197/reges-laravel/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/slash197/reges-laravel)](https://packagist.org/packages/slash197/reges-laravel)

Laravel bridge for [slash197/reges](https://github.com/slash197/reges), the PHP client for the [REGES Online](https://reges.inspectiamuncii.ro) API, the Romanian general register of employees run by Inspecția Muncii.

> **Status: 0.x.** Verified against the REGES test environment, but the API of this package may still change before 1.0. See the [changelog](CHANGELOG.md).

Reporting to REGES is not a single HTTP call. A submission is only queued; the outcome arrives later in a separate queue; a contract's messages must arrive in order; and the id REGES gives a new employee is needed before the contract can be sent. This package takes care of that part for a Laravel application:

- **The client in the container**, configured from `config/reges.php`, for one company or for one API key per tenant.
- **An outbox.** Messages are validated, stored, sent in order, retried on transient failures and matched to the result REGES reports later.
- **Events** for accepted, rejected and undeliverable messages, carrying your own model and context.
- **A local copy of the nomenclators**, so values are checked before a message is stored.
- **Artisan commands** to send, read results, refresh the nomenclators and check the setup.

What stays in your application is what only it knows: turning your employees and contracts into messages, deciding which operation a change amounts to, and what to do when a message is accepted or refused. Building messages, the 42 operations and the rules of the registry are described in the [core package's README](https://github.com/slash197/reges#readme); read it alongside this one.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [One registry or several](#one-registry-or-several)
- [Sending messages](#sending-messages)
- [The stored message](#the-stored-message)
- [Reading results](#reading-results)
- [Nomenclators](#nomenclators)
- [Commands](#commands)
- [Events](#events)
- [Without the outbox](#without-the-outbox)
- [Tokens, HTTP client and logging](#tokens-http-client-and-logging)
- [Personal data](#personal-data)
- [Testing your application](#testing-your-application)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [Development](#development)

## Requirements

- PHP 8.2 or newer
- Laravel 12 or 13
- A database, for the outbox table (not needed if you [switch the outbox off](#without-the-outbox))
- A cache store that is shared between your processes, for access tokens and the nomenclator copy
- The scheduler running (`php artisan schedule:work`, or cron calling `schedule:run`)

Laravel's queue is not used.

## Installation

```bash
composer require slash197/reges-laravel
php artisan migrate
```

The service provider and the `Reges` facade are discovered automatically. The migration creates one table, `reges_messages`.

Publish the config file if you want to change more than the environment variables allow, and the migration if you want to alter it:

```bash
php artisan vendor:publish --tag=reges-config
php artisan vendor:publish --tag=reges-migrations
```

## Quick start

For one company, on the REGES test environment.

**1. Credentials.** REGES uses two sets: an OAuth client id and secret, the same for every integrator (the ones for the test environment are published in the [official integration docs](https://github.com/reges-ro/integrare)), and an API key pair per employer registry, generated in the REGES employer application under *Setări → Acces → Chei API*.

```dotenv
REGES_ENVIRONMENT=test
REGES_CLIENT_ID=reges-api
REGES_CLIENT_SECRET=...
REGES_CLIENT_APPLICATION="My HR App"
REGES_CLIENT_VERSION=1.0
REGES_USERNAME=...   # the API key pair
REGES_PASSWORD=...
```

**2. Check the setup and download the nomenclators.**

```bash
php artisan reges:check
php artisan reges:nomenclators
```

**3. Schedule the commands**, in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('reges:send')->everyMinute()->withoutOverlapping();
Schedule::command('reges:results')->everyMinute()->withoutOverlapping();
Schedule::command('reges:nomenclators')->weekly();
```

**4. Queue a message** where your application registers an employee:

```php
use slash197\Reges\Data\InfoSalariat;
use slash197\Reges\Laravel\Outbox\Outbox;
use slash197\Reges\Message;
use slash197\Reges\Operation;

app(Outbox::class)->queue(
    Message::salariat(Operation::InregistrareSalariat, new InfoSalariat(
        cnp: $employee->cnp,
        nume: $employee->last_name,
        prenume: $employee->first_name,
        adresa: $employee->address,
        tipActIdentitate: 'CarteIdentitate',
        taraDomiciliu: 'România',
        nationalitate: 'România',
    )),
    sequence: "employee:{$employee->id}",
    subject: $employee,
    user: $request->user()->name,
);
```

**5. Handle the outcome** in listeners:

```php
use slash197\Reges\Laravel\Events\MessageAccepted;
use slash197\Reges\Laravel\Events\MessageRejected;

final class StoreRegesId
{
    public function handle(MessageAccepted $event): void
    {
        if ($event->message->operation === 'InregistrareSalariat') {
            $event->message->subject?->update(['reges_id' => $event->message->ref]);
        }
    }
}

final class FlagRegesRejection
{
    public function handle(MessageRejected $event): void
    {
        $event->message->subject?->update(['reges_error' => $event->message->error]);
    }
}
```

Within a minute or two the employee is in the registry and your model has its REGES id.

## Configuration

Everything is driven by environment variables; the published `config/reges.php` holds a few more settings.

| Variable | Default | Meaning |
|---|---|---|
| `REGES_ENVIRONMENT` | `test` | `test` or `production` |
| `REGES_CLIENT_ID`, `REGES_CLIENT_SECRET` | none | OAuth client issued by REGES for your software |
| `REGES_CLIENT_APPLICATION` | `APP_NAME` | Name of your software, reported in every message header |
| `REGES_CLIENT_VERSION` | `1.0` | Version of your software |
| `REGES_USER` | none | Default name of the person on whose behalf messages are sent |
| `REGES_USERNAME`, `REGES_PASSWORD` | none | API key of the one registry, when there is only one |
| `REGES_OUTBOX` | `true` | Whether the package keeps the outbox table and sends from it |
| `REGES_CONSUMER_ID` | none | Consumer id the result queues are read as |
| `REGES_CACHE_STORE` | default store | Cache store that keeps the access tokens |
| `REGES_NOMENCLATOR_CACHE_STORE` | `REGES_CACHE_STORE` | Cache store that keeps the nomenclator copy |
| `REGES_LOG_CHANNEL` | default channel | Log channel that receives request metadata |
| `REGES_API_URL`, `REGES_TOKEN_URL` | per environment | Overrides for the API and token hosts |

| Config key | Default | Meaning |
|---|---|---|
| `reges.outbox.table` | `reges_messages` | Name of the outbox table; change it before migrating |
| `reges.outbox.limit` | `100` | Most messages `reges:send` attempts in one run |
| `reges.outbox.retry_delay` | `60` | Seconds between attempts after a transient failure |
| `reges.outbox.give_up_after` | `259200` (3 days) | Seconds after which an undelivered message is given up on; `0` never gives up |
| `reges.outbox.sending_timeout` | `600` | Seconds after which a message left mid-send by a dead process is sent again |
| `reges.results.limit` | `500` | Most results `reges:results` reads per registry in one run |
| `reges.http.timeout`, `reges.http.connect_timeout` | `30`, `10` | Timeouts of the built-in HTTP client, in seconds |
| `reges.tokens.prefix`, `reges.nomenclators.prefix` | `reges:token:`, `reges:nomenclator:` | Cache key prefixes |

**Who is sending.** REGES records two identities with each message. The *author* is the API key. The *user* is a free-text name of the person in your application: pass it per message (`user:`), or set `REGES_USER` as the default. A message with neither is refused before it is stored.

**Production.** Set `REGES_ENVIRONMENT=production` and the client id and secret REGES issued for production. The production token URL in the core package was derived by analogy with the test one; if authentication fails there, set `REGES_TOKEN_URL`.

## One registry or several

An API key belongs to exactly one employer registry. The bridge refers to a registry by a **registry key**, a string your application chooses.

### One registry

With `REGES_USERNAME` and `REGES_PASSWORD` set there is one registry, called `default`, and you never need to name it. The client is in the container and behind a facade:

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

```php
use slash197\Reges\Laravel\Facades\Reges;

$nomenclator = Reges::nomenclators()->get('TemeiIncetare');
```

### Several registries

A SaaS, a payroll bureau or a group of companies has one API key per company. Tell the bridge where the keys are by binding a `CredentialsProvider`; the registry key is then whatever identifies a company to you, typically its id:

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
```

```php
// in a service provider's register()
$this->app->singleton(CredentialsProvider::class, CompanyCredentials::class);
```

Leave `REGES_USERNAME` and `REGES_PASSWORD` empty. Throw from `credentials()` for a company that has no key or has REGES switched off: `queue()` then fails for that company, and messages it already has in the outbox stay pending and are retried, so they go out once the key is back.

Pass the registry key wherever the bridge takes one, and get a client for a registry directly when you need it:

```php
use slash197\Reges\Laravel\Registries;

$reges = app(Registries::class)->client((string) $company->id);
```

Things worth knowing:

- Store API keys encrypted, for example with Laravel's `encrypted` cast.
- Tokens are cached under a key derived from the token URL, the client id and the API username, so registries never share a token.
- A client is built once per registry key per request or command. After changing a company's key in a long-running process, call `app(Registries::class)->forget()`.
- The OAuth client, the environment and the application name are the same for all registries.

## Sending messages

### Queueing

```php
use slash197\Reges\Laravel\Outbox\Outbox;

$queued = app(Outbox::class)->queue(
    $message,
    sequence: "contract:{$contract->id}",
    registry: (string) $company->id,
    subject: $contract,
    meta: ['addendum_id' => $addendum->id],
    user: $request->user()->name,
);
```

| Argument | Purpose |
|---|---|
| `$message` | A `slash197\Reges\Message`, built as the core README describes |
| `sequence` | Messages sharing it are sent strictly in order; see below. `null` sends independently |
| `registry` | The registry key; leave out with a single registry |
| `subject` | The Eloquent model the message is about; you get it back with the outcome |
| `meta` | An array of anything else you want back with the outcome |
| `user` | The person on whose behalf the message is sent; falls back to `REGES_USER` |

`queue()` validates the message, puts it in its final wire form and stores it. It makes no HTTP request, so it is safe to call inside a web request or a database transaction. It returns the [stored message](#the-stored-message).

If the message lacks something its operation needs, or a value is not in the nomenclators, `queue()` throws and stores nothing:

```php
use slash197\Reges\Exception\ValidationException;

try {
    $outbox->queue($message, sequence: "contract:{$contract->id}");
} catch (ValidationException $exception) {
    $exception->fields(); // ['continut.salariu', 'continut.cor.cod', ...]
    $exception->errors;   // field => the rule it broke: 'required', 'uuid', 'max:256', 'in:nationalitate'
}
```

### Sequences

REGES processes messages in the order it receives them and refuses a contract's messages that arrive out of order. Messages queued with the same `sequence` are therefore sent **one at a time, in the order they were queued, each waiting for the result of the one before**.

- Use one sequence per employee and one per contract, for example `"employee:{$id}"` and `"contract:{$id}"`. Sequences are per registry.
- A message carries the timestamp of the moment it was queued, and REGES compares those. Queue messages in the order the changes happened.
- A rejected, failed or cancelled message does not hold back the ones after it.
- Messages in different sequences, or with no sequence, do not wait for each other.

### Messages that depend on an earlier result

A contract needs the REGES id of its employee, and a change to a contract needs the REGES id of the contract. Those ids only exist once the earlier message has been accepted, so queue the dependent message from your `MessageAccepted` listener:

```php
public function handle(MessageAccepted $event): void
{
    $message = $event->message;

    if ($message->operation === 'InregistrareSalariat') {
        $employee = $message->subject;
        $employee->update(['reges_id' => $message->ref]);

        foreach ($employee->contracts()->whereNull('reges_id')->get() as $contract) {
            $this->outbox->queue(
                Message::contract(Operation::AdaugareContract, continut: $this->continut($contract)),
                sequence: "contract:{$contract->id}",
                registry: $message->registry,
                subject: $contract,
            );
        }
    }
}
```

What `ref` identifies depends on the operation: the new employee, the new contract, the new proposal, or the history entry a contract operation created. The table is in the [core README](https://github.com/slash197/reges#reading-results).

### Sending

`reges:send` sends every message that is due, oldest first, and `reges:results` records the outcomes. Schedule both every minute, as in the [quick start](#quick-start). On the test environment a result typically arrives within a second or two of sending, so a message is usually resolved on the run after the one that sent it.

A message goes through these statuses:

| Status | Meaning |
|---|---|
| `pending` | Waiting to be sent, for the first time or again after a transient failure |
| `sending` | Being sent right now |
| `sent` | Queued by REGES; its result has not arrived yet |
| `accepted` | REGES carried the operation out |
| `rejected` | REGES refused it, on submission or in its result |
| `failed` | It could not be delivered before the retry window closed |
| `cancelled` | Withdrawn by your application before it was sent |

### Retries and failures

| What happens | What the outbox does |
|---|---|
| No response, a 5xx, 408 or 429 | Back to `pending`; retried after `retry_delay`, which doubles after the 60th attempt up to 12 hours |
| Credentials refused, unknown registry, or any other error while sending | The same, and the exception is reported to your exception handler |
| REGES refuses the submission (any other 4xx, typically a 400 with schema problems) | `rejected`, `MessageRejected` is dispatched; not retried |
| REGES accepts the submission, then reports a failure in its result | `rejected`, `MessageRejected` is dispatched |
| Still undelivered `give_up_after` seconds after it was queued | `failed`, `MessageFailed` is dispatched |
| The process dies mid-send | Sent again after `sending_timeout`, with the same message id, because whether REGES received it is unknown |

To send something again after a rejection, fix the data and queue a new message. A stored message is never rebuilt, since its timestamp and content are final.

## The stored message

`queue()` returns a `slash197\Reges\Laravel\Outbox\OutboxMessage`, an Eloquent model, and the events carry the same model.

| Attribute | Meaning |
|---|---|
| `id` | Auto-incrementing; the order within a sequence |
| `registry` | The registry key |
| `message_id` | The id REGES matches the result by |
| `operation` | The operation name, e.g. `AdaugareContract` |
| `sequence` | The sequence it was queued in, if any |
| `subject` | The model you passed, as a `morphTo` relation (`subject_type`, `subject_id`) |
| `meta` | The array you passed |
| `status` | A `MessageStatus` enum case |
| `payload` | The message as sent, JSON; `envelope()` returns it as an `Envelope` |
| `attempts`, `last_attempt_at`, `next_attempt_at` | Delivery bookkeeping |
| `sent_at`, `resolved_at` | When REGES queued it, and when its outcome was recorded |
| `response_id` | The id REGES gave the submission |
| `ref`, `sec_ref` | The ids REGES returned with the result |
| `result_code`, `result_description` | The outcome as REGES words it |
| `error` | Why the last attempt failed or why the message was refused |

Give your own models the inverse relation to show the REGES state of a record:

```php
use Illuminate\Database\Eloquent\Relations\MorphMany;
use slash197\Reges\Laravel\Outbox\OutboxMessage;

public function regesMessages(): MorphMany
{
    return $this->morphMany(OutboxMessage::class, 'subject');
}
```

```php
$latest = $contract->regesMessages()->latest('id')->first();

$latest?->status;   // MessageStatus::Sent
$latest?->error;    // "Contractul ... nu poate fi modificat ..."
```

A message that has not been sent yet can be withdrawn; `cancel()` returns `false` once it is too late:

```php
$queued->cancel();
```

Old messages are not pruned. Delete what you no longer need yourself, keeping in mind that the `ref` of an accepted message is the only record of that id unless you copied it to your own tables.

## Reading results

`reges:results` reads the result queue of the configured registry and of every registry that has a message awaiting its result. For each result it dispatches a `ResultReceived` event; the outbox listens to it, matches the result to its stored message and dispatches `MessageAccepted` or `MessageRejected`.

You only need `ResultReceived` yourself to see every result, including those of messages your application did not send through the outbox:

```php
use slash197\Reges\Laravel\Events\ResultReceived;

final class LogRegesResult
{
    public function handle(ResultReceived $event): void
    {
        $event->result;    // slash197\Reges\Results\Result
        $event->registry;  // the registry key
    }
}
```

**Nothing is lost, and listeners may run twice.** The queue moves past a result only after every listener has returned, including your `MessageAccepted` and `MessageRejected` listeners. If one throws, that registry's run stops, the exception is reported, and the same result is delivered again on the next run. Two consequences:

- write listeners so that handling the same outcome twice does no harm;
- a result whose listener always throws holds back the results behind it, so fix or catch such errors rather than letting them repeat.

A listener that implements `ShouldQueue` counts as done once it is on your queue.

**Sharing a registry.** Each reader of a registry's queue has its own position, identified by a consumer id. If another application, or another environment of yours, reads the same registry, give each its own `REGES_CONSUMER_ID`, or they take each other's results. A new consumer id starts from the beginning of the queue.

**Options.** `--registry=KEY` (repeatable) reads only the registries named, `--consumer=ID` overrides the consumer id, and `--limit=N` caps the results read per registry in one run.

**Your own loop.** `ResultReader` is what the command uses, if you want to read a queue from your own code:

```php
use slash197\Reges\Laravel\Registries;
use slash197\Reges\Laravel\ResultReader;

$read = app(ResultReader::class)->drain(
    app(Registries::class)->client($registry),
    $registry,
    limit: 50,
);
```

## Nomenclators

The nomenclators are the reference lists REGES validates against. With a local copy, the client checks three kinds of value before a message is stored or sent: country names, identity document types and locality (SIRUTA) codes.

```bash
php artisan reges:nomenclators
```

- Until the first download those checks are skipped; everything else is still validated.
- The lists change, so refresh the copy on a schedule. An empty answer from REGES leaves the existing copy untouched.
- The nomenclators are public: the command works without an API key.
- The copy lives in the cache. `php artisan cache:clear` removes it, and the checks are skipped again until the next download. Set `REGES_NOMENCLATOR_CACHE_STORE` to a store you do not clear if that matters to you.
- To check against your own copy instead, bind `slash197\Reges\Validation\NomenclatorLookup` in the container.

Countries are referred to by **name**, spelled exactly as the nomenclator spells it (`România`, not `Romania`); most other fields take a code. To fill your own dropdowns, fetch any list through the client: `Reges::nomenclators()->get('TemeiIncetare')`.

## Commands

| Command | What it does |
|---|---|
| `reges:check` | Read-only check of the configured registry: authentication, the employer profile, a look at the result queue without advancing it, and whether a nomenclator copy exists |
| `reges:send` | Sends the stored messages that are due. `--limit=N` caps the attempts in one run |
| `reges:results` | Reads the result queues and dispatches the events. `--registry=KEY`, `--consumer=ID`, `--limit=N` |
| `reges:nomenclators` | Downloads the nomenclators |

`reges:send` is registered only while the outbox is enabled. `reges:results` exits with a failure code if any registry could not be read, after reading the others.

## Events

All in `slash197\Reges\Laravel\Events`.

| Event | Properties | When |
|---|---|---|
| `MessageAccepted` | `message`, `result` | REGES carried the operation out. `$message->ref` is the id of what it created |
| `MessageRejected` | `message`, `result` (null when refused on submission) | REGES refused the message. `$message->error` says why |
| `MessageFailed` | `message` | The message could not be delivered within the retry window. `$message->error` holds the last failure |
| `ResultReceived` | `result`, `registry` | Any result was read from a registry's queue |

## Without the outbox

If your application already has its own way of storing and ordering messages, set `REGES_OUTBOX=false`. No table is created and `reges:send` is not registered. You still get the configured client, the registries, token caching, the nomenclator copy, `reges:check` and `reges:results`:

```php
$envelope = $reges->envelope($message, $user);   // validated and final, not sent
// store $envelope->messageId() and $envelope->toJson() yourself

$receipt = $reges->send(Envelope::fromJson($json));
```

and match results in a `ResultReceived` listener by `$event->result->messageId`. With the outbox off, `reges:results` reads the configured registry, or the ones you name with `--registry`.

You can also mix the two: a message sent directly through the client simply has no stored counterpart, and its result reaches you through `ResultReceived` only.

## Tokens, HTTP client and logging

**Tokens.** Access tokens are kept in the cache, encrypted with the application key, until they expire. To keep them elsewhere, implement `slash197\Reges\Auth\TokenStore` and bind it:

```php
$this->app->singleton(\slash197\Reges\Auth\TokenStore::class, DatabaseTokenStore::class);
```

To build a single client with a store of its own, outside the registries, use the factory: `app(RegesFactory::class)->make($credentials, $tokenStore)`.

**HTTP client.** Requests go through a Guzzle client with the timeouts from `reges.http`. If your application binds `Psr\Http\Client\ClientInterface` in the container, that client is used instead, and timeouts, proxies and retries are yours to configure on it.

**Logging.** The client logs one line per request to `REGES_LOG_CHANNEL`: method, endpoint, status and the request id REGES returns. Message contents are never logged.

## Personal data

Messages to REGES carry CNPs, names and addresses, and so do its answers (a result reads, for example, "Salariatul … cu CNP … a fost adăugat cu succes"). The package therefore:

- encrypts `payload`, `error` and `result_description` in the outbox table with the application key;
- encrypts cached access tokens;
- never logs message contents.

Rotating `APP_KEY` makes stored messages unreadable unless you keep the old key in `APP_PREVIOUS_KEYS`. `meta` is stored as plain JSON, so do not put personal data in it. The exception messages the commands print and report can quote what REGES answered.

## Testing your application

`Outbox::queue()` makes no HTTP request, and an outcome can be simulated by dispatching the result yourself, so most tests need no fake client at all.

Give the test environment an API username that is a UUID, because REGES identifies the author by it and the message is validated accordingly:

```php
config([
    'reges.client_id' => 'reges-api',
    'reges.client_secret' => 'secret',
    'reges.username' => '7d2c6c32-643d-4746-8402-003b0f420eb4',
    'reges.password' => 'secret',
    'reges.user' => 'Test User',
]);
```

Assert that your code queued the right message:

```php
$this->post("/employees/{$employee->id}/register")->assertOk();

$this->assertDatabaseHas('reges_messages', [
    'operation' => 'InregistrareSalariat',
    'sequence' => "employee:{$employee->id}",
    'status' => 'pending',
]);
```

Simulate the outcome and assert what your listeners did:

```php
use slash197\Reges\Laravel\Events\ResultReceived;
use slash197\Reges\Laravel\Outbox\OutboxMessage;
use slash197\Reges\Results\Result;

$queued = OutboxMessage::query()->latest('id')->firstOrFail();

event(new ResultReceived(Result::fromPayload([
    'header' => ['messageId' => $queued->message_id],
    'result' => ['code' => 'SUCCES', 'ref' => '0f3a6c1e-0000-4000-8000-000000000001'],
])));

$this->assertSame('0f3a6c1e-0000-4000-8000-000000000001', $employee->refresh()->reges_id);
```

Any `code` other than `SUCCES`, with a `description`, simulates a rejection.

To exercise the HTTP layer as well, bind a fake PSR-18 client **before** anything resolves the REGES client, for example in `setUp()`:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;

$responses = new MockHandler([
    new Response(200, [], json_encode(['access_token' => 'token', 'expires_in' => 300])),
    new Response(200, [], json_encode(['responseId' => 'r-1'])),
]);

$this->app->instance(ClientInterface::class, new Client(['handler' => HandlerStack::create($responses)]));

$this->artisan('reges:send')->assertSuccessful();
```

A mock that has run out of responses throws, which also guarantees that a test never reaches the real REGES.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| `reges:check` says the credentials are missing | `REGES_USERNAME` and `REGES_PASSWORD` are empty. With several registries that is expected: `reges:check` covers the configured registry only |
| Authentication fails | Wrong client id or secret for the environment, or an API key from the other environment. Test and production keys are separate |
| `Unknown REGES registry "…"` | A registry key was used but no `CredentialsProvider` is bound, so only `default` exists |
| Messages stay `pending` | The scheduler is not running; or an earlier message of the same sequence is still unresolved; or sending keeps failing, in which case `error` says why |
| Messages stay `sent` | `reges:results` is not running; or another application reads the same registry with the same consumer id and takes the results |
| A message is `rejected` with a schema error | REGES refused the submission. `error` holds its answer; the core README's list of what REGES is strict about usually explains it |
| A later contract message is rejected as out of order | Messages for one contract were queued in different sequences, or queued in a different order than the changes happened |
| Values that are not in the nomenclators get through | There is no local copy; run `reges:nomenclators` (it is lost on `cache:clear`) |
| `queue()` says REGES needs the name of the user | Pass `user:` or set `REGES_USER` |
| The migration fails because `reges_messages` exists | Your application has a table of that name; set `reges.outbox.table` before migrating |
| Dates land a day early in the registry | Build calendar days with `Dates::date()`, as the core README explains |

## Contributing

Issues and pull requests are welcome, including small ones: a bug report with the operation and what REGES answered is already useful. Please leave real CNPs, company codes and credentials out of issues, tests and commits.

Before opening a pull request, run `make check`. Tests must not reach the network; the test base class installs a fake HTTP client for that reason.

Questions about messages, operations and how REGES itself behaves belong in the [core package](https://github.com/slash197/reges).

## Development

Nothing needs to be installed on the host besides Docker:

```bash
make build     # once
make install
make check     # composer validate, PHPStan level 8 and PHPUnit
```

`PHP_VERSION=8.4 make build` switches the PHP version of the container.

## Disclaimer

This is an independent, unofficial project. It is not affiliated with or endorsed by Inspecția Muncii. The official integration documentation lives at [reges-ro/integrare](https://github.com/reges-ro/integrare).

## Licence

MIT. See [LICENSE](LICENSE).
