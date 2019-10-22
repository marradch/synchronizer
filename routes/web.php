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
Route::get('/', 'LoginController@authPage')->name('home');
Route::get('/authRedirect', 'LoginController@authRedirect');

Route::group(['middleware' => ['vk.token.verfy']], function () { 
	Route::get('/dashboard', 'DashboardController@index')->name('dashboard');
});


