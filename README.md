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

For a single-server production-style run, build Vue into the PHP public directory:

```bash
cd frontend
npm run build
cd ..
php -S 127.0.0.1:8080 -t public
```

Then open <http://127.0.0.1:8080>. The PHP fallback remains available until the Vue build exists.

OAuth support will be added after Plaud provides private-beta access and endpoint details.

## Summary API migration

The PHP API now returns `summary` (standard summary) and `customSummary` (custom-template summary, nullable) from `GET /api/recordings/{id}`. The old `transcript` key has been removed. List responses use `hasSummary` and `hasCustomSummary`, mapped from the existing upstream `is_trans` and `is_summary` flags. These list flags are upstream hints, not checks of downloaded content.

The backend supports both the installed legacy SDK and the renamed SDK, using the presence of `customSummary` to select the correct property mapping. Custom-summary nested content and identifier filtering are retained. Only `consumer_note` links are downloaded for custom-template content; `auto_sum_note` is no longer used as a custom-summary fallback.

Vue must migrate its property reads, filters, labels, and copy actions together before using this API contract. Vue source is not updated by this backend change. The PHP fallback page uses the new summary names and the same content resolution as the API.
