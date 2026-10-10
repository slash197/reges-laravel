# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). While the version is 0.x, a minor release may contain breaking changes.

## [Unreleased]

### Changed

- The outbox now encrypts `error` and `result_description` as well as the message body, because what REGES answers quotes names and CNPs. Rows written by 0.2.0 hold these two columns in plain text and cannot be read back through the model; clear the columns or the rows when upgrading.
- The README covers the whole package: a quick start, the life of a stored message, retries, testing and troubleshooting.

## [0.2.0] - 2026-10-10

### Added

- An outbox: `Outbox::queue()` stores a validated message, `reges:send` sends what is due while keeping each sequence in order and retrying transient failures, and results are matched back to the stored message. `MessageAccepted`, `MessageRejected` and `MessageFailed` announce the outcome. It comes with a `reges_messages` migration and can be switched off with `REGES_OUTBOX=false`.
- `CredentialsProvider` and `Registries`, for applications with an API key per tenant: every part of the bridge addresses a registry by a key the application chooses.
- `reges:results` and `ResultReader`, which read the result queues and dispatch a `ResultReceived` event for each result, committing it only after its listeners returned.
- `reges:nomenclators` and `CacheNomenclatorLookup`, a local copy of the nomenclators that every client now validates against once it has been downloaded.
- `reges:check`, which verifies the configuration with read-only calls.
- A `Reges` facade for the container-bound client.

## [0.1.0] - 2026-10-10

First release.

### Added

- Service provider with a publishable `config/reges.php`, binding `slash197\Reges\Reges` in the container for the configured API key.
- `RegesFactory`, which builds a client for credentials supplied at runtime, with an optional token store of its own.
- `CacheTokenStore`, which keeps access tokens encrypted in a Laravel cache store.
- The client logs to a Laravel log channel and sends requests through the application's PSR-18 client, or Guzzle when none is bound.

[0.2.0]: https://github.com/slash197/reges-laravel/releases/tag/v0.2.0
[0.1.0]: https://github.com/slash197/reges-laravel/releases/tag/v0.1.0
