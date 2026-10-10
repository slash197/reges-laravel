<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Console;

use Illuminate\Console\Command;
use slash197\Reges\Config;
use slash197\Reges\Exception\RegesException;
use slash197\Reges\Laravel\CacheNomenclatorLookup;
use slash197\Reges\Reges;
use slash197\Reges\Validation\NomenclatorLookup;

final class CheckCommand extends Command
{
    protected $signature = 'reges:check';

    protected $description = 'Check the REGES configuration with read-only calls: authentication, profile and result queue';

    public function handle(Reges $reges, Config $config, CacheNomenclatorLookup $nomenclators): int
    {
        $this->components->twoColumnDetail('Environment', $config->environment->value);

        $steps = [
            'Authentication' => static function () use ($reges): string {
                $expiresAt = $reges->authenticate()->expiresAt;

                return 'ok, token expires ' . ($expiresAt?->format(\DATE_ATOM) ?? 'at an unknown time');
            },
            'Profile' => static fn (): string => $reges->profile()->angajatorId !== null
                ? 'ok, employer id present'
                : 'employer id MISSING',
            'Result queue' => static function () use ($reges): string {
                $result = $reges->results()->read();

                return $result === null
                    ? 'ok, empty'
                    : "ok, next is {$result->operation} => {$result->code} (not committed)";
            },
        ];

        foreach ($steps as $label => $step) {
            try {
                $this->components->twoColumnDetail($label, $step());
            } catch (RegesException $exception) {
                $this->components->twoColumnDetail($label, '<fg=red>FAILED</>');
                $this->components->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        $missing = array_filter(
            [NomenclatorLookup::NATIONALITATE, NomenclatorLookup::TIP_ACT_IDENTITATE, NomenclatorLookup::LOCALITATE],
            static fn (string $nomenclator): bool => !$nomenclators->holds($nomenclator),
        );

        $this->components->twoColumnDetail(
            'Nomenclators',
            $missing === [] ? 'ok, local copy present' : 'no local copy, run reges:nomenclators',
        );

        return self::SUCCESS;
    }
}
