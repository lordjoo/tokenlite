<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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



Route::get('/stage', 'APIController@stage')->name('stage');
Route::get('/stage/full', 'APIController@stage_full')->name('stage.full');

Route::get('/bonus', 'APIController@bonuses')->name('bonus');
Route::get('/price', 'APIController@prices')->name('price');





Route::group([
    'middleware' => 'api',
    'prefix' => 'auth',
    'namespace' => 'Api\User'
], function ($router) {

    Route::post('reset', 'AuthController@reset');
    Route::post('register', 'AuthController@register');
    Route::post('login', 'AuthController@login');
    Route::post('logout', 'AuthController@logout');
    Route::post('refresh', 'AuthController@refresh');
    Route::post('me', 'AuthController@me');

});







Route::any('/{any?}', function() {
    throw new App\Exceptions\APIException("Enter a valid endpoint", 400);
})->where('any', '.*');
