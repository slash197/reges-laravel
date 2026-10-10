<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Console;

use Illuminate\Console\Command;
use slash197\Reges\Exception\RegesException;
use slash197\Reges\Laravel\CacheNomenclatorLookup;
use slash197\Reges\Reges;

final class NomenclatorsCommand extends Command
{
    protected $signature = 'reges:nomenclators';

    protected $description = 'Download the nomenclators that messages are validated against';

    public function handle(Reges $reges, CacheNomenclatorLookup $nomenclators): int
    {
        try {
            $counts = $nomenclators->refresh($reges);
        } catch (RegesException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $failed = false;

        foreach ($counts as $nomenclator => $count) {
            if ($count === 0) {
                $failed = true;
                $this->components->twoColumnDetail($nomenclator, '<fg=red>empty answer, copy left as it was</>');
            } else {
                $this->components->twoColumnDetail($nomenclator, "{$count} values");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
