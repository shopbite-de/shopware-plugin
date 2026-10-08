<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Wishlist;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ShopBite\Wishlist\WishlistConfiguration;
use ShopBite\Wishlist\WishlistException;

#[CoversClass(WishlistConfiguration::class)]
#[CoversClass(WishlistException::class)]
class WishlistConfigurationTest extends TestCase
{
    public function testNormalisesLists(): void
    {
        $configuration = WishlistConfiguration::fromInput(
            [' Zwiebeln ', 'Knoblauch', '', 'Zwiebeln', '   '],
            ['X-2', 'X-1', 'X-2'],
        );

        self::assertSame(['Knoblauch', 'Zwiebeln'], $configuration->without);
        self::assertSame(['X-1', 'X-2'], $configuration->extras);
        self::assertSame(['without' => ['Knoblauch', 'Zwiebeln'], 'extras' => ['X-1', 'X-2']], $configuration->toArray());
    }

    public function testMissingListsAreEmpty(): void
    {
        $configuration = WishlistConfiguration::fromInput(null, null);

        self::assertSame([], $configuration->without);
        self::assertSame([], $configuration->extras);
    }

    public function testRejectsNonList(): void
    {
        $this->expectException(WishlistException::class);
        $this->expectExceptionMessage('The parameter "extras" must be a list of strings.');

        WishlistConfiguration::fromInput([], 'X-1');
    }

    public function testRejectsNonStringEntries(): void
    {
        try {
            WishlistConfiguration::fromInput(['Zwiebeln', 42], []);
            self::fail('Expected exception');
        } catch (WishlistException $e) {
            self::assertSame(WishlistException::INVALID_CONFIGURATION, $e->getErrorCode());
            self::assertSame(400, $e->getStatusCode());
        }
    }

    public function testFromStorageIsLenient(): void
    {
        self::assertSame([], WishlistConfiguration::fromStorage('garbage')->without);
        self::assertSame([], WishlistConfiguration::fromStorage(['without' => 'x'])->without);
        self::assertSame(['a', 'b'], WishlistConfiguration::fromStorage(['without' => ['b', 'a']])->without);
    }
}
