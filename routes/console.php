<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('meta:resync-ad-metrics {--discover} {--ad=}', function () {
    return $this->call('ads:resync-metrics', [
        '--discover' => (bool) $this->option('discover'),
        '--ad' => $this->option('ad'),
    ]);
})->purpose('Alias for ads:resync-metrics');
