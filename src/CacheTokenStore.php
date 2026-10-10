<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use slash197\Reges\Auth\Token;
use slash197\Reges\Auth\TokenStore;

/**
 * Keeps access tokens in a Laravel cache store, encrypted with the application key.
 */
final class CacheTokenStore implements TokenStore
{
    public function __construct(
        private readonly Repository $cache,
        private readonly Encrypter $encrypter,
        private readonly string $prefix = 'reges:token:',
    ) {
    }

    public function get(string $key): ?Token
    {
        $payload = $this->cache->get($this->prefix . $key);

        if (!is_string($payload)) {
            return null;
        }

        try {
            $data = $this->encrypter->decrypt($payload);
        } catch (DecryptException) {
            // Written with another application key; a new token replaces it
            return null;
        }

        return is_array($data) ? Token::fromArray($data) : null;
    }

    public function put(string $key, Token $token): void
    {
        // The client never reuses a token without a known expiry
        if ($token->expiresAt === null) {
            return;
        }

        $this->cache->put($this->prefix . $key, $this->encrypter->encrypt($token->toArray()), $token->expiresAt);
    }
}
