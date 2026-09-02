# Product Scraper

A web scraping service: Laravel backend scrapes and stores product data,
a Go microservice manages proxy rotation, and a Next.js frontend displays
the results. Currently scraping Jumia; built to extend to other sites
(e.g. Amazon) without changing the core flow.

## Architecture

```
Next.js  --poll every 30s-->  Laravel API  --GET /proxy-->  proxy-manager (Go)
                                    |        <--report ok/fail--
                                    v
                               fetch + parse
                                    |
                                    v
                               MySQL (products, product_images)
```

- **Laravel** fetches the target page (via a proxy from the Go service, if one's
  available), parses it, and stores the result.
- **proxy-manager** hands out proxy addresses in round-robin order and stops
  handing out ones that keep failing.
- **Next.js** just polls Laravel's `/api/products` and renders a grid — it
  never talks to the Go service directly.

Note: **if the Go service isn't running or its pool is empty, Laravel just
fetches directly** — nothing hard-fails without it.

## Repo structure

```
backend/          Laravel app
proxy-manager/     Go microservice
frontend/          Next.js app
```

## Setup

### 1. Backend (Laravel)

```
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Set your MySQL credentials in `.env`, then:

```
php artisan install:api   # Laravel 11+: routes/api.php isn't wired up by default
php artisan migrate
php artisan serve
```

Backend runs at `http://127.0.0.1:8000`.

### 2. Proxy manager (Go)

```
cd proxy-manager
go build -o proxy-manager .
./proxy-manager
```

Optional env vars:

create a free-proxy-list.txt

Without `free-proxy-list.txt`, the pool starts empty and Laravel falls back to
direct requests — fine for local testing.

### 3. Frontend (Next.js)

```
cd frontend
npm install
cp .env.local.example .env.local
```

Set `NEXT_PUBLIC_API_URL` in `.env.local` to your Laravel URL
(`http://127.0.0.1:8000/api`), then:

```
npm run dev
```

Visit `http://localhost:3000/products`.

## API

| Method | Path | Body | Returns |
|--------|------|------|---------|
| GET | `/api/products` | — | All scraped products with images |
| POST | `/api/products/scrape` | `{"url": "https://..."}` | The newly scraped + saved product |

## Testing the scrape manually

```
curl -X POST http://127.0.0.1:8000/api/products/scrape \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"url": "https://www.jumia.com.eg/<some-product-page>.html"}'
```
