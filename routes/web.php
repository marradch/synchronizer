<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/
Route::get('/', 'VKAuthController@authPage')->name('home');
Route::get('/authRedirect', 'VKAuthController@authRedirect')->name('vk.redirect_uri');
Route::get('/logout', 'VKAuthController@logout')->name('vk.logout');
Route::get('/auth/error/{code}', 'VKAuthController@displayError')->name('auth.error');

Route::group(['middleware' => ['vk.token.verify']], function () {
    Route::get('/choose-group', 'VKAuthController@chooseGroup')->name('auth.choose.group');
    Route::post('/set-group', 'VKAuthController@setGroup')->name('auth.set.group');
    Route::group(['middleware' => ['vk.group.verify']], function () {
        Route::get('/dashboard', 'DashboardController@index')->name('dashboard');

        Route::get('/categories', 'CategoryController@index')->name('categories');
        Route::get('/get-categories-db', 'CategoryController@getCategories')->name('get-categories-db');

        Route::get('/set-load-to-vk-yes/{ids}', 'CategoryController@setLoadToVKYes')->name('set-load-to-vk-yes');
        Route::get('/set-load-to-vk-no/{ids}', 'CategoryController@setLoadToVKNo')->name('set-load-to-vk-no');

        Route::get('/get-selected-count', 'CategoryController@getSelectedCount')->name('get-selected-count');

        Route::get('/albums', 'AlbumController@index')->name('album');
        Route::get('/get-albums/{page}', 'AlbumController@getAlbums')->name('get-albums');
        Route::post('/set-task', 'AlbumController@setTask')->name('set-task');
    });
});
