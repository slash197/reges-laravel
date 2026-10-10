<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Event;
use slash197\Reges\Laravel\Events\ResultReceived;
use slash197\Reges\Laravel\ResultReader;
use slash197\Reges\Laravel\Tests\TestCase;
use slash197\Reges\Reges;

final class ResultReaderTest extends TestCase
{
    private const READ = 'POST /api/Status/ReadMessage';
    private const COMMIT = 'POST /api/Status/CommitRead';

    public function testDispatchesAnEventForEachResultAndCommitsItUntilTheQueueIsEmpty(): void
    {
        $received = $this->listen();
        $this->fakeHttp(
            $this->tokenResponse(),
            $this->resultResponse('m-1'),
            new Response(200),
            $this->resultResponse('m-2', 'EROARE'),
            new Response(200),
            new Response(204),
        );

        $read = $this->reader()->drain($this->app()->make(Reges::class));

        self::assertSame(2, $read);
        self::assertSame(['m-1', 'm-2'], array_map(static fn (ResultReceived $event): ?string => $event->result->messageId, $received->getArrayCopy()));
        self::assertTrue($received->getArrayCopy()[0]->result->isSuccess());
        self::assertFalse($received->getArrayCopy()[1]->result->isSuccess());
        self::assertSame('default', $received->getArrayCopy()[0]->registry);
        self::assertSame([self::READ, self::COMMIT, self::READ, self::COMMIT, self::READ], array_slice($this->sent(), 1));
    }

    public function testLeavesAResultUncommittedWhenAListenerThrows(): void
    {
        Event::listen(ResultReceived::class, static fn () => throw new \RuntimeException('could not store'));
        $this->fakeHttp($this->tokenResponse(), $this->resultResponse('m-1'), new Response(200));

        try {
            $this->reader()->drain($this->app()->make(Reges::class));
            self::fail('The listener exception should have surfaced.');
        } catch (\RuntimeException $exception) {
            self::assertSame('could not store', $exception->getMessage());
        }

        self::assertSame([self::READ], array_slice($this->sent(), 1));
    }

    public function testStopsAtTheLimit(): void
    {
        $received = $this->listen();
        $this->fakeHttp($this->tokenResponse(), $this->resultResponse('m-1'), new Response(200), $this->resultResponse('m-2'));

        self::assertSame(1, $this->reader()->drain($this->app()->make(Reges::class), limit: 1));
        self::assertCount(1, $received);
    }

    public function testPassesOnTheRegistryLabelAndTheConsumerId(): void
    {
        $received = $this->listen();
        $this->fakeHttp($this->tokenResponse(), $this->resultResponse('m-1'), new Response(200), new Response(204));

        $this->reader()->drain($this->app()->make(Reges::class), registry: 'company-7', consumerId: 'hr-app');

        self::assertSame('company-7', $received->getArrayCopy()[0]->registry);
        self::assertSame('consumerId=hr-app', $this->requests[1]->getUri()->getQuery());
        self::assertSame('consumerId=hr-app', $this->requests[2]->getUri()->getQuery());
    }

    public function testTheCommandReadsTheQueueOfTheConfiguredRegistry(): void
    {
        $received = $this->listen();
        config()->set('reges.results.consumer_id', 'from-config');
        $this->fakeHttp($this->tokenResponse(), $this->resultResponse('m-1'), new Response(200), new Response(204));

        $this->command('reges:results')->expectsOutputToContain('1 result')->assertSuccessful();

        self::assertCount(1, $received);
        self::assertSame('consumerId=from-config', $this->requests[1]->getUri()->getQuery());
    }

    public function testTheCommandTakesTheConsumerAndLimitAsOptions(): void
    {
        $this->listen();
        $this->fakeHttp($this->tokenResponse(), $this->resultResponse('m-1'), new Response(200), $this->resultResponse('m-2'));

        $this->command('reges:results', ['--consumer' => 'other', '--limit' => 1])
            ->expectsOutputToContain('1 result')
            ->assertSuccessful();

        self::assertSame('consumerId=other', $this->requests[1]->getUri()->getQuery());
    }

    private function reader(): ResultReader
    {
        return $this->app()->make(ResultReader::class);
    }

    /**
     * @return \ArrayObject<int, ResultReceived>
     */
    private function listen(): \ArrayObject
    {
        /** @var \ArrayObject<int, ResultReceived> $received */
        $received = new \ArrayObject();

        Event::listen(ResultReceived::class, static function (ResultReceived $event) use ($received): void {
            $received->append($event);
        });

        return $received;
    }

    private function resultResponse(string $messageId, string $code = 'SUCCES'): Response
    {
        return $this->jsonResponse(['header' => ['messageId' => $messageId], 'result' => ['code' => $code]]);
    }
}
