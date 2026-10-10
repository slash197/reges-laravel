<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel;

use slash197\Reges\Credentials;

/**
 * Tells the bridge which API key belongs to a registry. Applications with a
 * key per tenant implement this and bind it in the container; the registry
 * key is whatever identifies a tenant to them, such as a company id.
 */
interface CredentialsProvider
{
    /**
     * @throws \InvalidArgumentException When the registry is unknown or has no API key
     */
    public function credentials(string $registry): Credentials;
}
