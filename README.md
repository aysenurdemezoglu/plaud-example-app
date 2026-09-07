# Plaud Workspace

An intentionally small, framework-free PHP web application that demonstrates how an application can use the independent `plaud/plaud-php` package.

The application owns the web UI, session, and future token persistence. The Composer package owns communication with Plaud.

## Requirements

- PHP 8.1+
- Composer
- `ext-curl`
- A Plaud account while the integration uses the current credential-based PoC flow

## Setup

```bash
copy .env.example .env
composer install
php -S localhost:8080 -t public
```

Open <http://localhost:8080>.

OAuth support will be added after Plaud provides private-beta access and endpoint details.