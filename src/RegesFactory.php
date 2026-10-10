<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel;

use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use slash197\Reges\Auth\TokenStore;
use slash197\Reges\Config;
use slash197\Reges\Credentials;
use slash197\Reges\Reges;
use slash197\Reges\Validation\NomenclatorLookup;

/**
 * Builds a client for one registry from credentials known only at runtime,
 * sharing the application's REGES configuration, HTTP client and logger.
 */
final class RegesFactory
{
    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $httpClient,
        private readonly TokenStore $tokenStore,
        private readonly LoggerInterface $logger,
        private readonly ?NomenclatorLookup $nomenclatorLookup = null,
    ) {
    }

    /**
     * @param TokenStore|null $tokenStore Replaces the configured store for this client
     */
    public function make(Credentials $credentials, ?TokenStore $tokenStore = null): Reges
    {
        return new Reges(
            $this->config,
            $credentials,
            $this->httpClient,
            $tokenStore ?? $this->tokenStore,
            $this->logger,
            $this->nomenclatorLookup,
        );
    }
}
