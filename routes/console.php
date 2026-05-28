<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hàng ngày: gửi thông báo + đánh dấu HĐ quá hạn
Schedule::command('notifications:invoice-overdue')->daily();
Schedule::command('accounting:mark-overdue')->dailyAt('01:00');

// Ngày cuối tháng: chạy toàn bộ nghiệp vụ đóng tháng
Schedule::command('accounting:month-end')->monthlyOn(28, '02:00');
