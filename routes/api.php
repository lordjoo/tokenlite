<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
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


Route::get('test-mail',function(){
    $user = [
        'name' => "Learning Laravel",
        'email' => "el3zahaby@gmail.com",
    ];
//    dd(config('mail'));
    Mail::raw('Hi, welcome user!', function ($message) use ($user) {
        $message
            ->to($user['email'], $user['name'])
            ->subject('Welcome!')
            ->from(config('mail.from.address'), config('mail.from.name'))
            ->setBody('<h1>Hi, welcome user!</h1>', 'text/html');
    });
});

Route::get('/stage', 'APIController@stage')->name('stage');
Route::get('/stage/full', 'APIController@stage_full')->name('stage.full');

Route::get('/bonus', 'APIController@bonuses')->name('bonus');
Route::get('/price', 'APIController@prices')->name('price');






Route::group(['middleware' => 'api', 'prefix' => '/', 'namespace' => 'Api\User'], function ($router) {
    Route::group(['middleware' => 'api', 'prefix' => 'auth'], function ($router) {

        Route::post('reset', 'AuthController@reset');
        Route::post('register', 'AuthController@register');
        Route::post('login', 'AuthController@login');
        Route::post('logout', 'AuthController@logout');
        Route::post('refresh', 'AuthController@refresh');
        Route::post('me', 'AuthController@me');

    });
    // home
    Route::get('home', 'HomeController@index')->name('home');


    Route::get('contribute', 'HomeController@contribute')->name('contribute');
    Route::post('/contribute/access', 'User\TokenController@access');


    Route::get('balance', 'HomeController@mytoken_balance')->name('token.balance');

    // profile
    Route::get('profile', 'ProfileController@index')->name('profile');
    Route::post('profile/update', 'ProfileController@update_profile')->name('update');
    Route::post('profile/changePassword', 'ProfileController@change_password')->name('changePass');
});







Route::any('/{any?}', function() {
    throw new App\Exceptions\APIException("Enter a valid endpoint", 400);
})->where('any', '.*');
