<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use slash197\Reges\Auth\InMemoryTokenStore;
use slash197\Reges\Auth\TokenStore;
use slash197\Reges\Config;
use slash197\Reges\Credentials;
use slash197\Reges\Environment;
use slash197\Reges\Laravel\CacheTokenStore;
use slash197\Reges\Laravel\RegesFactory;
use slash197\Reges\Laravel\Tests\TestCase;
use slash197\Reges\Reges;

final class RegesServiceProviderTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    public function testBuildsTheConfigFromTheApplicationConfig(): void
    {
        config()->set('reges.client_application', ' Payroll ');
        config()->set('reges.client_version', '2.3');

        $config = $this->app()->make(Config::class);

        self::assertSame(Environment::Test, $config->environment);
        self::assertSame('reges-api', $config->clientId);
        self::assertSame('Payroll', $config->clientApplication);
        self::assertSame('2.3', $config->clientVersion);
        self::assertNull($config->user);
        self::assertSame(Environment::Test->apiUrl(), $config->apiUrl);
    }

    public function testRejectsAnUnknownEnvironment(): void
    {
        config()->set('reges.environment', 'staging');

        $this->expectException(\InvalidArgumentException::class);

        $this->app()->make(Config::class);
    }

    public function testBindsOneClientForTheConfiguredCredentials(): void
    {
        self::assertInstanceOf(CacheTokenStore::class, $this->app()->make(TokenStore::class));
        self::assertSame($this->app()->make(Reges::class), $this->app()->make(Reges::class));
    }

    public function testTheBoundClientAuthenticatesWithTheConfiguredCredentialsAndCachesTheToken(): void
    {
        $this->fakeHttp($this->tokenResponse('token-1'), $this->profileResponse('a-1'));

        $profile = $this->app()->make(Reges::class)->profile();

        self::assertSame('a-1', $profile->angajatorId);
        self::assertStringContainsString('username=key-user', (string) $this->requests[0]->getBody());
        self::assertSame('Bearer token-1', $this->requests[1]->getHeaderLine('Authorization'));

        // A second client for the same credentials reuses the cached token: no token request is queued
        $this->fakeHttp($this->profileResponse('a-1'));
        $this->app()->forgetInstance(RegesFactory::class);

        $this->app()->make(RegesFactory::class)->make(new Credentials('key-user', 'key-password'))->profile();

        self::assertSame('Bearer token-1', $this->requests[2]->getHeaderLine('Authorization'));
    }

    public function testTheFactoryBuildsAClientForRuntimeCredentialsWithItsOwnTokenStore(): void
    {
        $this->fakeHttp($this->tokenResponse('token-2'), $this->profileResponse('a-2'));
        $store = new InMemoryTokenStore();

        $profile = $this->app()->make(RegesFactory::class)
            ->make(new Credentials('tenant-user', 'tenant-password'), $store)
            ->profile();

        self::assertSame('a-2', $profile->angajatorId);
        self::assertStringContainsString('username=tenant-user', (string) $this->requests[0]->getBody());

        $cache = $this->app()->make(CacheFactory::class)->store()->getStore();
        self::assertInstanceOf(ArrayStore::class, $cache);
        self::assertSame([], $cache->all(false));
    }

    private function fakeHttp(Response ...$responses): void
    {
        $stack = HandlerStack::create(new MockHandler(array_values($responses)));
        $stack->push(Middleware::tap(function (RequestInterface $request): void {
            $this->requests[] = $request;
        }));

        $this->app()->instance(ClientInterface::class, new Client(['handler' => $stack]));
    }

    private function tokenResponse(string $accessToken): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'access_token' => $accessToken,
            'expires_in' => 300,
            'token_type' => 'Bearer',
        ]));
    }

    private function profileResponse(string $angajatorId): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'referintaAngajator' => ['id' => $angajatorId],
        ]));
    }
}
