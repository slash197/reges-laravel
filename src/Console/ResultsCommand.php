<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use slash197\Reges\Laravel\Outbox\MessageStatus;
use slash197\Reges\Laravel\Outbox\OutboxMessage;
use slash197\Reges\Laravel\Registries;
use slash197\Reges\Laravel\ResultReader;

final class ResultsCommand extends Command
{
    protected $signature = 'reges:results
        {--registry=* : Registry to read; defaults to the configured one and those with messages awaiting a result}
        {--consumer= : Consumer id to read the queue as; defaults to reges.results.consumer_id}
        {--limit= : Most results to read per registry in this run; defaults to reges.results.limit}';

    protected $description = 'Read the REGES result queues and dispatch an event for each result';

    public function handle(
        Registries $registries,
        ResultReader $reader,
        Repository $config,
        ExceptionHandler $exceptions,
    ): int {
        $consumer = $this->option('consumer') ?? $config->get('reges.results.consumer_id');
        $limit = $this->option('limit') ?? $config->get('reges.results.limit');
        $failed = false;

        foreach ($this->registries($config) as $registry) {
            try {
                $read = $reader->drain(
                    $registries->client($registry),
                    $registry,
                    is_string($consumer) && $consumer !== '' ? $consumer : null,
                    is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null,
                );

                $this->components->twoColumnDetail($registry, "{$read} " . ($read === 1 ? 'result' : 'results'));
            } catch (\Throwable $exception) {
                // One registry with a revoked key or a failing listener must not stop the others
                $failed = true;
                $exceptions->report($exception);
                $this->components->twoColumnDetail($registry, '<fg=red>FAILED</> ' . $exception->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function registries(Repository $config): array
    {
        $registries = array_values(array_filter((array) $this->option('registry'), 'is_string'));

        if ($registries !== []) {
            return $registries;
        }

        $username = $config->get('reges.username');
        if (is_string($username) && trim($username) !== '') {
            $registries[] = Registries::DEFAULT;
        }

        if ($config->get('reges.outbox.enabled')) {
            $awaiting = OutboxMessage::query()
                ->where('status', MessageStatus::Sent)
                ->distinct()
                ->pluck('registry')
                ->all();

            $registries = array_merge($registries, array_filter($awaiting, 'is_string'));
        }

        return array_values(array_unique($registries));
    }
}
