<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel;

use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use slash197\Reges\Auth\TokenStore;
use slash197\Reges\Config;
use slash197\Reges\Credentials;
use slash197\Reges\Environment;
use slash197\Reges\Laravel\Console\CheckCommand;
use slash197\Reges\Laravel\Console\NomenclatorsCommand;
use slash197\Reges\Laravel\Console\ResultsCommand;
use slash197\Reges\Reges;
use slash197\Reges\Validation\NomenclatorLookup;

final class RegesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/reges.php', 'reges');

        $this->app->singletonIf(Config::class, function (Application $app): Config {
            $config = $app->make(ConfigRepository::class);
            $environment = self::string($config, 'environment');

            return new Config(
                Environment::tryFrom($environment)
                    ?? throw new \InvalidArgumentException("Unknown REGES environment \"{$environment}\"; use \"test\" or \"production\"."),
                self::string($config, 'client_id'),
                self::string($config, 'client_secret'),
                self::string($config, 'client_application'),
                self::string($config, 'client_version'),
                self::string($config, 'user') ?: null,
                self::string($config, 'api_url') ?: null,
                self::string($config, 'token_url') ?: null,
            );
        });

        $this->app->singletonIf(TokenStore::class, function (Application $app): TokenStore {
            $config = $app->make(ConfigRepository::class);

            return new CacheTokenStore(
                $app->make(CacheFactory::class)->store(self::string($config, 'tokens.store') ?: null),
                $app->make(Encrypter::class),
                self::string($config, 'tokens.prefix'),
            );
        });

        $this->app->singleton(CacheNomenclatorLookup::class, function (Application $app): CacheNomenclatorLookup {
            $config = $app->make(ConfigRepository::class);

            return new CacheNomenclatorLookup(
                $app->make(CacheFactory::class)->store(self::string($config, 'nomenclators.store') ?: null),
                self::string($config, 'nomenclators.prefix'),
            );
        });

        $this->app->bindIf(NomenclatorLookup::class, CacheNomenclatorLookup::class);

        $this->app->singleton(RegesFactory::class, function (Application $app): RegesFactory {
            $config = $app->make(ConfigRepository::class);
            $channel = self::string($config, 'log_channel');

            return new RegesFactory(
                $app->make(Config::class),
                $app->bound(ClientInterface::class)
                    ? $app->make(ClientInterface::class)
                    : new Client([
                        'timeout' => $config->get('reges.http.timeout'),
                        'connect_timeout' => $config->get('reges.http.connect_timeout'),
                    ]),
                $app->make(TokenStore::class),
                $channel !== ''
                    ? $app->make(LogManager::class)->channel($channel)
                    : $app->make(LoggerInterface::class),
                $app->make(NomenclatorLookup::class),
            );
        });

        $this->app->singleton(Reges::class, function (Application $app): Reges {
            $config = $app->make(ConfigRepository::class);

            return $app->make(RegesFactory::class)->make(
                new Credentials(self::string($config, 'username'), self::string($config, 'password')),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/reges.php' => $this->app->configPath('reges.php')], 'reges-config');

            $this->commands([CheckCommand::class, NomenclatorsCommand::class, ResultsCommand::class]);
        }
    }

    private static function string(ConfigRepository $config, string $key): string
    {
        $value = $config->get("reges.{$key}");

        return is_scalar($value) ? (string) $value : '';
    }
}
