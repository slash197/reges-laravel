<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use slash197\Reges\Laravel\Outbox\OutboxSender;

final class SendCommand extends Command
{
    protected $signature = 'reges:send
        {--limit= : Most messages to attempt in this run; defaults to reges.outbox.limit}';

    protected $description = 'Send the stored REGES messages that are due, keeping each sequence in order';

    public function handle(OutboxSender $sender, Repository $config): int
    {
        $limit = $this->option('limit') ?? $config->get('reges.outbox.limit');

        $sent = $sender->sendDue(is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null);

        $this->components->info("Sent {$sent} " . ($sent === 1 ? 'message' : 'messages') . '.');

        return self::SUCCESS;
    }
}
