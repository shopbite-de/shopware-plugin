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
| `ShopBite Management` | menu, prices, variants, extras, sections, SEO, opening hours, orders (64 privileges) |
| `ShopBite Personal` | today's orders, done/cancel, pausing online orders, delivery time (17 privileges) |

Accounts are managed on the command line (`--help` on each command):

```bash
bin/console shopbite:staff:create owner --role=management --name="Anna Rossi"
bin/console shopbite:staff:create counter --role=personal --branch=<salesChannelId> --generate-password
bin/console shopbite:staff:update counter --branch=<salesChannelId> | --role=... | --generate-password | --deactivate
bin/console shopbite:staff:list
```

Staff need at least one branch; management without a branch sees all. Branches are stored in the user custom field `shopbite_sales_channel_ids` (custom field set `shopbite_user_set`). Like the order printer role, the staff roles are reset on update and removed on uninstall without *keep user data*.

## Requirements

- Shopware 6.7.0 or higher
- PHP 8.4 or higher

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
