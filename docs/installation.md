# Installation

- [Requirements](#requirements)
- [Install the Package](#install-the-package)
- [Publish and Run the Migrations](#publish-and-run-the-migrations)
- [Install a Gateway](#install-a-gateway)
- [Publish the Configuration](#publish-the-configuration)
- [Use a Dedicated Connection](#use-a-dedicated-connection)
- [Verify the Installation](#verify-the-installation)

This page takes an application that has never run Payline to a working setup. Follow it in one sitting; every step is required unless it says otherwise.

## Requirements

- PHP 8.3 or newer
- Laravel 12 or 13
- One Payline gateway package

## Install the Package

```shell
composer require x-laravel/payline
```

The service provider is discovered automatically. It registers the `payline` and `payline.bin_lookup` managers, the internal collaborators, the callback and webhook routes, and the `payline:doctor` and `payline:reconcile` commands.

## Publish and Run the Migrations

Payline does not load its migrations from the package directory. You publish them into the application, so the schema belongs to the application and can be edited before it is applied.

```shell
php artisan vendor:publish --tag=payline-migrations
php artisan migrate
```

This creates four tables: `payline_payments`, `payline_transactions`, `payline_webhook_logs` and `payline_commission_rates`. Their columns are listed in [Database](database.md).

Skipping this step leaves the application without any Payline table, and the first charge fails on a missing table rather than on a missing migration.

## Install a Gateway

Payline itself talks to no provider. Install the gateway package for the provider you use and follow its own installation notes, then add its credentials under `payline.gateways`:

```php
'gateways' => [
    'iyzico' => [
        'api_key' => env('IYZICO_API_KEY'),
        'secret_key' => env('IYZICO_SECRET_KEY'),
        'base_url' => env('IYZICO_BASE_URL', 'https://sandbox-api.iyzipay.com'),
    ],
],
```

Name the default gateway in the environment:

```shell
PAYLINE_GATEWAY=iyzico
PAYLINE_TEST_MODE=true
```

Resolving a gateway without a configured default throws a `RuntimeException`.

`PAYLINE_TEST_MODE` sends every gateway to its provider's test environment. It defaults to `false`, so an installation that never sets it talks to the live one. Test environments have their own merchant credentials, which belong in the gateway's own environment variables.

## Publish the Configuration

Publishing the configuration file is optional. Do it when you need to change routing, storage or security defaults:

```shell
php artisan vendor:publish --tag=payline-config
```

Every key is documented in [Configuration](configuration.md).

## Use a Dedicated Connection

Payline reads its connection name from `payline.database.connection` and falls back to the application default. Set it when payment data belongs on a separate database:

```shell
PAYLINE_DB_CONNECTION=payments
```

The published migrations read the same key, so the tables are created on the connection you configure.

## Verify the Installation

```shell
php artisan payline:doctor
```

The command resolves the default gateway and checks that all four tables exist on the configured connection. It prints one line per check and exits with a failure code when any of them fails:

```text
  Default gateway ....................................... OK (iyzico)
  Payments table ........................................ OK (payments)
  Transactions table .................................... OK (payments)
  Webhook logs table .................................... OK (payments)
  Commission rates table ................................ OK (payments)
```

Once every check passes, continue with [Payments](payments.md).
