<?php

use App\Http\Controllers\LeadExportController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('leads', 'pages::leads.index')->name('leads.index');
    Route::livewire('leads/create', 'pages::leads.create')->name('leads.create');
    Route::livewire('leads/{leadRequest}', 'pages::leads.show')->name('leads.show');
    Route::get('leads/{leadRequest}/export', LeadExportController::class)->name('leads.export');
});

require __DIR__.'/settings.php';
