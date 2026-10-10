<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel;

use Illuminate\Contracts\Cache\Repository;
use slash197\Reges\Reges;
use slash197\Reges\Validation\NomenclatorLookup;

/**
 * A local copy of the nomenclators validation checks values against, kept in
 * a Laravel cache store. Until refresh() has run for a nomenclator, its
 * checks pass, exactly as they do without a lookup.
 */
final class CacheNomenclatorLookup implements NomenclatorLookup
{
    /**
     * The REGES name of each nomenclator and the field messages refer to it by.
     */
    private const SOURCES = [
        self::NATIONALITATE => ['Nationalitate', 'nume'],
        self::TIP_ACT_IDENTITATE => ['TipActIdentitate', 'cod'],
        self::LOCALITATE => ['Localitate', 'codSiruta'],
    ];

    /** @var array<string, array<string, true>|null> */
    private array $loaded = [];

    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = 'reges:nomenclator:',
    ) {
    }

    public function has(string $nomenclator, string $value): bool
    {
        $values = $this->values($nomenclator);

        return $values === null || isset($values[$value]);
    }

    /**
     * Downloads the nomenclators and replaces the local copy. They are public,
     * so any client will do, whatever its credentials.
     *
     * @return array<string, int> Number of values now held for each nomenclator
     */
    public function refresh(Reges $reges): array
    {
        $counts = [];

        foreach (self::SOURCES as $nomenclator => [$type, $field]) {
            $values = [];

            foreach ($reges->nomenclators()->get($type) as $entry) {
                $value = is_array($entry) ? ($entry[$field] ?? null) : null;

                if (is_scalar($value) && (string) $value !== '') {
                    $values[(string) $value] = true;
                }
            }

            // An empty answer is more likely a fault than a nomenclator emptied out
            if ($values !== []) {
                $this->cache->forever($this->prefix . $nomenclator, array_map(strval(...), array_keys($values)));
                $this->loaded[$nomenclator] = $values;
            }

            $counts[$nomenclator] = count($values);
        }

        return $counts;
    }

    /**
     * Whether a local copy of the nomenclator exists.
     */
    public function holds(string $nomenclator): bool
    {
        return $this->values($nomenclator) !== null;
    }

    /**
     * @return array<string, true>|null
     */
    private function values(string $nomenclator): ?array
    {
        if (!array_key_exists($nomenclator, $this->loaded)) {
            $cached = $this->cache->get($this->prefix . $nomenclator);

            $this->loaded[$nomenclator] = is_array($cached) && $cached !== []
                ? array_fill_keys(array_map(strval(...), $cached), true)
                : null;
        }

        return $this->loaded[$nomenclator];
    }
}
