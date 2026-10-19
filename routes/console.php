<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('idempotency:prune')->dailyAt('02:00');
Schedule::command('inventory:expire-check --force')->dailyAt('01:00');
// Tiap jam, bukan harian: kantong yang reservasinya habis jam 09:00 tidak boleh
// menunggu sampai tengah malam untuk kembali ke stok.
Schedule::command('request:expire-reservations --force')->hourly();
Schedule::command('privacy:purge-expired --force')->monthlyOn(1, '03:00');
// Jaring pengaman untuk event DonorLocationChanged yang hilang -- bukan jalur utamanya.
Schedule::command('donor:reconcile-geo --force')->dailyAt('03:30');
