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

        $images = $this->extractImages($product);

        return [
            'title' => $product['name'] ?? null,
            'price' => $this->extractPrice($product),
            'currency' => $product['offers']['priceCurrency'] ?? null,
            'image_url' => $images[0] ?? null,
            'image_urls' => $images,
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

    private function extractImages(array $node): array
    {
        $image = $node['image'] ?? null;
        $urls = [];

        if (is_string($image)) {
            $urls[] = $image;
        } elseif (is_array($image)) {
            if (isset($image['contentUrl'])) {
                // Single ImageObject. contentUrl itself can be one URL or,
                // as seen on Jumia, an array of every product photo.
                $urls = array_merge($urls, $this->normalizeUrlField($image['contentUrl']));
            } elseif (array_is_list($image)) {
                // Array of images: could be plain URL strings, ImageObjects,
                // or a mix of both.
                foreach ($image as $item) {
                    if (is_string($item)) {
                        $urls[] = $item;
                    } elseif (is_array($item)) {
                        if (isset($item['contentUrl'])) {
                            $urls = array_merge($urls, $this->normalizeUrlField($item['contentUrl']));
                        } elseif (isset($item['url'])) {
                            $urls = array_merge($urls, $this->normalizeUrlField($item['url']));
                        }
                    }
                }
            } elseif (isset($image['url'])) {
                $urls = array_merge($urls, $this->normalizeUrlField($image['url']));
            }
        }

        return array_values(array_unique(array_filter($urls, 'is_string')));
    }

    /**
     * A single JSON-LD field (contentUrl, url, etc.) can hold either one
     * URL string or an array of them. Normalize either shape into a list.
     */
    private function normalizeUrlField(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }

        return [];
    }
}