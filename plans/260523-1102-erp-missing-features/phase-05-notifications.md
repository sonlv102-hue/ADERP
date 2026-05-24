# Phase 05 — Notifications (Thông báo)

## Context Links
- Laravel built-in database notifications: `php artisan notifications:table`.
- Trigger points: `app/Services/StockService.php::confirmExit` (low stock), Ticket create, Invoice overdue (artisan command).
- UI integration: `resources/js/Components/Layout/TopBar.vue`.

## Overview
- **Priority:** P2
- **Status:** Completed
- Provide bell icon + dropdown in TopBar with unread badge. Three notification types in V1. Poll unread count every 30s.

## Key Insights
- Use Laravel's `Notifiable` trait + `database` channel — no extra package.
- Notifications table created by `php artisan notifications:table` migration (uuid PK, morph `notifiable`, JSON `data`, `read_at`).
- Polling: simple `setInterval(30000)` in TopBar; cancel on unmount.
- Low-stock threshold: read from `settings` (or hardcode default 5). Trigger after StockExit confirm. Notify users with role `warehouse`/`admin`.
- Invoice overdue: artisan command `notifications:invoice-overdue` runs daily via scheduler. Notify creator + accounting role.
- Ticket created: notify users with `tickets.assign` capability (or technical role).

## Requirements
- 3 notification classes in `app/Notifications/`.
- 1 controller `NotificationController` (index/markRead/markAllRead/unreadCount).
- TopBar bell + dropdown component.
- Routes (no permission middleware — every authenticated user has notifications).
- Artisan command + schedule entry.

## Architecture
- Migration: `2026_05_23_900016_create_notifications_table.php` (output of `notifications:table`).
- Controllers: `app/Http/Controllers/NotificationController.php`.
- Notifications: `LowStockNotification`, `TicketCreatedNotification`, `InvoiceOverdueNotification`.
- Command: `app/Console/Commands/InvoiceOverdueNotify.php` (kebab signature `notifications:invoice-overdue`).
- Schedule registered in `routes/console.php` (Laravel 12 convention) OR `bootstrap/app.php` `withSchedule()`.
- Vue: `Shared/NotificationDropdown.vue` mounted in `TopBar.vue`.

## Related Code Files

**Create:**
- `database/migrations/2026_05_23_900016_create_notifications_table.php` (artisan-generated)
- `app/Notifications/LowStockNotification.php`
- `app/Notifications/TicketCreatedNotification.php`
- `app/Notifications/InvoiceOverdueNotification.php`
- `app/Http/Controllers/NotificationController.php`
- `app/Console/Commands/InvoiceOverdueNotify.php`
- `resources/js/Components/Shared/NotificationDropdown.vue`

**Modify:**
- `app/Models/User.php` — ensure `use Notifiable;` (likely already present).
- `app/Services/StockService.php` — after `confirmExit` transaction, dispatch `LowStockNotification` if any product crosses threshold.
- `app/Http/Controllers/Support/TicketController.php` — on `store`, send `TicketCreatedNotification`.
- `resources/js/Components/Layout/TopBar.vue` — mount `<NotificationDropdown />`.
- `routes/web.php` — add notifications routes (authenticated, no permission gate).
- `routes/console.php` — schedule `notifications:invoice-overdue` daily.

## Implementation Steps
1. Run `php artisan notifications:table` to generate the migration; rename file to use the next number `2026_05_23_900016`.
2. Run `php artisan migrate`.
3. `User` model: confirm `use Notifiable;`. Already present in Laravel default — verify.
4. Create `LowStockNotification`:
   - Constructor: `Product $product, int $currentStock, Warehouse $warehouse`.
   - `via()`: return `['database']`.
   - `toDatabase()`: return array with `type` (low-stock), `product_id`, `product_name`, `warehouse_id`, `warehouse_name`, `current_stock`, `url => route('catalog.products.show', $product)`.
5. Create `TicketCreatedNotification`:
   - Constructor: `Ticket $ticket`.
   - `toDatabase()`: `type` (ticket-created), `ticket_id`, `ticket_code`, `subject`, `url => route('support.tickets.show', $ticket)`.
6. Create `InvoiceOverdueNotification`:
   - Constructor: `Invoice $invoice, int $daysOverdue`.
   - `toDatabase()`: `type` (invoice-overdue), `invoice_id`, `invoice_code`, `customer_name`, `days_overdue`, `url => route('accounting.invoices.show', $invoice)`.
7. `StockService::confirmExit` — after the existing transaction:
   - Compute new stock per product/warehouse via `SUM(stock_movements.quantity)`.
   - Read threshold from setting `low_stock_threshold` (default 5).
   - For each product crossing below threshold: `Notification::send(User::role(['warehouse','admin'])->get(), new LowStockNotification(...))`.
8. `TicketController::store` — after ticket created:
   - `Notification::send(User::role(['technical','admin'])->get(), new TicketCreatedNotification($ticket))`.
9. `NotificationController`:
   - `index()`: paginate `auth()->user()->notifications()->paginate(20)`. Inertia render `Shared/NotificationsIndex.vue` (optional) OR return JSON for dropdown.
   - For dropdown we expose JSON endpoints:
     - `latest()`: returns last 10 + unread_count as JSON.
     - `markRead(string $id)`: `auth()->user()->notifications()->where('id',$id)->update(['read_at'=>now()])`; JSON ok.
     - `markAllRead()`: `auth()->user()->unreadNotifications->markAsRead()`.
     - `unreadCount()`: returns `{ count: int }` JSON.
10. Artisan command `InvoiceOverdueNotify`:
    - `signature = 'notifications:invoice-overdue'`.
    - `handle()`: query `Invoice` where `due_date < now()` and `status` in [Sent, PartiallyPaid] (use existing InvoiceStatus enum), compute `days_overdue`, notify creator + users with `accounting` role.
11. Schedule the command daily at 08:00 in `routes/console.php`:
    ```php
    Schedule::command('notifications:invoice-overdue')->dailyAt('08:00');
    ```
12. Routes (authenticated group, NO permission middleware):
    ```php
    Route::get('notifications', [NotificationController::class,'index'])->name('notifications.index');
    Route::get('notifications/latest', [NotificationController::class,'latest'])->name('notifications.latest');
    Route::get('notifications/unread-count', [NotificationController::class,'unreadCount'])->name('notifications.unread-count');
    Route::post('notifications/{id}/read', [NotificationController::class,'markRead'])->name('notifications.mark-read');
    Route::post('notifications/mark-all-read', [NotificationController::class,'markAllRead'])->name('notifications.mark-all-read');
    ```
13. Vue `NotificationDropdown.vue` (under 200 lines):
    - Bell icon + numeric badge.
    - On mount: `fetchLatest()` (axios GET `notifications.latest`) + `setInterval(fetchUnreadCount, 30000)`.
    - Cancel interval on `onBeforeUnmount`.
    - Click bell → toggle dropdown; render list; each item links to `data.url`; click marks read.
    - "Mark all read" button.
14. `TopBar.vue` — insert `<NotificationDropdown />` between `flex-1` spacer and user menu.

## Todo List
- [ ] Generate + run notifications migration
- [ ] Notification classes (3)
- [ ] NotificationController + 5 routes
- [ ] Wire `StockService::confirmExit` low-stock trigger
- [ ] Wire `TicketController::store` notification
- [ ] Artisan command + schedule
- [ ] `NotificationDropdown.vue`
- [ ] Mount in `TopBar.vue`
- [ ] Smoke test: confirm exit on low-stock product → bell shows 1 → click → marks read

## Success Criteria
- Bell badge updates within 30s of new notification.
- Unread count decreases when notification opened.
- Mark-all-read clears badge.
- `php artisan notifications:invoice-overdue` queues overdue notifications for accounting + creator.
- Polling stops when user logs out (component unmounts).

## Risk Assessment
- N+1 on `auth()->user()->notifications()` — paginate + cap to 10 in dropdown.
- LowStock spam: only fire when stock crosses below threshold (not every confirmExit). Compute pre/post stock; fire only if `pre >= threshold && post < threshold`.
- Setting `low_stock_threshold` may not exist — add default + fallback in code.

## Security Considerations
- Routes only check `auth` middleware; notifications scoped to `auth()->user()` automatically via `auth()->user()->notifications()`.
- markRead by id: verify ownership (`where('id', $id)` already scoped via `auth()->user()->notifications()`).

## Next Steps
- V2: websocket (Reverb) for push instead of polling.
- V2: per-user notification preferences (opt-out).
