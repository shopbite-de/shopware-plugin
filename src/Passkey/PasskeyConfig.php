<?php

declare(strict_types=1);

namespace ShopBite\Passkey;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Where passkeys are used: the backoffice domain. The relying party id is its host
 * (e.g. backoffice.pizzeria-lafattoria.de), the origins are its full URLs. Without both,
 * passkeys are switched off.
 */
final readonly class PasskeyConfig
{
    /** @psalm-suppress PossiblyUnusedMethod Constructed by the DI container */
    public function __construct(private SystemConfigService $systemConfig)
    {
    }

    public function rpId(): string
    {
        return trim($this->systemConfig->getString('ShopBitePlugin.config.passkeyRpId'));
    }

    /**
     * @return list<string>
     */
    public function origins(): array
    {
        $raw = $this->systemConfig->getString('ShopBitePlugin.config.passkeyOrigins');

        return array_values(array_filter(array_map(
            static fn (string $origin): string => rtrim(trim($origin), '/'),
            explode(',', $raw),
        )));
    }

    public function enabled(): bool
    {
        return $this->rpId() !== '' && $this->origins() !== [];
    }
}
