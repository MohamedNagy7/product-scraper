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
  available), parses it, and stores the result. If a proxy fails or times out
  mid-request, it retries against a different proxy from the pool (up to 4
  attempts) before giving up on that URL.
- **proxy-manager** actively verifies every proxy before handing it out —
  each one is test-fetched through a bounded pool of goroutines on startup,
  and re-checked every 15 minutes — rather than trusting the seed list on
  faith. It also stops handing out proxies that keep failing on real
  requests.
- **Next.js** just polls Laravel's `/api/products` and renders a paginated
  grid — it never talks to the Go service directly.

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

Without `free-proxy-list.txt`, there's nothing to verify and the pool stays
empty — Laravel falls back to direct requests, which is fine for local
testing.

With a seed file present, the pool still starts empty on boot: each address
is verified concurrently in the background (a quick HTTPS test-fetch per
proxy) and only added once it passes, so this can take anywhere from a few
seconds to a couple of minutes depending on list size. `GET /proxy` returns
503 gracefully in the meantime.

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
| GET | `/api/products` | — | Paginated products (10/page) with images. Standard Laravel paginator shape: `{data, links, meta}`. Accepts `?page=` |
| POST | `/api/products/scrape` | `{"urls": ["https://...", "https://..."]}` (1–20 URLs) | `{"results": [{"url", "status": "success"\|"error", "product"?, "message"?}]}`. HTTP 201 if all succeeded, 207 if partial, 422 if all failed |

## Testing the scrape manually

```
curl -X POST http://127.0.0.1:8000/api/products/scrape \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"urls": ["https://www.jumia.com.eg/<some-product-page>.html"]}'
```

Batching multiple URLs in one call:

```
curl -X POST http://127.0.0.1:8000/api/products/scrape \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"urls": [
        "https://www.jumia.com.eg/<product-1>.html",
        "https://www.jumia.com.eg/<product-2>.html"
      ]}'
```

Note: scraping is synchronous per request, and a single URL can take up to
~40s worst case (network fetch + up to 4 proxy retries). Batches are capped
at 20 URLs to keep requests from timing out — for larger volumes, this would
need a queued-job version rather than the current synchronous one.