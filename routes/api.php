<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ElasticSearchController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

  
// elastic search
 Route::post('elastic-search/search/', [ElasticSearchController::class, 'searchGlobal']);
 Route::post('elastic-search/search-with-title/', [ElasticSearchController::class, 'searchWithTitle']);
 Route::get('elastic-search/autocomplete-sentence/', [ElasticSearchController::class, 'autoCompleteSentence']);
 Route::get('elastic-search/search-history/', [ElasticSearchController::class, 'searchKeywordHistory']);
 Route::get('elastic-search/search-save/', [ElasticSearchController::class, 'searchSave']);
 Route::get('elastic-search/search-with-ai', [ElasticSearchController::class, 'searchWithAI']);
 Route::get('elastic-search/search-files-id', [ElasticSearchController::class, 'searchableFilesId']);
 Route::delete('/elastic-search/delete-history/{id}', [ElasticSearchController::class, 'deleteSearchedHistory']);


