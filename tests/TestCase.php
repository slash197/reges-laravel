<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Application;
use Illuminate\Testing\PendingCommand;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Orchestra\Testbench\TestCase as Orchestra;
use slash197\Reges\Laravel\RegesServiceProvider;

abstract class TestCase extends Orchestra
{
    /** @var list<RequestInterface> */
    protected array $requests = [];

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
        $app['config']->set('reges.username', 'key-user');
        $app['config']->set('reges.password', 'key-password');
    }

    /**
     * Makes every client built from here on answer with these responses, in order.
     */
    protected function fakeHttp(Response ...$responses): void
    {
        $stack = HandlerStack::create(new MockHandler(array_values($responses)));
        $stack->push(Middleware::tap(function (RequestInterface $request): void {
            $this->requests[] = $request;
        }));

        $this->app()->instance(ClientInterface::class, new Client(['handler' => $stack]));
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
