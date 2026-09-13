# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Shopware 6.7 plugin (`ShopBite\ShopBitePlugin`, PHP 8.4) that adds the food-delivery domain to Shopware and exposes it through custom Store API routes. Its only consumer is the headless Nuxt storefront in `../storefront/`; there is no Twig storefront code here. Part of the ShopBite monorepo — see the root `../CLAUDE.md` for how the services fit together.

## Commands

```bash
composer install
make test            # PHPUnit (tests/ — pure unit tests, no DB or kernel boot)
make cs-fix          # php-cs-fixer, PSR-12 + strict_types + alpha-sorted imports
make cs-check        # dry-run with diff (what CI runs)
make psalm           # Psalm level 3, findUnusedCode=true, with baseline
make psalm-baseline  # regenerate psalm-baseline.xml (only for known unavoidable issues)
make check           # cs-check + psalm + test — run before committing
```

Single test file or method:

```bash
vendor/bin/phpunit tests/Unit/Core/Content/Holiday/SalesChannel/HolidayRouteTest.php
vendor/bin/phpunit --filter testLoad
```

CI (`.github/workflows/ci.yml`) runs `make cs-check`, `make psalm`, `make test` against Shopware 6.7.5.1 and 6.7.7.1. Keep code compatible with both.

### Local Shopware for manual testing

`compose.yaml` builds a full Shopware dev stack (`web` on http://localhost:8000, MariaDB on 33061, OpenSearch, Mailpit) and mounts this repo at `custom/static-plugins/ShopBitePlugin` inside the container.

```bash
docker compose up -d
make init            # system:install + composer require the plugin + plugin:install --activate
make activate-plugin # reinstall/reactivate after changing migrations or custom fields
```

The plugin name for `bin/console plugin:*` commands is `ShopBitePlugin`.

## Architecture

### Feature-module layout

`src/` is organised by domain feature, not by Shopware layer. Each feature that has a Store API route follows the same shape (BusinessHour, Holiday, MultiChannelGroup, Config):

```
src/<Feature>/
├── <Feature>Definition.php          # DAL EntityDefinition (ENTITY_NAME = 'shopbite_<feature>')
├── <Feature>Entity.php
├── <Feature>Collection.php
└── SalesChannel/
    ├── Abstract<Feature>Route.php   # abstract readonly, getDecorated() + load()
    ├── <Feature>Route.php           # final readonly, #[Route] attributes, throws DecorationPatternException
    ├── <Feature>RouteResponse.php   # extends StoreApiResponse<Struct>
    └── <Feature>Struct.php          # final Struct with public readonly props — this is the JSON shape
```

Routes are Shopware's standard abstract/concrete decoration pattern. All are scoped `_routeScope: ['store-api']` under `/store-api/shopbite/...` and filter by `$context->getSalesChannelId()`, so every entity that is per-shop carries a `sales_channel_id` FK.

### Registration checklist for a new feature

Nothing is autowired. Adding an entity or route touches all of these:

1. `src/Migration/Migration<timestamp>Create...Table.php` — raw SQL, `BINARY(16)` ids, `DATETIME(3)` timestamps, `fk.<table>.<column>` constraint naming.
2. `src/Resources/config/services.xml` — tag the definition with `shopware.entity.definition`, the route with `controller.service_arguments`, inject `<entity_name>.repository`.
3. `src/Resources/config/routes.xml` — add an `<import resource="../../<Feature>/**/*Route.php" type="attribute"/>` line; routes in an unlisted directory are silently not registered.
4. `src/Extension/SalesChannel/SalesChannelExtension.php` — if the sales channel should expose the association (`shopbiteBusinessHours`, `shopbiteHolidays`).
5. `src/Resources/Schema/StoreApi/shopbite.json` — OpenAPI schema for the Store API docs; keep it in sync with the Struct.
6. Admin module under `src/Resources/app/administration/src/module/shopbite-<feature>/` and an import in `main.js` (see below).
7. A `tests/Unit/.../<Feature>RouteTest.php`.

**Currently in progress:** `src/Voucher/` (Voucher + Redemption definitions) and its migration exist but are not yet registered in `services.xml` or wired to a route or admin module.

### Checkout extensions (`src/Checkout/Cart/`)

- `ContainerLineItemFactory` — registers a `container` line item type (`shopware.cart.line_item.factory`). The storefront posts a parent line item with `children` (product line items) to model a dish with extras/toppings as one cart item.
- `ReceiptPrintTypeProcessor` — collector + processor at priority 4500 (runs before Shopware's product processor). It loads products for cart line items and copies the `shopbite_receipt_print_type` custom field into the line item payload, so it survives into the order for `../order-printer/`.
- `Delivery/MinuteAwareDeliveryBuilder` — decorates Shopware's `DeliveryBuilder` to add a `minute` delivery time unit. Core's `DeliveryDate::createFromDeliveryTime` is a static `match` that throws on unknown units, so the decorator swaps minute-based delivery times (shipping method + line items) for the next full hour during the core call and restores the originals afterwards. The matching admin side is `Resources/app/administration/src/override/sw-settings-delivery-time-detail/`, which adds the unit to the select and its snippets.

### Custom fields (`src/Service/CustomFieldsInstaller.php`)

Custom field sets are defined as constant arrays with **hard-coded UUIDs** and upserted on install/update; relations to `product`/`category` are added on activate. New custom fields must get a fixed UUID (so reinstalls are idempotent) and a public constant if PHP code reads them. Plugin config (`Resources/config/config.xml`) is read via `SystemConfigService` with keys `ShopBitePlugin.config.<name>` and exposed through `ShopBiteConfigRoute`.

### Administration (`src/Resources/app/administration/`)

Plain Shopware admin modules (`Shopware.Module.register`, list + detail pages, de-DE/en-GB snippets), all nested under the `shopbite.main.index` navigation parent. The compiled bundle in `src/Resources/public/administration/` **is committed**; after changing admin JS, rebuild it with Shopware's admin build (`make build-administration` / `make watch-admin` in `../shopware/`, or `bin/build-administration.sh` inside this repo's `web` container) and commit the output.

## Documentation (mandatory with every change)

The official user-facing docs live in the `homepage/` service and exist in two languages. **Every change here must be reflected in both**:

- `../homepage/content/{de,en}/docs/7.shopware/` — this plugin: installation, plugin configuration, business hours, holidays, checkout.
- `../homepage/content/{de,en}/docs/9.receipt-printer/` — the order/receipt printer. Update it whenever a change here affects what the printer receives (e.g. the `shopbite_receipt_print_type` custom field, line item payload, container line items).

Always bump `dateModified` (and `sitemap.lastmod`) in the frontmatter of every page you touch.

## Code conventions

- `declare(strict_types=1)`, `final readonly` classes wherever possible, `#[\Override]` on every overridden method (Psalm and cs-fixer enforce parts of this).
- PHP 8.4 idioms are used freely: `new Foo()->method()` without wrapping parentheses, typed class constants (`public const string`).
- Runtime type coercion uses `azjezz/psl` (`Psl\Type\bool()->coerce(...)`, `instance_of(...)`) instead of manual casts or assertions.
- Psalm runs with `findUnusedCode=true`, so classes/methods only reached via DI or routing need `@psalm-suppress UnusedClass` / `PossiblyUnusedMethod` docblocks, as the existing routes do. Prefer a targeted suppress over growing `psalm-baseline.xml`.
- Repository types are documented as generics in docblocks (`EntityRepository<HolidayCollection>`) so Psalm can type `getEntities()`.

## Tests

`phpunit.xml` sets `KERNEL_CLASS` but nothing boots the kernel; all tests are isolated unit tests that mock `EntityRepository`, `SalesChannelContext`, and `EntitySearchResult` and assert on the `Criteria` passed to `search()`. Follow that style — no database fixtures. Tests use PHPUnit 12 attributes (`#[CoversClass]`) and run in random order.
