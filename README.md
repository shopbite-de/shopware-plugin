# ShopBite Shopware 6 Plugin

This plugin is a core component of the [ShopBite](https://github.com/shopbite-de) ecosystem. It provides essential backend functionality and API endpoints to power the ShopBite Nuxt storefront, enabling seamless integration between Shopware 6 and a modern headless frontend.

## Overview

The ShopBite plugin extends Shopware 6 with specialized features tailored for the ShopBite ecosystem. It focuses on providing advanced configuration, business hours management, and checkout enhancements required for a high-performance headless storefront.

## Key Features

- **Business Hours Management**: Define and manage business hours for your store, exposed via Store API.
- **Holiday Management**: Configure store holidays and special closing days.
- **Storefront Configuration**: Custom API endpoints to provide frontend-specific settings to the Nuxt storefront.
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

## Requirements

- Shopware 6.7.0 or higher
- PHP 8.4 or higher

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
