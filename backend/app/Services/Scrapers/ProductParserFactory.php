<?php

namespace App\Services\Scrapers;

class ProductParserFactory
{
    /**
     * Map of host substrings -> parser class. Most e-commerce sites emit
     * schema.org Product JSON-LD, so JsonLdProductParser covers a lot of
     * ground on its own. When a site needs bespoke handling (e.g. Amazon
     * hides/varies its structured data more than Jumia does), add its own
     * entry here pointing at a dedicated parser class.
     *
     * @var array<string, class-string<ProductParserInterface>>
     */
    private array $hostMap = [
        'jumia.com' => JsonLdProductParser::class,
        // 'amazon.' => AmazonProductParser::class,
    ];

    public function make(string $url): ProductParserInterface
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';

        foreach ($this->hostMap as $needle => $parserClass) {
            if (str_contains($host, $needle)) {
                return app($parserClass);
            }
        }

        // Fall back to the generic JSON-LD parser for anything unmapped.
        return app(JsonLdProductParser::class);
    }
}
