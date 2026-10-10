<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use slash197\Reges\Laravel\ResultReader;
use slash197\Reges\Reges;

final class ResultsCommand extends Command
{
    protected $signature = 'reges:results
        {--consumer= : Consumer id to read the queue as; defaults to reges.results.consumer_id}
        {--limit= : Most results to read in this run; defaults to reges.results.limit}';

    protected $description = 'Read the result queue of the configured registry and dispatch an event for each result';

    public function handle(Reges $reges, ResultReader $reader, Repository $config): int
    {
        $consumer = $this->option('consumer') ?? $config->get('reges.results.consumer_id');
        $limit = $this->option('limit') ?? $config->get('reges.results.limit');

        $read = $reader->drain(
            $reges,
            consumerId: is_string($consumer) && $consumer !== '' ? $consumer : null,
            limit: is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null,
        );

        $this->components->info("Read {$read} " . ($read === 1 ? 'result' : 'results') . '.');

        return self::SUCCESS;
    }
}
