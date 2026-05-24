<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use App\Models\Ticket;

class TicketCreatedNotification extends Notification
{
    public function __construct(public Ticket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'ticket_created',
            'title'   => 'Ticket mới',
            'message' => "Ticket #{$this->ticket->code}: {$this->ticket->title}",
            'url'     => "/support/tickets/{$this->ticket->id}",
            'icon'    => 'ticket',
            'color'   => 'blue',
        ];
    }
}
