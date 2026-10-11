<?php

declare(strict_types=1);

namespace ShopBite\Passkey;

/** A passkey request that cannot be carried out; the message is safe to show. */
final class PasskeyException extends \RuntimeException
{
}
