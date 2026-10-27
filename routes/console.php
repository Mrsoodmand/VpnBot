<?php

use App\Services\OrderCheckSchedule;
use App\Services\OrderLifecycleNotificationService;
use App\Services\OrderLifecycleService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('orders:sync-lifecycle', function () {
    if (! app(OrderCheckSchedule::class)->available()) {
        $this->error('Run the order_checks migration before enabling the lifecycle cron.');

        return 1;
    }
    // Also covers direct cron/manual invocations, not only schedule:run.
    $lock = Cache::lock('orders:lifecycle:runner', 900);
    if (! $lock->get()) {
        $this->info('Lifecycle worker is already running.');

        return;
    }
    try {
        $result = [
            'at' => now()->toDateTimeString(),
            'synchronization' => app(OrderLifecycleService::class)->synchronizeAll(),
            'notifications' => app(OrderLifecycleNotificationService::class)->sendDueNotifications(),
        ];
        Cache::put('orders:lifecycle:last-run', $result, now()->addDays(7));
        $this->info(json_encode($result, JSON_UNESCAPED_UNICODE));
    } finally {
        $lock->release();
    }
})->purpose('Synchronize order lifecycle and send due renewal/volume notifications');

Schedule::command('orders:sync-lifecycle')
    ->everyMinute()
    ->withoutOverlapping(30);
