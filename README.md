# Lettermint Magento 2 Module

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lettermint/lettermint-magento2.svg?style=flat-square)](https://packagist.org/packages/lettermint/lettermint-magento2)
[![Total Downloads](https://img.shields.io/packagist/dt/lettermint/lettermint-magento2.svg?style=flat-square)](https://packagist.org/packages/lettermint/lettermint-magento2)
[![Join our Discord server](https://img.shields.io/discord/1305510095588819035?logo=discord&logoColor=eee&label=Discord&labelColor=464ce5&color=0D0E28&cacheSeconds=43200)](https://lettermint.co/r/discord)

Integrate Lettermint email service with your Magento 2 store for reliable transactional and marketing email delivery.

## Requirements

- Magento Open Source or Adobe Commerce 2.4.6 or higher
- PHP 8.2 or higher
- Composer

The module sends email with the [Lettermint PHP SDK](https://github.com/lettermint/lettermint-php) 3.x, which needs Guzzle 7.15.2 or higher. Composer installs it for you.

## Installation

Install the module via Composer:

```bash
composer require lettermint/lettermint-magento2
php bin/magento module:enable Lettermint_Email
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

### Upgrading from 0.1.x

This version uses `lettermint/lettermint-php` 3.x instead of 1.x. If your store has an older Guzzle locked, let Composer update the dependencies too:

```bash
composer update lettermint/lettermint-magento2 --with-all-dependencies
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

Your configuration is kept: the admin settings and their config paths are unchanged.

## Configuration

1. Navigate to **Stores → Configuration → Lettermint → Configuration**
2. Enable the module
3. Enter your Lettermint project sending token
4. Configure sender information and route IDs
5. Save configuration

### Configuration Options

- **API Token**: Your Lettermint project sending token, starting with `lm_` (encrypted storage). Team API tokens cannot send email.
- **Transactional Route**: Route ID for transactional emails (default: `outgoing`)
- **Newsletter Route**: Route ID for newsletter/marketing emails (default: `broadcast`)
- **Sender Configuration**: Default sender name and email address

## Features

- **Transactional Emails**: Order confirmations, password resets, etc. → Configurable route (default: `outgoing`)
- **Newsletter Emails**: Marketing campaigns, newsletters → Configurable route (default: `broadcast`)

## Email Flow

When the module is enabled, a plugin on Magento's transport factory gives every email its own Lettermint transport. The transport sends the email through the Lettermint API in one request. Emails sent from the Magento Newsletter module use the newsletter route; all other emails use the transactional route.

```
Magento email → TransportSwitcher plugin → Lettermint transport → Lettermint API (route)
```

### Attachments and headers

Attachments are sent to Lettermint with the email, on every supported Magento version:

- Magento 2.4.6 and 2.4.7: attachment parts on the Laminas MIME message (Magento `MimePart` or Laminas `Part`), including nested `multipart/alternative` bodies.
- Magento 2.4.8 and newer: the Symfony MIME body, for example `multipart/mixed` with a `multipart/alternative` body and `DataPart` attachments.

Inline images keep their Content-ID, so `cid:` references in the HTML keep working. An attachment without a file name is sent as `attachment-<n>` with an extension for common types. Lettermint's [attachment limits](https://docs.lettermint.co/platform/emails/limitations) apply (25 MB per email, blocked file types).

Custom headers are forwarded: `X-*` headers and `List-*`, `Precedence`, `Auto-Submitted`, `Importance` and `Priority`. Lettermint sets the structural headers (From, To, Subject, Date, Message-ID, MIME and Content headers) itself. Lettermint's own control headers (`X-Lettermint-*`, `X-LM-*`) and headers whose name looks like a credential (for example containing `auth`, `token`, `secret` or `api-key`) are never forwarded.

### Errors

If Lettermint rejects an email, cannot be reached within 15 seconds, or the email cannot be read, the error is logged to Magento's log and Magento receives a `MailException`. The module does not retry.

## Security

- API tokens are encrypted in Magento's configuration storage
- Input validation and sanitization
- Secure API communication via HTTPS
- API tokens are never logged

## Development

The unit tests install `magento/framework` from the public [Mage-OS mirror](https://mirror.mage-os.org/), so no Magento Marketplace credentials are needed. They never call the Lettermint API.

```bash
composer update --ignore-platform-req='ext-*'
composer lint      # php -l on every PHP file
composer test      # PHPUnit
composer analyse   # PHPStan
```

To test against a specific Magento line, constrain the framework version, for example `composer update --ignore-platform-req='ext-*' --with 'magento/framework:103.0.6.*'` for Magento 2.4.6. CI runs Magento 2.4.6 to 2.4.9 on PHP 8.2 to 8.5.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Credits

- [Bjarn Bronsveld](https://github.com/bjarn)
- [All Contributors](../../contributors)
