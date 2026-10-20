<?php

use Illuminate\Support\Facades\Route;
use KareliaLabs\Conversions\Http\Controllers\ConversionsController;

Route::prefix('conversions')->name('karelia-conversions.')->group(function () {
    Route::get('/', [ConversionsController::class, 'index'])->name('index');
    Route::post('test', [ConversionsController::class, 'test'])->name('test');
});
