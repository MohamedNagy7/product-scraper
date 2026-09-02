<?php

namespace App\Services\Scrapers;

class JsonLdProductParser implements ProductParserInterface
{
    public function parse(string $html, string $url): ?array
    {
        $nodes = $this->flattenNodes(
            $this->extractJsonLdBlocks($html)
        );

        $product = collect($nodes)->first(
            fn (array $node) => $this->isProductNode($node)
        );

        if (!$product) {
            return null;
        }

        return [
            'title' => $product['name'] ?? null,
            'price' => $this->extractPrice($product),
            'currency' => $product['offers']['priceCurrency'] ?? null,
            'image_url' => $this->extractImage($product),
            'source_url' => $url,
        ];
    }

    /**
     * Pull every <script type="application/ld+json"> block out of the page
     * and decode it. Uses DOMDocument rather than regex so we don't choke
     * on attribute order or nested markup inside the script tag.
     *
     * @return array<int, array>
     */
    private function extractJsonLdBlocks(string $html): array
    {
        $previous = libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        // Jumia (and most real-world pages) will have minor HTML quirks;
        
        $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $blocks = [];

        foreach ($dom->getElementsByTagName('script') as $script) {
            if ($script->getAttribute('type') !== 'application/ld+json') {
                continue;
            }

            $decoded = json_decode($script->textContent, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $blocks[] = $decoded;
            }
        }

        return $blocks;
    }

    /**
     * Sites shape JSON-LD differently: a single object, a top-level array
     * of objects, or (like Jumia) one object wrapping everything in
     * "@graph". Flatten all of that into one flat list of nodes so the
     * rest of the parser doesn't need to care which shape it got.
     *
     * @param array<int, array> $blocks
     * @return array<int, array>
     */
    private function flattenNodes(array $blocks): array
    {
        $nodes = [];

        foreach ($blocks as $block) {
            if (isset($block['@graph']) && is_array($block['@graph'])) {
                array_push($nodes, ...array_values($block['@graph']));
                continue;
            }

            if (array_is_list($block)) {
                array_push($nodes, ...$block);
                continue;
            }

            $nodes[] = $block;
        }

        return $nodes;
    }

    private function isProductNode(array $node): bool
    {
        $type = $node['@type'] ?? null;

        return $type === 'Product'
            || (is_array($type) && in_array('Product', $type, true));
    }

    private function extractPrice(array $node): ?string
    {
        $offers = $node['offers'] ?? null;

        if (!is_array($offers)) {
            return null;
        }

        // Single Offer
        if (isset($offers['price'])) {
            return (string) $offers['price'];
        }

        // AggregateOffer (price range) - take the low end
        if (isset($offers['lowPrice'])) {
            return (string) $offers['lowPrice'];
        }

        // Array of Offer nodes
        if (array_is_list($offers) && isset($offers[0]['price'])) {
            return (string) $offers[0]['price'];
        }

        return null;
    }

    private function extractImage(array $node): ?string
    {
        $image = $node['image'] ?? null;

        if (is_string($image)) {
            return $image;
        }

        if (!is_array($image)) {
            return null;
        }

        if (isset($image['contentUrl'])) {
            return is_array($image['contentUrl'])
                ? ($image['contentUrl'][0] ?? null)
                : $image['contentUrl'];
        }

        if (array_is_list($image)) {
            $first = $image[0] ?? null;

            if (is_string($first)) {
                return $first;
            }

            return $first['contentUrl'][0] ?? $first['url'] ?? null;
        }

        return $image['url'] ?? null;
    }
}
