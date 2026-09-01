# products-frontend

Next.js `/products` page — polls the Laravel API every 30s, responsive
CSS grid of title/price/image.

## Setup

```
npm install
cp .env.local.example .env.local   # point NEXT_PUBLIC_API_URL at your Laravel app
npm run dev
```

Visit `/products` (root `/` redirects there).

## Notes

- Plain CSS grid (`app/globals.css`), no styling framework dependency —
  `repeat(auto-fill, minmax(220px, 1fr))` so it reflows at any width without
  breakpoints.
- Polling uses `setInterval` in a `useEffect`, with a `cancelled` flag so an
  in-flight request from a previous 30s tick can't overwrite state after
  the component unmounts.
- `next/image` needs external image hosts allow-listed — `next.config.mjs`
  already includes Jumia's CDN (`*.jumia.is`). Add Amazon's when you get there.
- Verified with `npm run build` — compiles and type-checks clean.
