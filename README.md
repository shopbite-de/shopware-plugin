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
    - Dish line items (`type: container`): the product as first child, extras as further product children. The plugin validates the structure (product children with product ids, at most 30) and sets label and product number from the products; the ingredients to leave out come from `payload.without` (at most 20 names of up to 100 characters) or the `-<ingredient>` parts of the label, and only options of the product's property group "Hauptzutaten" are kept. Dishes carry no receipt print type, so the printer prints their label.
    - Receipt print type processing for specialized fulfillment workflows.
    - Ordering window: a cart validator blocks Store API orders while ordering is paused for the sales channel (`isCheckoutEnabled`), on holidays and when no opening interval of today ends after now plus the lead time of the cart's shipping method (orders for later today are allowed, intervals past midnight count). Time zone: the sales channel's business time zone (Shopware 6.7.13+), else Europe/Berlin. Cart error keys: `shopbite-ordering-paused`, `shopbite-ordering-holiday`, `shopbite-ordering-closed`.
- **Delivery and pickup**: each shipping method carries its fulfillment (custom field) and lead time (its Shopware delivery time), see [Delivery and pickup](#delivery-and-pickup).
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
| `ShopBite Personal` | today's orders, done/cancel, pausing online orders, lead times (15 privileges) |

Ordering on/off and the lead times go through `GET/PATCH /api/_action/shopbite/settings/{salesChannelId}`, guarded by the plugin privileges `shopbite_settings:read` and `shopbite_settings:update`. Neither role has `system_config:*` rights. `GET /api/_action/shopbite/settings` lists all sales channels (`{"channels": {"<id>": <read>}}`). A read returns:

```json
{
  "orderingOpen": true,
  "menuCategoryId": "<id>",
  "shippingMethods": [
    {"id": "<id>", "name": "Lieferung", "fulfillment": "delivery", "leadTimeMinutes": 45, "leadTimeMinutesSet": true, "editable": true},
    {"id": "<id>", "name": "Abholung", "fulfillment": "pickup", "leadTimeMinutes": 20, "leadTimeMinutesSet": true, "editable": false}
  ]
}
```

`orderingOpen` is the channel value with the global value as fallback, `menuCategoryId` the menu root from the plugin configuration (per sales channel, else its navigation category, also returned by `/store-api/shopbite/config`), `shippingMethods` the channel's active shipping methods (see [Delivery and pickup](#delivery-and-pickup); `leadTimeMinutesSet: false` = the method has no delivery time of a known unit and the default of 30 minutes applies; `editable` = the requesting user may change the lead time, i.e. not bound to branches or every sales channel of the method is one of their branches). `PATCH` accepts `{"orderingOpen"?: bool, "leadTimes"?: {"<shippingMethodId>": minutes}}` (minutes 1 to 1440, only shipping methods assigned to this sales channel, else 400) and answers with the new read; ordering switch and lead times are written in one transaction. A lead time is the delivery time of the shipping method (see [Delivery and pickup](#delivery-and-pickup) for how it is assigned), so it changes for **every** sales channel that uses the method.

Accounts are managed on the command line (`--help` on each command):

```bash
bin/console shopbite:staff:create owner --role=management --name="Anna Rossi"
bin/console shopbite:staff:create counter --role=personal --branch=<salesChannelId> --generate-password
bin/console shopbite:staff:update counter --branch=<salesChannelId> | --role=... | --generate-password | --deactivate
bin/console shopbite:staff:list
```

Staff need at least one branch; management without a branch sees all. Branches are stored in the user custom field `shopbite_sales_channel_ids` (custom field set `shopbite_user_set`). The plugin enforces the binding in the Admin API: the restaurant settings route (`/api/_action/shopbite/settings`) answers 403 for other sales channels and lists only the user's own, searches on orders and their parts (line items, deliveries, transactions, customers, addresses, documents, ...) return only orders of the user's branches, requests that would reach orders through another entity (e.g. a sales channel's or a product's orders, also as filter, sorting or aggregation) are refused with 403, and writes and order action routes (state transitions, ...) on orders of other branches are refused as well. A bound user may change the lead time of a shipping method only when every sales channel it is assigned to is one of their branches (else 403). Admins, integrations and users without a branch are not restricted; a branch field that is set but unreadable allows no branch. Like the order printer role, the staff roles are reset on update and removed on uninstall without *keep user data*.

## Delivery and pickup

A shop has a shipping method for delivery and one for pickup. Two things matter for each (*Settings > Shipping* in the administration, German *Einstellungen > Versand > Versandarten*):

| What | Where | Without a value |
| --- | --- | --- |
| Fulfillment | custom field `shopbite_fulfillment` (set `shopbite_shipping_method_set`), select `delivery` \| `pickup` | `delivery` |
| Lead time: minutes until delivered or ready for pickup | the shipping method's Shopware **delivery time** (*Lieferzeit*) | 30 minutes |

The lead time is the **maximum** of the delivery time converted to minutes (unit minute ×1, hour ×60, day ×1440, week, month and year accordingly) and clamped to 1 to 1440 minutes, so "0-1 hour" is 60 and Shopware's default "1-3 days" is 1440. A delivery time of an unknown unit counts as 30 minutes. Shopware has no minute unit; the plugin adds it to the delivery time form in the administration (*Settings > Delivery times*, unit *Minute*) and to the cart's delivery date calculation, which rounds minutes up to full hours internally (core only knows hours to years) while the cart keeps reporting the configured minutes. Nothing else is read: the name of a method does not matter at runtime.

When the backoffice sets a lead time of X minutes (settings route below), the method gets a delivery time with unit minute and min = max = X: an existing one if there is one, else a new one named "X Minuten" in German languages and "X minutes" in all others. Existing delivery times are never changed, because other shipping methods (or products) may use them; a delivery time shared by two methods stays as it is when the lead time of one of them changes.

Install and update (from older versions) migrate the shipping methods once:

- Fulfillment, only where it is empty or invalid: pickup when the technical name or a name contains "abhol", "pickup" or "selbst", else delivery; written into every translation of the method.
- Lead time: every method that is assigned to a sales channel (active or not) and whose delivery time is not minute based (Shopware's "1-3 days", a "0-1 hour", ...) gets a minute delivery time with the value of the former plugin setting *Default Delivery Time* (the values set on the method's sales channels when they agree, else the global value, else 30). On a fresh install that means Shopware's *Standard* and *Express* get "30 Minuten". While the plugin is inactive (install runs the migrations before activation, or an update of an inactive plugin), the methods are not changed yet: the plan is remembered and activating the plugin assigns it. Methods in no sales channel and methods that already have a minute delivery time stay as they are. The former setting is deleted. A value of the custom field `shopbite_lead_time_minutes` of an unreleased development version wins over the setting; the field and its values are removed.

Shopware itself does not know the unit minute: without the plugin, every cart with such a delivery time fails ("Not supported unit minute"). So whenever the plugin moves a shipping method to a minute delivery time (migration or settings route), it remembers the method's previous delivery time (table `shopbite_shipping_method_delivery_time`):

- **Deactivate** (also before uninstall) puts the previous delivery time back on every such method that is still on a minute delivery time, and remembers that minute delivery time (also one chosen in the administration since) for the next activation. It is **refused**, without changing anything, while another shipping method (one the plugin did not move, e.g. set to minutes by hand) or a product still uses a delivery time in minutes; the message names them. Give them a delivery time with unit hour, day, week, month or year and try again.
- **Activate** gives the methods that are still on the restored delivery time their minute delivery time again.
- **Uninstall** is refused the same way, before anything is removed (also for a plugin that is already inactive). Without *keep user data* it drops the table; a later install migrates again.

`/store-api/shopbite/config` returns the active shipping methods of the sales channel:

```json
"shippingMethods": [
  {"id": "<id>", "fulfillment": "delivery", "leadTimeMinutes": 45},
  {"id": "<id>", "fulfillment": "pickup", "leadTimeMinutes": 20}
]
```

The storefront shows the earliest time per method from it, and the ordering window (see Key Features) checks the lead time of the shipping method selected in the cart. The backoffice changes the lead times through the settings route (see [Backoffice roles and accounts](#backoffice-roles-and-accounts)). The storefront caches the config route for 60 seconds and the backoffice purges it after saving settings, so changes show up within a minute at most.

## Allergens and additives

Install and update create the property groups **Allergene** (the 14 allergens of the EU food information regulation, cereals and nuts by name, codes A-R) and **Zusatzstoffe** (additives that German menus have to mark, codes 1-12). The code of each option is stored in its custom field `shopbite_code`, the product custom field `shopbite_allergens_checked` marks a dish whose lists are complete. A group of the same name that already exists is adopted: matching options keep their id and only get code and position, nothing is duplicated. The groups stay on uninstall because dishes reference them. The catalogue (`src/FoodLabel/FoodLabelCatalogue.php`) is the same as in the backoffice, where the restaurant ticks them per dish.

## Passkeys for the backoffice

People log in to the backoffice with fingerprint or Face ID instead of a password (WebAuthn, `web-auth/webauthn-lib`). The plugin stores the passkeys (table `shopbite_passkey`, linked to the Shopware user) and adds the OAuth grant `shopbite_passkey` to `/api/oauth/token`, which answers like a password login. Routes for the logged-in user: `GET /api/_action/shopbite/passkey`, `POST …/register-options`, `POST …/register`, `DELETE …/{id}`; open: `POST …/login-options`.

Switch it on in the plugin configuration (card "Backoffice passkeys"): **relying party id** = host of the backoffice (e.g. `backoffice.mein-restaurant.de`), **origins** = its URL(s). Without both, the grant and the routes are off. Passkeys only work on that host; a deactivated user cannot log in with a passkey either.

Registering a passkey (`register-options`, `register`) needs an access token with the scope `user-verified`, i.e. a fresh password login (`grant_type=password` with `scope=write user-verified`), as Shopware asks before changes to the own user. Tokens from the passkey grant never carry that scope. A user's passkeys are removed when their password changes or they are deactivated (Administration, Admin API, password recovery, `shopbite:staff:update`). Each login or registration challenge is used once, also under concurrent requests; with several Shopware hosts this needs a shared lock store (`LOCK_DSN`, e.g. Redis).

```bash
bin/console shopbite:staff:passkeys counter                     # list
bin/console shopbite:staff:passkeys counter --revoke=<passkeyId> # remove one
bin/console shopbite:staff:passkeys counter --revoke-all
```

## "Jetzt drucken"

Order custom fields `shopbite_print_requested_at` and `shopbite_printed_at` (set `shopbite_order_set`): the backoffice sets the first, the [order printer](https://github.com/shopbite-de/order-printer) prints the receipt again and sets the second after every print.

## Requirements

- Shopware 6.7.0 or higher
- PHP 8.4 or higher

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
