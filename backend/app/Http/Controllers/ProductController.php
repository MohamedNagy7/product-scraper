<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ProductScraperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        $products = Product::with('images')
            ->latest('created_at')
            ->get();

        return response()->json($products);
    }

    public function scrape(Request $request, ProductScraperService $scraper): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url'],
        ]);

        $product = $scraper->scrapeAndStore($validated['url']);

        return response()->json($product, 201);
    }
}
