<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketLog;
use Illuminate\Support\Facades\Auth;

class TicketService
{
    private const TRANSITIONS = [
        'open'        => ['in_progress'],
        'in_progress' => ['resolved', 'open'],
        'resolved'    => ['closed', 'open'],
        'closed'      => [],
    ];

    public function transition(Ticket $ticket, TicketStatus $newStatus): void
    {
        $allowed = self::TRANSITIONS[$ticket->status->value] ?? [];

        if (! in_array($newStatus->value, $allowed)) {
            throw new \RuntimeException(
                "Không thể chuyển từ {$ticket->status->label()} sang {$newStatus->label()}."
            );
        }

        $oldStatus = $ticket->status;

        $updates = ['status' => $newStatus];

        if ($newStatus === TicketStatus::Resolved) {
            $updates['resolved_at'] = now();
        } elseif ($newStatus === TicketStatus::Closed) {
            $updates['closed_at'] = now();
        }

        $ticket->update($updates);

        $this->log($ticket, 'status_change', $oldStatus->value, $newStatus->value);
    }

    public function assign(Ticket $ticket, ?int $userId): void
    {
        $oldAssignee = $ticket->assigned_to;
        $ticket->update(['assigned_to' => $userId]);

        $this->log($ticket, 'assign', (string) $oldAssignee, (string) $userId);
    }

    public function addNote(Ticket $ticket, string $note): TicketLog
    {
        return $this->log($ticket, 'note', null, null, $note);
    }

    private function log(Ticket $ticket, string $action, ?string $oldValue, ?string $newValue, ?string $note = null): TicketLog
    {
        return TicketLog::create([
            'ticket_id' => $ticket->id,
            'user_id'   => Auth::id(),
            'action'    => $action,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'note'      => $note,
        ]);
    }
}
