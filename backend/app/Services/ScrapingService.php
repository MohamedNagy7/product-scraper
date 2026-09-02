<?php

namespace App\Services;

use GuzzleHttp\Client;

class ScrapingService
{
    protected Client $client;

    private const MAX_ATTEMPTS = 4;
    private const RETRYABLE_STATUSES = [403, 407, 408, 425, 429, 500, 502, 503, 504];

    private array $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/18.1 Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 Version/17.1 Mobile/15E148 Safari/605.1.15',
        'Mozilla/5.0 (iPad; CPU OS 17_1 like Mac OS X) AppleWebKit/605.1.15 Version/17.1 Mobile/15E148 Safari/605.1.15',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/129.0.0.0 Safari/537.36',
    ];

    public function __construct(protected ProxyManagerClient $proxyManager)
    {
        $this->client = new Client([
            'timeout' => 10,
            'connect_timeout' => 5,
            'http_errors' => false,
        ]);
    }

    public function fetch(string $url): string
    {
        $lastException = null;
        $triedAddresses = [];

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            // If the proxy manager has nothing (empty pool, or it's unreachable),
            // next() returns null and we just go direct.
            $proxy = $this->proxyManager->next();

            if ($proxy !== null && in_array($proxy, $triedAddresses, true)) {
                break;
            }
            if ($proxy !== null) {
                $triedAddresses[] = $proxy;
            }

            $options = [
                'headers' => [
                    'User-Agent' => $this->getRandomUserAgent(),
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ],
            ];

            if ($proxy) {
                $options['proxy'] = $proxy;
            }

            try {
                $response = $this->client->get($url, $options);
            } catch (\Throwable $e) {
                // Network failure, timeout, or a proxy MITM-ing the TLS
                $lastException = $e;
                if ($proxy) {
                    $this->proxyManager->reportFailure($proxy);
                }
                continue;
            }

            $status = $response->getStatusCode();

            if ($status === 200) {
                if ($proxy) {
                    $this->proxyManager->reportSuccess($proxy);
                }
                return (string) $response->getBody();
            }

            if (in_array($status, self::RETRYABLE_STATUSES, true)) {
                if ($proxy) {
                    $this->proxyManager->reportFailure($proxy);
                }
                $lastException = new \RuntimeException(
                    "Failed to fetch page. HTTP status: {$status}"
                );
                continue;
            }

            if ($proxy) {
                $this->proxyManager->reportSuccess($proxy);
            }
            throw new \RuntimeException(
                "Failed to fetch page. HTTP status: {$status}"
            );
        }

        throw new \RuntimeException(
            "Failed to fetch {$url} after " . count($triedAddresses) . " proxy attempt(s)",
            0,
            $lastException
        );
    }

    public function getRandomUserAgent(): string
    {
        return $this->userAgents[array_rand($this->userAgents)];
    }
}