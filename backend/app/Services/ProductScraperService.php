<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Scrapers\ProductParserFactory;
use Illuminate\Support\Facades\DB;

class ProductScraperService
{
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

            if ($data['image_url']) {
                ProductImage::create([
                    'product_id' => $product->id,
                    'image_url' => $data['image_url'],
                    'is_primary' => true,
                    'sort_order' => 0,
                    'created_at' => now(),
                ]);
            }

            return $product->load('images');
        });
    }
}
