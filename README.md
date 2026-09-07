# Plaud Workspace

An intentionally small PHP backend and Vue frontend that demonstrates how an application can use the independent `plaud/plaud-php` package.

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

## Vue frontend

The Vue frontend lives in `frontend/` and communicates with the PHP API through the Vite development proxy.

```bash
cd frontend
npm install
npm run dev
```

Run the PHP API in a second terminal:

```bash
php -S 127.0.0.1:8080 -t public
```

Open the Vite URL shown in the terminal, usually <http://localhost:5173>.

OAuth support will be added after Plaud provides private-beta access and endpoint details.
