<?php

namespace App\Logging;

use DateTimeZone;
use Monolog\LogRecord;

/**
 * Chuyển timestamp log sang giờ Việt Nam (Asia/Ho_Chi_Minh, UTC+7).
 * Chỉ ảnh hưởng log file — không thay đổi now() hay Carbon trong app.
 */
class VietnamTimezoneProcessor
{
    private DateTimeZone $tz;

    public function __construct()
    {
        $this->tz = new DateTimeZone('Asia/Ho_Chi_Minh');
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(datetime: $record->datetime->setTimezone($this->tz));
    }
}
