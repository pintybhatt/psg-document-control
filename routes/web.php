<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

$pages = [
    'dashboard' => '/dashboard',
    'incoming' => '/incoming',
    'outgoing' => '/outgoing',
    'controlled-documents' => '/controlled-documents',
    'document-search' => '/document-search',
    'reports' => '/reports',
    'administration' => '/administration',
];

foreach ($pages as $page => $path) {
    Route::view($path, 'app', ['page' => $page, 'pages' => $pages])->name('dcms.'.$page);
}
