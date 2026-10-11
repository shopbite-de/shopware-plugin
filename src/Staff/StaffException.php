<?php

declare(strict_types=1);

namespace ShopBite\Staff;

/**
 * A staff account request that cannot be carried out (unknown role, missing branch, ...).
 * The message is shown to the operator as is.
 */
final class StaffException extends \RuntimeException
{
}
