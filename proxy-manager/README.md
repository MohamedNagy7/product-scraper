# proxy-manager

Small Go microservice that hands out proxies in round-robin order and takes
unhealthy ones out of rotation. Standard library only — no external deps,
so `go build` works with no `go.sum` to fetch.

## Run it

```
go build -o proxy-manager .
SEED_PROXIES="http://user:pass@1.2.3.4:8080,http://5.6.7.8:8080" PORT=8081 ./proxy-manager
```

`SEED_PROXIES` is optional — you can also start empty and `POST /proxies` to
add them later. `PORT` defaults to `8081`.

## Endpoints

| Method | Path                       | Body                    | What it does |
|--------|----------------------------|--------------------------|---------------|
| GET    | `/proxy`                   | —                         | Returns the next healthy proxy (round-robin), skipping unhealthy ones. `503` if none are available. |
| GET    | `/proxies`                 | —                         | Lists every proxy and its health state. |
| POST   | `/proxies`                 | `{"address": "..."}`      | Adds a proxy. Idempotent. |
| POST   | `/proxies/remove`          | `{"address": "..."}`      | Removes a proxy. |
| POST   | `/proxies/report`          | `{"address": "..."}`      | Reports a failed request against a proxy. After 3 consecutive failures it's marked unhealthy and skipped by `/proxy`. |
| POST   | `/proxies/report-success`  | `{"address": "..."}`      | Clears the failure count and marks the proxy healthy again. |

Addresses go in the JSON body rather than the URL path because proxy URLs
(`http://1.2.3.4:8080`) contain slashes.

## Why body-based addressing, and why report/report-success instead of a
health-check loop

A simpler design would be a background goroutine that periodically pings
each proxy. I went with failure reporting from the caller instead because
the caller (Laravel) already knows the instant a request fails or succeeds —
no polling delay, no separate "is this proxy alive" probe that might not
match real request behavior (e.g. a proxy that's up but rate-limiting you).

## Tests

`go test ./... -race` — covers round-robin ordering, the unhealthy-after-3-
failures threshold, recovery via report-success, add/remove idempotency, and
concurrent access under the race detector.
