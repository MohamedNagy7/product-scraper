<?php

use App\Services\ProductScraperService;
use Illuminate\Support\Facades\Route;

Route::get('/test-scraper', function (ProductScraperService $scraper) {
    $url = 'https://www.jumia.com.eg/generic-t1000-ultra-smart-watch-series-9-black-61735683.html';

    $product = $scraper->scrapeAndStore($url);

    return response()->json($product);
});
