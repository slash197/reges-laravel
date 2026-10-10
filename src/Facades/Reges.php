<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * The client for the registry configured in config/reges.php.
 *
 * @method static \slash197\Reges\Receipt send(\slash197\Reges\Message|\slash197\Reges\Envelope $message, ?string $user = null)
 * @method static \slash197\Reges\Envelope envelope(\slash197\Reges\Message $message, ?string $user = null)
 * @method static \slash197\Reges\Results\Results results(?string $consumerId = null)
 * @method static \slash197\Reges\Profile profile()
 * @method static \slash197\Reges\Nomenclators nomenclators()
 * @method static \slash197\Reges\BonusTypes bonusTypes()
 * @method static \slash197\Reges\Auth\Token authenticate()
 * @method static string angajatorId()
 *
 * @see \slash197\Reges\Reges
 */
final class Reges extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \slash197\Reges\Reges::class;
    }
}
