<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class ProxyManagerClient
{
    protected Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => config('services.proxy_manager.url', 'http://localhost:8081'),
            'timeout' => 3,
        ]);
    }

    public function next(): ?string
    {
        try {
            $response = $this->http->get('/proxy');
        } catch (GuzzleException $e) {
            Log::error("Failed to get next proxy: " . $e->getMessage());
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = json_decode((string) $response->getBody(), true);

        return $data['address'] ?? null;
    }

    public function reportFailure(string $address): void
    {
        $this->safePost('/proxies/report', $address);
    }

    public function reportSuccess(string $address): void
    {
        $this->safePost('/proxies/report-success', $address);
    }

    private function safePost(string $path, string $address): void
    {
        try {
            $this->http->post($path, ['json' => ['address' => $address]]);
        } catch (GuzzleException $e) {
            Log::error("Failed to report proxy status for {$address} to {$path}: " . $e->getMessage());
        }
    }
}
