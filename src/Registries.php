<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel;

use slash197\Reges\Reges;

/**
 * The clients of the registries the application works on, by registry key.
 */
final class Registries
{
    /** The key of the registry configured in config/reges.php. */
    public const DEFAULT = 'default';

    /** @var array<string, Reges> */
    private array $clients = [];

    public function __construct(
        private readonly RegesFactory $factory,
        private readonly CredentialsProvider $credentials,
    ) {
    }

    public function client(string $registry = self::DEFAULT): Reges
    {
        return $this->clients[$registry] ??= $this->factory->make($this->credentials->credentials($registry));
    }

    /**
     * Drops the clients built so far, so the next one is built from current credentials.
     */
    public function forget(): void
    {
        $this->clients = [];
    }
}
