<?php

use Illuminate\Support\Facades\Route;


Route::redirect('/', '/admin/login');
Route::redirect('/login', '/admin/login')->name('login');
