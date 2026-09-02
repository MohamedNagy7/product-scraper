<?php

use App\Services\ProductScraperService;
use Illuminate\Support\Facades\Route;

Route::get('/test-scraper', function (ProductScraperService $scraper) {
    $url = 'https://www.jumia.com.eg/sodo-sodo-head-phone-1004-bluetooth-headphones-for-ultimate-comfort-132048788.html';

    $product = $scraper->scrapeAndStore($url);

    return response()->json($product);
});
