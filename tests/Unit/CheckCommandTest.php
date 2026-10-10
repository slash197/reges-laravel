<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use slash197\Reges\Laravel\Tests\TestCase;

final class CheckCommandTest extends TestCase
{
    public function testReportsEachStepWithoutCommittingAnything(): void
    {
        $this->fakeHttp(
            $this->tokenResponse(),
            $this->profileResponse('a-1'),
            $this->jsonResponse(['header' => ['messageId' => 'm-1'], 'operation' => 'AdaugareContract', 'result' => ['code' => 'SUCCES']]),
        );

        $this->command('reges:check')
            ->expectsOutputToContain('ok, token expires')
            ->expectsOutputToContain('ok, employer id present')
            ->expectsOutputToContain('(not committed)')
            ->expectsOutputToContain('no local copy, run reges:nomenclators')
            ->assertSuccessful();

        self::assertNotContains('POST /api/Status/CommitRead', $this->sent());
    }

    public function testFailsWhenTheCredentialsAreRefused(): void
    {
        $this->fakeHttp($this->jsonResponse(['error' => 'invalid_grant'], 401));

        $this->command('reges:check')
            ->expectsOutputToContain('REGES authentication failed: invalid_grant.')
            ->assertFailed();
    }
}
