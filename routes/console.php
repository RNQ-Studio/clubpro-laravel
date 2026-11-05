<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pembersihan asset dua fase. withoutOverlapping mencegah tumpang tindih bila eksekusi lama.
Schedule::command('assets:soft-delete-expired')->dailyAt('01:00')->withoutOverlapping();
Schedule::command('assets:hard-delete-expired')->dailyAt('02:00')->withoutOverlapping();

// Database lokasi IP terbit bulanan; perintahnya melewati bila file bulan ini sudah ada.
Schedule::command('geoip:update')->dailyAt('03:00')->withoutOverlapping();
