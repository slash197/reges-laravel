<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel;

use Illuminate\Contracts\Config\Repository;
use slash197\Reges\Credentials;

/**
 * The single-registry case: the API key in config/reges.php, known as "default".
 */
final class ConfigCredentialsProvider implements CredentialsProvider
{
    public function __construct(private readonly Repository $config)
    {
    }

    public function credentials(string $registry): Credentials
    {
        if ($registry !== Registries::DEFAULT) {
            throw new \InvalidArgumentException(
                "Unknown REGES registry \"{$registry}\". Bind your own " . CredentialsProvider::class . ' to use more than one.',
            );
        }

        return new Credentials($this->string('reges.username'), $this->string('reges.password'));
    }

    private function string(string $key): string
    {
        $value = $this->config->get($key);

        return is_scalar($value) ? (string) $value : '';
    }
}
