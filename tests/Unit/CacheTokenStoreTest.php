<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Tests\Unit;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use slash197\Reges\Auth\Token;
use slash197\Reges\Laravel\CacheTokenStore;
use slash197\Reges\Laravel\Tests\TestCase;

final class CacheTokenStoreTest extends TestCase
{
    private Repository $cache;
    private CacheTokenStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = $this->app()->make(Repository::class);
        $this->store = new CacheTokenStore($this->cache, $this->app()->make(Encrypter::class), 'prefix:');
    }

    public function testReturnsTheTokenItStored(): void
    {
        $expiresAt = new \DateTimeImmutable('+5 minutes');
        $this->store->put('key', new Token('access', $expiresAt, 'refresh', 'Bearer'));

        $token = $this->store->get('key');

        self::assertNotNull($token);
        self::assertSame('access', $token->accessToken);
        self::assertSame('refresh', $token->refreshToken);
        self::assertSame('Bearer', $token->tokenType);
        self::assertSame($expiresAt->getTimestamp(), $token->expiresAt?->getTimestamp());
    }

    public function testKeepsTheTokenEncryptedInTheCache(): void
    {
        $this->store->put('key', new Token('access', new \DateTimeImmutable('+5 minutes'), 'refresh'));

        $cached = $this->cache->get('prefix:key');

        self::assertIsString($cached);
        self::assertStringNotContainsString('access', $cached);
        self::assertStringNotContainsString('refresh', $cached);
    }

    public function testForgetsTheTokenOnceItExpires(): void
    {
        $this->store->put('key', new Token('access', new \DateTimeImmutable('+5 minutes')));

        $this->travel(6)->minutes();

        self::assertNull($this->store->get('key'));
    }

    public function testDoesNotStoreATokenWithoutAnExpiry(): void
    {
        $this->store->put('key', new Token('access'));

        self::assertFalse($this->cache->has('prefix:key'));
    }

    public function testIgnoresAValueItCannotDecrypt(): void
    {
        $this->cache->put('prefix:key', 'not-encrypted', 60);

        self::assertNull($this->store->get('key'));
    }

    public function testReturnsNullForAnUnknownKey(): void
    {
        self::assertNull($this->store->get('missing'));
    }
}
