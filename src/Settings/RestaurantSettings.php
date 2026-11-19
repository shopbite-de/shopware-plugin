<?php

declare(strict_types=1);

namespace ShopBite\Settings;

use function Psl\Type\bool;

use Psl\Type\Exception\CoercionException;
use ShopBite\Fulfillment\ShippingMethodFulfillment;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The settings a restaurant changes during service (ordering on/off; the lead times of the
 * shipping methods, see SettingsRoute and ShippingMethodLeadTimes) and the menu category (read
 * only here, set in the plugin configuration), per sales channel with the global plugin value as
 * fallback, like ShopBiteConfigRoute reads them. Used by the backoffice through SettingsRoute,
 * so staff need no system_config privileges.
 */
final readonly class RestaurantSettings
{
    public const string ORDERING_OPEN = 'ShopBitePlugin.config.isCheckoutEnabled';

    public const string MENU_CATEGORY = 'ShopBitePlugin.config.menuCategoryId';

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
     * @return array{orderingOpen: bool, menuCategoryId: string}
     */
    public function read(string $salesChannelId, string $navigationCategoryId): array
    {
        return [
            'orderingOpen' => self::isOpen($this->systemConfig->get(self::ORDERING_OPEN, $salesChannelId)),
            'menuCategoryId' => self::menuCategoryId($this->systemConfig->get(self::MENU_CATEGORY, $salesChannelId), $navigationCategoryId),
        ];
    }

    /**
     * Read like /store-api/shopbite/config does (bool coercion, so "false" and "0" mean
     * paused); unset or unreadable means open, like the plugin default.
     */
    private static function isOpen(mixed $value): bool
    {
        try {
            return $value === null || bool()->coerce($value);
        } catch (CoercionException) {
            return true;
        }
    }

    /**
     * Writes the given values for this sales channel only; other channels keep theirs.
     *
     * Lead times are written by SettingsRoute through ShippingMethodLeadTimes.
     *
     * @param array{orderingOpen?: bool, leadTimes?: array<string, int>} $changes
     */
    public function write(string $salesChannelId, array $changes): void
    {
        if (\array_key_exists('orderingOpen', $changes)) {
            $this->systemConfig->set(self::ORDERING_OPEN, $changes['orderingOpen'], $salesChannelId);
        }
    }

    /**
     * The valid changes of a request body, or an error message.
     *
     * @param array<array-key, mixed> $body
     *
     * @return array{orderingOpen?: bool, leadTimes?: array<string, int>}|string
     */
    public static function changesFrom(array $body): array|string
    {
        $unknown = array_diff(array_map('strval', array_keys($body)), ['orderingOpen', 'leadTimes']);
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
        if (\array_key_exists('leadTimes', $body)) {
            $leadTimes = self::leadTimesFrom($body['leadTimes']);
            if (\is_string($leadTimes)) {
                return $leadTimes;
            }
            if ($leadTimes !== []) {
                $changes['leadTimes'] = $leadTimes;
            }
        }

        return $changes === [] ? 'Nothing to change.' : $changes;
    }

    /**
     * `{"<shipping method id>": minutes}`; ids are lowercased.
     *
     * @return array<string, int>|string
     */
    private static function leadTimesFrom(mixed $value): array|string
    {
        if (!\is_array($value)) {
            return 'leadTimes must be an object of shipping method ids and minutes.';
        }
        $leadTimes = [];
        foreach ($value as $id => $minutes) {
            $id = strtolower((string) $id);
            if (!Uuid::isValid($id)) {
                return 'leadTimes must be an object of shipping method ids and minutes.';
            }
            $minutes = ShippingMethodFulfillment::validLeadTimeMinutes($minutes);
            if ($minutes === null) {
                return \sprintf(
                    'Lead times must be whole numbers from %d to %d.',
                    ShippingMethodFulfillment::MIN_LEAD_TIME_MINUTES,
                    ShippingMethodFulfillment::MAX_LEAD_TIME_MINUTES,
                );
            }
            $leadTimes[$id] = $minutes;
        }

        return $leadTimes;
    }
}
