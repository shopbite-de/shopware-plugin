<?php

declare(strict_types=1);

namespace ShopBite\Settings;

use function Psl\Type\int;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The settings a restaurant changes during service (ordering on/off, delivery time) and the menu
 * category (read only here, set in the plugin configuration), per sales
 * channel with the global plugin value as fallback, like ShopBiteConfigRoute reads them.
 * Used by the backoffice through SettingsRoute, so staff need no system_config privileges.
 */
final readonly class RestaurantSettings
{
    public const string ORDERING_OPEN = 'ShopBitePlugin.config.isCheckoutEnabled';

    public const string DELIVERY_MINUTES = 'ShopBitePlugin.config.defaultDeliveryTime';

    public const string MENU_CATEGORY = 'ShopBitePlugin.config.menuCategoryId';

    public const int MIN_DELIVERY_MINUTES = 1;

    public const int MAX_DELIVERY_MINUTES = 1440;

    /** @psalm-suppress PossiblyUnusedMethod Constructed by the container */
    public function __construct(private SystemConfigService $systemConfig)
    {
    }

    /**
     * The menu category of a sales channel: the configured one, else its navigation category
     * (plugin#35; the storefront and the backoffice read it, so they cannot drift apart).
     */
    public static function menuCategoryId(mixed $configured, string $navigationCategoryId): string
    {
        return \is_string($configured) && preg_match('/^[0-9a-f]{32}$/', $configured) === 1 ? $configured : $navigationCategoryId;
    }

    /**
     * @return array{orderingOpen: bool, deliveryMinutes: int|null, menuCategoryId: string}
     */
    public function read(string $salesChannelId, string $navigationCategoryId): array
    {
        $minutes = $this->systemConfig->get(self::DELIVERY_MINUTES, $salesChannelId);
        $minutes = int()->matches($minutes) || (\is_string($minutes) && ctype_digit($minutes)) ? (int) $minutes : 0;

        return [
            // unset means open, like the plugin default
            'orderingOpen' => $this->systemConfig->get(self::ORDERING_OPEN, $salesChannelId) !== false,
            'deliveryMinutes' => $minutes >= self::MIN_DELIVERY_MINUTES ? $minutes : null,
            'menuCategoryId' => self::menuCategoryId($this->systemConfig->get(self::MENU_CATEGORY, $salesChannelId), $navigationCategoryId),
        ];
    }

    /**
     * Writes the given values for this sales channel only; other channels keep theirs.
     *
     * @param array{orderingOpen?: bool, deliveryMinutes?: int} $changes
     */
    public function write(string $salesChannelId, array $changes): void
    {
        if (\array_key_exists('orderingOpen', $changes)) {
            $this->systemConfig->set(self::ORDERING_OPEN, $changes['orderingOpen'], $salesChannelId);
        }
        if (\array_key_exists('deliveryMinutes', $changes)) {
            $this->systemConfig->set(self::DELIVERY_MINUTES, $changes['deliveryMinutes'], $salesChannelId);
        }
    }

    /**
     * The valid changes of a request body, or an error message.
     *
     * @param array<array-key, mixed> $body
     *
     * @return array{orderingOpen?: bool, deliveryMinutes?: int}|string
     */
    public static function changesFrom(array $body): array|string
    {
        $unknown = array_diff(array_map('strval', array_keys($body)), ['orderingOpen', 'deliveryMinutes']);
        if ($unknown !== []) {
            return 'Unknown setting: ' . implode(', ', $unknown);
        }
        $changes = [];
        if (\array_key_exists('orderingOpen', $body)) {
            if (!\is_bool($body['orderingOpen'])) {
                return 'orderingOpen must be a boolean.';
            }
            $changes['orderingOpen'] = $body['orderingOpen'];
        }
        if (\array_key_exists('deliveryMinutes', $body)) {
            $minutes = $body['deliveryMinutes'];
            if (!\is_int($minutes) || $minutes < self::MIN_DELIVERY_MINUTES || $minutes > self::MAX_DELIVERY_MINUTES) {
                return \sprintf('deliveryMinutes must be a whole number from %d to %d.', self::MIN_DELIVERY_MINUTES, self::MAX_DELIVERY_MINUTES);
            }
            $changes['deliveryMinutes'] = $minutes;
        }

        return $changes === [] ? 'Nothing to change.' : $changes;
    }
}
