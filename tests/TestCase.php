<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Orchestra\Testbench\TestCase as Orchestra;
use slash197\Reges\Laravel\RegesServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected const USERNAME = '7d2c6c32-643d-4746-8402-003b0f420eb4';

    /** @var list<RequestInterface> */
    protected array $requests = [];

    private MockHandler $http;

    /**
     * No test reaches the network: every client gets a fake that fails once
     * it runs out of queued responses.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new MockHandler();
        $stack = HandlerStack::create($this->http);
        $stack->push(Middleware::tap(function (RequestInterface $request): void {
            $this->requests[] = $request;
        }));

        $this->app()->instance(ClientInterface::class, new Client(['handler' => $stack]));
    }

    protected function app(): Application
    {
        self::assertNotNull($this->app);

        return $this->app;
    }

    protected function getPackageProviders($app): array
    {
        return [RegesServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('reges.client_id', 'reges-api');
        $app['config']->set('reges.client_secret', 'secret');
        $app['config']->set('reges.username', self::USERNAME);
        $app['config']->set('reges.user', 'Maria Ionescu');
        $app['config']->set('reges.password', 'key-password');
    }

    /**
     * Queues the responses the fake HTTP client answers with next, in order.
     */
    protected function fakeHttp(Response ...$responses): void
    {
        $this->http->append(...array_values($responses));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    protected function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    /**
     * @param array<mixed> $body
     */
    protected function jsonResponse(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    protected function tokenResponse(string $accessToken = 'token'): Response
    {
        return $this->jsonResponse(['access_token' => $accessToken, 'expires_in' => 300, 'token_type' => 'Bearer']);
    }

    protected function profileResponse(string $angajatorId): Response
    {
        return $this->jsonResponse(['referintaAngajator' => ['id' => $angajatorId]]);
    }

    /**
     * @return list<string> Method and path of each request sent so far
     */
    protected function sent(): array
    {
        return array_map(
            static fn (RequestInterface $request): string => $request->getMethod() . ' ' . $request->getUri()->getPath(),
            $this->requests,
        );
    }
}
