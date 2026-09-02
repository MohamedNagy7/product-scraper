<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Scrapers\ProductParserFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProductScraperService
{
    public const MAX_BATCH_SIZE = 20;

    public function __construct(
        private readonly ScrapingService $scraper,
        private readonly ProductParserFactory $parserFactory,
    ) {
    }

    public function scrapeAndStore(string $url): Product
    {
        $html = $this->scraper->fetch($url);

        $parser = $this->parserFactory->make($url);
        $data = $parser->parse($html, $url);

        if (!$data || !$data['title'] || !$data['price']) {
            throw new \RuntimeException("Could not find product data on {$url}");
        }

        return DB::transaction(function () use ($data) {
            $product = Product::create([
                'title' => $data['title'],
                'price' => $data['price'],
                'created_at' => now(),
            ]);

            $imageUrls = $data['image_urls'] ?? array_filter([$data['image_url'] ?? null]);

            foreach (array_values($imageUrls) as $sortOrder => $imageUrl) {
                ProductImage::create([
                    'product_id' => $product->id,
                    'image_url' => $imageUrl,
                    'is_primary' => $sortOrder === 0,
                    'sort_order' => $sortOrder,
                    'created_at' => now(),
                ]);
            }

            return $product->load('images');
        });
    }

    /**
     * Scrape a batch of URLs. A failure on one URL (network error, parse
     * miss, etc.) does not abort the rest of the batch — each URL gets its
     * own result entry so the caller can see exactly what succeeded.
     *
     * @param array<int, string> $urls
     * @return array<int, array{url: string, status: 'success'|'error', product?: Product, message?: string}>
     */
    public function scrapeAndStoreMany(array $urls): array
    {
        $results = [];

        foreach ($urls as $url) {
            try {
                $results[] = [
                    'url' => $url,
                    'status' => 'success',
                    'product' => $this->scrapeAndStore($url),
                ];
            } catch (Throwable $e) {
                Log::warning("Batch scrape failed for {$url}: " . $e->getMessage());

                $results[] = [
                    'url' => $url,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}