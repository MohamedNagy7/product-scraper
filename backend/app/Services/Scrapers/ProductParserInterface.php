<?php

namespace App\Services\Scrapers;

interface ProductParserInterface
{
    /**
     * Parse a fetched product page into normalized product data.
     *
     * Returns null if no product could be found on the page.
     *
     * @return array{title: ?string, price: ?string, currency: ?string, image_url: ?string, source_url: string}|null
     */
    public function parse(string $html, string $url): ?array;
}
