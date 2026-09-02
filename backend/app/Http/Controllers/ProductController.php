<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
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
            ->paginate(10);

        return ProductResource::collection($products)->response();
    }

    public function scrape(Request $request, ProductScraperService $scraper): JsonResponse
    {
        $validated = $request->validate([
            'urls' => ['required', 'array', 'min:1', 'max:' . ProductScraperService::MAX_BATCH_SIZE],
            'urls.*' => ['required', 'url', 'distinct'],
        ]);

        $results = $scraper->scrapeAndStoreMany($validated['urls']);

        $successCount = collect($results)->where('status', 'success')->count();
        $status = match (true) {
            $successCount === count($results) => 201,
            $successCount === 0 => 422,
            default => 207,
        };

        return response()->json(['results' => $results], $status);
    }
}
