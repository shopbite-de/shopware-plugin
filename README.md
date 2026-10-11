# ShopBite Shopware 6 Plugin

This plugin is a core component of the [ShopBite](https://github.com/shopbite-de) ecosystem. It provides essential backend functionality and API endpoints to power the ShopBite Nuxt storefront, enabling seamless integration between Shopware 6 and a modern headless frontend.

## Overview

The ShopBite plugin extends Shopware 6 with specialized features tailored for the ShopBite ecosystem. It focuses on providing advanced configuration, business hours management, and checkout enhancements required for a high-performance headless storefront.

## Key Features

- **Business Hours Management**: Define and manage business hours for your store, exposed via Store API.
- **Holiday Management**: Configure store holidays and special closing days.
- **Storefront Configuration**: Custom API endpoints to provide frontend-specific settings to the Nuxt storefront.
- **Contact & Location**: Address, telephone and Google Business Profile link per sales channel, maintained in the plugin configuration and exposed via `/store-api/shopbite/config`.
- **Wishlist for configured dishes**: Logged-in customers save a product together with deselected ingredients and extras (`/store-api/shopbite/wishlist`, `/add`, `/merge`, `DELETE /{id}`), scoped per customer and sales channel.
- **Checkout Enhancements**:
    - Custom line item handling for container-based products.
    - Receipt print type processing for specialized fulfillment workflows.
- **Headless Optimized**: Designed from the ground up to work with the ShopBite Nuxt storefront and the Shopware Store API.

## Integration

This plugin is specifically designed to work within the [ShopBite ecosystem](https://github.com/shopbite-de). While it runs on Shopware 6, its primary purpose is to serve as the backend provider for the **ShopBite Nuxt storefront**.

For the best experience, it is recommended to use this plugin in combination with the other tools and templates provided by the ShopBite project.

## Installation

1. Install the plugin via composer:
   ```bash
   composer require shopbite-de/shopware-plugin
   ```
2. Install and activate the plugin via the Shopware administration or CLI:
   ```bash
   bin/console plugin:install --activate ShopBite
   ```
3. Refresh the administration to see the ShopBite modules.

## Order Printer role

Install and update create the ACL role `order-printer` for the [ShopBite Order Printer](https://github.com/shopbite-de/order-printer), with only the privileges its Admin API calls need:

| Privilege | Used for |
| --- | --- |
| `order:read`, `state_machine_state:read` | `POST /api/search/order` (open orders) |
| `order_delivery:read`, `order_line_item:read`, `order_address:read`, `shipping_method:read` | `POST /api/search/order-delivery` (receipt contents) |
| `order:update` | `POST /api/_action/order/{id}/state/process` (mark *in progress*) |

To connect a printer: *Settings › System › Integrations › Add integration*, select the role `order-printer`, and copy the access key ID and secret access key into the printer's `SHOPWARE_CLIENT_ID` / `SHOPWARE_CLIENT_SECRET`. The secret is only shown once.

Re-running install or update leaves an up-to-date role untouched and resets changed privileges, so don't edit the role by hand. Uninstalling with *keep user data* keeps the role, because integrations reference it.

## Backoffice roles and accounts

Install and update also create two ACL roles with fixed ids for the [ShopBite backoffice](https://github.com/shopbite-de/backoffice), where restaurant staff log in with their own Shopware user:

| Role | May |
| --- | --- |
| `ShopBite Management` | menu, prices, variants, extras and extras groups, sections, SEO, opening hours, orders (69 privileges) |
| `ShopBite Personal` | today's orders, done/cancel, pausing online orders, delivery time (15 privileges) |

Ordering on/off and the delivery time go through `GET/PATCH /api/_action/shopbite/settings/{salesChannelId}` (`{"orderingOpen": bool, "deliveryMinutes": int}`, channel value with the global value as fallback), guarded by the plugin privileges `shopbite_settings:read` and `shopbite_settings:update`. Neither role has `system_config:*` rights. `GET /api/_action/shopbite/settings` lists all sales channels; every read also returns `menuCategoryId`, the menu root from the plugin configuration (per sales channel, else its navigation category), which `/store-api/shopbite/config` returns as well.

Accounts are managed on the command line (`--help` on each command):

```bash
bin/console shopbite:staff:create owner --role=management --name="Anna Rossi"
bin/console shopbite:staff:create counter --role=personal --branch=<salesChannelId> --generate-password
bin/console shopbite:staff:update counter --branch=<salesChannelId> | --role=... | --generate-password | --deactivate
bin/console shopbite:staff:list
```

Staff need at least one branch; management without a branch sees all. Branches are stored in the user custom field `shopbite_sales_channel_ids` (custom field set `shopbite_user_set`). Like the order printer role, the staff roles are reset on update and removed on uninstall without *keep user data*.

## Allergens and additives

Install and update create the property groups **Allergene** (the 14 allergens of the EU food information regulation, cereals and nuts by name, codes A-R) and **Zusatzstoffe** (additives that German menus have to mark, codes 1-12). The code of each option is stored in its custom field `shopbite_code`, the product custom field `shopbite_allergens_checked` marks a dish whose lists are complete. A group of the same name that already exists is adopted: matching options keep their id and only get code and position, nothing is duplicated. The groups stay on uninstall because dishes reference them. The catalogue (`src/FoodLabel/FoodLabelCatalogue.php`) is the same as in the backoffice, where the restaurant ticks them per dish.

## Passkeys for the backoffice

People log in to the backoffice with fingerprint or Face ID instead of a password (WebAuthn, `web-auth/webauthn-lib`). The plugin stores the passkeys (table `shopbite_passkey`, linked to the Shopware user) and adds the OAuth grant `shopbite_passkey` to `/api/oauth/token`, which answers like a password login. Routes for the logged-in user: `GET /api/_action/shopbite/passkey`, `POST …/register-options`, `POST …/register`, `DELETE …/{id}`; open: `POST …/login-options`.

Switch it on in the plugin configuration (card "Backoffice passkeys"): **relying party id** = host of the backoffice (e.g. `backoffice.pizzeria-lafattoria.de`), **origins** = its URL(s). Without both, the grant and the routes are off. Passkeys only work on that host; a deactivated user cannot log in with a passkey either.

## "Jetzt drucken"

Order custom fields `shopbite_print_requested_at` and `shopbite_printed_at` (set `shopbite_order_set`): the backoffice sets the first, the [order printer](https://github.com/shopbite-de/order-printer) prints the receipt again and sets the second after every print.

## Requirements

- Shopware 6.7.0 or higher
- PHP 8.4 or higher

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
