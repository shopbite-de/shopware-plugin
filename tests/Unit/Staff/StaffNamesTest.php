<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Staff;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ShopBite\Staff\Command\StaffNames;

#[CoversClass(StaffNames::class)]
class StaffNamesTest extends TestCase
{
    public function testSplitsFirstAndLastName(): void
    {
        static::assertSame(['Anna', 'Rossi'], StaffNames::split('Anna Rossi', 'inhaber'));
        static::assertSame(['Anna', 'Maria Rossi'], StaffNames::split('  Anna  Maria Rossi ', 'inhaber'));
    }

    public function testFallsBackToTheUsernameAsLastName(): void
    {
        static::assertSame(['', 'Anna'], StaffNames::split('Anna', 'inhaber'));
        static::assertSame(['', 'inhaber'], StaffNames::split('', 'inhaber'));
    }
}
