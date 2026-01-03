# Lettermint Magento 2 Module

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lettermint/lettermint-magento2.svg?style=flat-square)](https://packagist.org/packages/lettermint/lettermint-magento2)
[![Total Downloads](https://img.shields.io/packagist/dt/lettermint/lettermint-magento2.svg?style=flat-square)](https://packagist.org/packages/lettermint/lettermint-magento2)
[![Join our Discord server](https://img.shields.io/discord/1305510095588819035?logo=discord&logoColor=eee&label=Discord&labelColor=464ce5&color=0D0E28&cacheSeconds=43200)](https://lettermint.co/r/discord)

Integrate Lettermint email service with your Magento 2 store for reliable transactional and marketing email delivery.

## Requirements

- Magento 2.3.0 or higher
- PHP 8.1 or higher
- Composer

## Installation

Install the module via Composer:

```bash
composer require lettermint/lettermint-magento2
php bin/magento module:enable Lettermint_Email
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

## Configuration

1. Navigate to **Stores → Configuration → Lettermint → Configuration**
2. Enable the module
3. Enter your Lettermint API token
4. Configure sender information and route IDs
5. Save configuration

### Configuration Options

- **API Token**: Your Lettermint service API token (encrypted storage)
- **Transactional Route**: Route ID for transactional emails (default: `outgoing`)
- **Newsletter Route**: Route ID for newsletter/marketing emails (default: `broadcast`)
- **Sender Configuration**: Default sender name and email address

## Features

- **Transactional Emails**: Order confirmations, password resets, etc. → Configurable route (default: `outgoing`)
- **Newsletter Emails**: Marketing campaigns, newsletters → Configurable route (default: `broadcast`)
## Email Flow

### Transactional Emails
```
Magento Email System → TransportPlugin → Lettermint API (outgoing route)
```

### Newsletter Emails
```
Magento Newsletter → newsletter_send_after event → NewsletterSendObserver → Lettermint API (broadcast route)
```

## Security

- API tokens are encrypted in Magento's configuration storage
- Input validation and sanitization
- Secure API communication via HTTPS
- No sensitive data logged in plain text

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Credits

- [Bjarn Bronsveld](https://github.com/bjarn)
- [All Contributors](../../contributors)
