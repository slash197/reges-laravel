# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). While the version is 0.x, a minor release may contain breaking changes.

## [Unreleased]

### Added

- `reges:results` and `ResultReader`, which read a registry's result queue and dispatch a `ResultReceived` event for each result, committing it only after its listeners returned.
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

[0.1.0]: https://github.com/slash197/reges-laravel/releases/tag/v0.1.0
