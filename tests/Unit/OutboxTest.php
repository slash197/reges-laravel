<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use slash197\Reges\Credentials;
use slash197\Reges\Data\InfoSalariat;
use slash197\Reges\Exception\ValidationException;
use slash197\Reges\Laravel\CredentialsProvider;
use slash197\Reges\Laravel\Events\MessageAccepted;
use slash197\Reges\Laravel\Events\MessageFailed;
use slash197\Reges\Laravel\Events\MessageRejected;
use slash197\Reges\Laravel\Outbox\MessageStatus;
use slash197\Reges\Laravel\Outbox\Outbox;
use slash197\Reges\Laravel\Outbox\OutboxMessage;
use slash197\Reges\Laravel\Outbox\OutboxSender;
use slash197\Reges\Laravel\Tests\TestCase;
use slash197\Reges\Message;
use slash197\Reges\Operation;

final class OutboxTest extends TestCase
{
    private const SEND = 'POST /api/Salariat';
    private const READ = 'POST /api/Status/ReadMessage';

    public function testQueueStoresTheValidatedMessageEncrypted(): void
    {
        $queued = $this->outbox()->queue($this->message(), sequence: 'employee:7', meta: ['employee_id' => 7]);

        self::assertSame(MessageStatus::Pending, $queued->status);
        self::assertSame('default', $queued->registry);
        self::assertSame('InregistrareSalariat', $queued->operation);
        self::assertSame(['employee_id' => 7], $queued->meta);
        self::assertSame($queued->message_id, $queued->envelope()->messageId());
        self::assertStringContainsString('1800612015459', $queued->payload);

        $stored = DB::table('reges_messages')->value('payload');
        self::assertIsString($stored);
        self::assertStringNotContainsString('1800612015459', $stored);
        self::assertSame([], $this->requests);
    }

    public function testQueueStoresNothingWhenTheMessageIsInvalid(): void
    {
        try {
            $this->outbox()->queue(Message::salariat(Operation::InregistrareSalariat, new InfoSalariat()));
            self::fail('The empty employee should have been rejected.');
        } catch (ValidationException $exception) {
            self::assertContains('info.cnp', $exception->fields());
        }

        self::assertSame(0, OutboxMessage::query()->count());
    }

    public function testSendsADueMessageExactlyAsStored(): void
    {
        $queued = $this->outbox()->queue($this->message());
        $this->fakeHttp($this->tokenResponse(), $this->receipt('r-1'));

        self::assertSame(1, $this->sender()->sendDue());

        $queued->refresh();
        self::assertSame(MessageStatus::Sent, $queued->status);
        self::assertSame('r-1', $queued->response_id);
        self::assertSame(1, $queued->attempts);
        self::assertNotNull($queued->sent_at);
        self::assertSame(self::SEND, $this->sent()[1]);
        self::assertSame($queued->payload, (string) $this->requests[1]->getBody());
    }

    public function testAMessageWaitsForTheResultOfTheOneBeforeItInItsSequence(): void
    {
        $first = $this->outbox()->queue($this->message(), sequence: 'employee:7');
        $second = $this->outbox()->queue($this->message(), sequence: 'employee:7');
        $other = $this->outbox()->queue($this->message(), sequence: 'employee:8');
        $this->fakeHttp(
            $this->tokenResponse(),
            $this->receipt('r-1'),
            $this->receipt('r-other'),
            $this->resultResponse((string) $first->message_id),
            new Response(200),
            new Response(204),
            $this->receipt('r-2'),
        );

        self::assertSame(2, $this->sender()->sendDue());
        self::assertSame(MessageStatus::Sent, $first->refresh()->status);
        self::assertSame(MessageStatus::Pending, $second->refresh()->status);
        self::assertSame(MessageStatus::Sent, $other->refresh()->status);

        self::assertSame(0, $this->sender()->sendDue());

        $this->command('reges:results')->assertSuccessful();
        self::assertSame(MessageStatus::Accepted, $first->refresh()->status);

        self::assertSame(1, $this->sender()->sendDue());
        self::assertSame('r-2', $second->refresh()->response_id);
    }

    public function testRetriesATransientFailureAfterTheDelay(): void
    {
        $queued = $this->outbox()->queue($this->message());
        $this->fakeHttp($this->tokenResponse(), new Response(503), $this->receipt('r-1'));

        self::assertSame(0, $this->sender()->sendDue());
        self::assertSame(MessageStatus::Pending, $queued->refresh()->status);
        self::assertSame('REGES message submission failed with HTTP 503.', $queued->error);

        self::assertSame(0, $this->sender()->sendDue());
        self::assertCount(2, $this->requests);

        $this->travel(61)->seconds();

        self::assertSame(1, $this->sender()->sendDue());
        self::assertSame(MessageStatus::Sent, $queued->refresh()->status);
        self::assertSame(2, $queued->attempts);
        self::assertNull($queued->error);
    }

    public function testAMessageRegesRefusesOnSubmissionIsRejectedAndDoesNotHoldBackTheNext(): void
    {
        Event::fake([MessageRejected::class]);
        $first = $this->outbox()->queue($this->message(), sequence: 'employee:7');
        $second = $this->outbox()->queue($this->message(), sequence: 'employee:7');
        $this->fakeHttp($this->tokenResponse(), new Response(400, [], '{"errors":["schema"]}'), $this->receipt('r-2'));

        self::assertSame(1, $this->sender()->sendDue());

        self::assertSame(MessageStatus::Rejected, $first->refresh()->status);
        self::assertStringContainsString('HTTP 400', (string) $first->error);
        self::assertStringContainsString('schema', (string) $first->error);
        self::assertSame(MessageStatus::Sent, $second->refresh()->status);
        Event::assertDispatched(
            MessageRejected::class,
            static fn (MessageRejected $event): bool => $event->message->is($first) && $event->result === null,
        );
    }

    public function testGivesUpOnAMessageOnceTheRetryWindowHasClosed(): void
    {
        Event::fake([MessageFailed::class]);
        $queued = $this->outbox()->queue($this->message());
        $this->fakeHttp();

        $this->travel(4)->days();

        self::assertSame(0, $this->sender()->sendDue());
        self::assertSame(MessageStatus::Failed, $queued->refresh()->status);
        self::assertSame([], $this->requests);
        Event::assertDispatched(MessageFailed::class);
    }

    public function testSendsAgainAMessageLeftInSendingByADeadProcess(): void
    {
        $queued = $this->outbox()->queue($this->message());
        $queued->update(['status' => MessageStatus::Sending, 'attempts' => 1, 'last_attempt_at' => now()]);
        $this->fakeHttp($this->tokenResponse(), $this->receipt('r-1'));

        self::assertSame(0, $this->sender()->sendDue());

        $this->travel(11)->minutes();

        self::assertSame(1, $this->sender()->sendDue());
        self::assertSame(MessageStatus::Sent, $queued->refresh()->status);
    }

    public function testRecordsAnAcceptedResultAndAnnouncesIt(): void
    {
        Event::fake([MessageAccepted::class]);
        $queued = $this->outbox()->queue($this->message(), meta: ['employee_id' => 7]);
        $queued->update(['status' => MessageStatus::Sent]);
        $this->fakeHttp(
            $this->tokenResponse(),
            $this->resultResponse((string) $queued->message_id, ref: 'salariat-1'),
            new Response(200),
            new Response(204),
        );

        $this->command('reges:results')->assertSuccessful();

        $queued->refresh();
        self::assertSame(MessageStatus::Accepted, $queued->status);
        self::assertSame('salariat-1', $queued->ref);
        self::assertNotNull($queued->resolved_at);
        Event::assertDispatched(
            MessageAccepted::class,
            static fn (MessageAccepted $event): bool => $event->message->is($queued)
                && $event->message->meta === ['employee_id' => 7]
                && $event->result->ref === 'salariat-1',
        );
    }

    public function testRecordsARejectedResultWithItsReason(): void
    {
        Event::fake([MessageRejected::class]);
        $queued = $this->outbox()->queue($this->message());
        $queued->update(['status' => MessageStatus::Sent]);
        $this->fakeHttp(
            $this->tokenResponse(),
            $this->jsonResponse([
                'header' => ['messageId' => $queued->message_id],
                'result' => ['code' => 'EROARE', 'description' => 'CNP deja inregistrat'],
            ]),
            new Response(200),
            new Response(204),
        );

        $this->command('reges:results')->assertSuccessful();

        self::assertSame(MessageStatus::Rejected, $queued->refresh()->status);
        self::assertSame('CNP deja inregistrat', $queued->error);
        self::assertSame('CNP deja inregistrat', $queued->result_description);

        $stored = (array) DB::table('reges_messages')->first();
        self::assertStringNotContainsString('CNP', (string) $stored['error']);
        self::assertStringNotContainsString('CNP', (string) $stored['result_description']);
        Event::assertDispatched(MessageRejected::class, static fn (MessageRejected $event): bool => $event->result !== null);
    }

    public function testAResultIsNotCommittedWhenAnOutcomeListenerThrows(): void
    {
        Event::listen(MessageAccepted::class, static fn () => throw new \RuntimeException('could not store'));
        $queued = $this->outbox()->queue($this->message());
        $queued->update(['status' => MessageStatus::Sent]);
        $this->fakeHttp($this->tokenResponse(), $this->resultResponse((string) $queued->message_id), new Response(200));

        $this->command('reges:results')->assertFailed();

        self::assertSame([self::READ], array_slice($this->sent(), 1));
    }

    public function testWorksAcrossRegistriesThroughACredentialsProvider(): void
    {
        $this->app()->instance(CredentialsProvider::class, new class () implements CredentialsProvider {
            public function credentials(string $registry): Credentials
            {
                return match ($registry) {
                    'acme' => new Credentials('11111111-1111-4111-8111-111111111111', 'acme-password'),
                    'globex' => new Credentials('22222222-2222-4222-8222-222222222222', 'globex-password'),
                    default => throw new \InvalidArgumentException("Unknown registry {$registry}"),
                };
            }
        });
        config()->set('reges.username', '');

        $acme = $this->outbox()->queue($this->message(), registry: 'acme');
        $globex = $this->outbox()->queue($this->message(), registry: 'globex');
        $this->fakeHttp(
            $this->tokenResponse('acme-token'),
            $this->receipt('r-acme'),
            $this->tokenResponse('globex-token'),
            $this->receipt('r-globex'),
            $this->resultResponse((string) $acme->message_id),
            new Response(200),
            new Response(204),
            $this->resultResponse((string) $globex->message_id),
            new Response(200),
            new Response(204),
        );

        $this->command('reges:send')->expectsOutputToContain('Sent 2 messages.')->assertSuccessful();

        self::assertStringContainsString('"authorId":"11111111-1111-4111-8111-111111111111"', (string) $this->requests[1]->getBody());
        self::assertSame('Bearer acme-token', $this->requests[1]->getHeaderLine('Authorization'));
        self::assertSame('Bearer globex-token', $this->requests[3]->getHeaderLine('Authorization'));

        $this->command('reges:results')->assertSuccessful();

        self::assertSame(MessageStatus::Accepted, $acme->refresh()->status);
        self::assertSame(MessageStatus::Accepted, $globex->refresh()->status);
        self::assertSame('Bearer acme-token', $this->requests[4]->getHeaderLine('Authorization'));
        self::assertSame('Bearer globex-token', $this->requests[7]->getHeaderLine('Authorization'));
    }

    public function testAMessageFromAnUnknownRegistryIsRetriedNotDropped(): void
    {
        $queued = $this->outbox()->queue($this->message());
        $queued->update(['registry' => 'gone']);
        $this->fakeHttp();

        self::assertSame(0, $this->sender()->sendDue());

        self::assertSame(MessageStatus::Pending, $queued->refresh()->status);
        self::assertStringContainsString('Unknown REGES registry "gone"', (string) $queued->error);
    }

    public function testOnlyAPendingMessageCanBeCancelled(): void
    {
        $queued = $this->outbox()->queue($this->message());

        self::assertTrue($queued->cancel());
        self::assertSame(MessageStatus::Cancelled, $queued->status);
        self::assertFalse($queued->cancel());

        $sent = $this->outbox()->queue($this->message());
        $sent->update(['status' => MessageStatus::Sent]);

        self::assertFalse($sent->cancel());
        self::assertSame(MessageStatus::Sent, $sent->status);
    }

    private function outbox(): Outbox
    {
        return $this->app()->make(Outbox::class);
    }

    private function sender(): OutboxSender
    {
        return $this->app()->make(OutboxSender::class);
    }

    private function message(): Message
    {
        return Message::salariat(Operation::InregistrareSalariat, new InfoSalariat(
            cnp: '1800612015459',
            nume: 'POPESCU',
            prenume: 'ION',
            adresa: 'STR. SALARIATULUI, NR. 1',
            tipActIdentitate: 'CarteIdentitate',
            taraDomiciliu: 'România',
            nationalitate: 'România',
        ));
    }

    private function receipt(string $responseId): Response
    {
        return $this->jsonResponse(['responseId' => $responseId]);
    }

    private function resultResponse(string $messageId, ?string $ref = null): Response
    {
        return $this->jsonResponse(['header' => ['messageId' => $messageId], 'result' => ['code' => 'SUCCES', 'ref' => $ref]]);
    }
}
