<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use App\Models\Invoice;

class InvoiceOverdueNotification extends Notification
{
    public function __construct(public Invoice $invoice) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'invoice_overdue',
            'title'   => 'Hóa đơn quá hạn',
            'message' => "Hóa đơn {$this->invoice->code} đã quá hạn thanh toán",
            'url'     => "/accounting/invoices/{$this->invoice->id}",
            'icon'    => 'clock',
            'color'   => 'red',
        ];
    }
}
