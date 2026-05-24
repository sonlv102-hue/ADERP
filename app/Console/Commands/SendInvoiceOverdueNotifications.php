<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\InvoiceOverdueNotification;
use Illuminate\Support\Facades\Notification;

class SendInvoiceOverdueNotifications extends Command
{
    protected $signature = 'notifications:invoice-overdue';
    protected $description = 'Send notifications for overdue invoices';

    public function handle(): void
    {
        $overdueInvoices = Invoice::where('status', 'overdue')->get();
        $accountants = User::role('accounting')->get();

        foreach ($overdueInvoices as $invoice) {
            Notification::send($accountants, new InvoiceOverdueNotification($invoice));
        }

        $this->info("Sent notifications for {$overdueInvoices->count()} overdue invoices.");
    }
}
