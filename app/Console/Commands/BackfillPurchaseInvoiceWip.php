<?php

namespace App\Console\Commands;

use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseInvoice;
use App\Models\ProjectWipEntry;
use App\Services\ProjectWipService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillPurchaseInvoiceWip extends Command
{
    protected $signature = 'projects:backfill-purchase-invoice-wip {--apply} {--limit=0}';
    protected $description = 'Backfill project WIP entries from purchase invoice items (TK154)';

    public function handle(ProjectWipService $wipService)
    {
        $apply = $this->option('apply');
        $limit = (int) $this->option('limit');

        $this->info(($apply ? 'Apply' : 'Dry-run') . ' backfill: purchase invoice WIP entries');

        $query = PurchaseInvoiceItem::query()
            ->whereRaw("account_code LIKE '154%'")
            ->where(function ($q) {
                $q->whereNotNull('project_id')
                  ->orWhereHas('invoice', function ($qi) {
                      $qi->whereNotNull('project_id');
                  });
            })
            ->whereHas('invoice', function ($qinv) {
                $qinv->whereIn('status', ['valid', 'partial_paid', 'paid']);
            });

        if ($limit > 0) $query->limit($limit);

        $items = $query->with('invoice')->get();

        $toCreate = [];
        foreach ($items as $item) {
            $invoice = $item->invoice;
            $projId = $item->project_id ?? $invoice->project_id ?? null;
            if (!$projId) continue;

            $exists = ProjectWipEntry::where('source_type', PurchaseInvoice::class)
                ->where('source_id', $invoice->id)
                ->where('source_item_id', $item->id)
                ->exists();

            if ($exists) continue;

            $toCreate[] = [
                'project_id'  => $projId,
                'invoice_id'  => $invoice->id,
                'item_id'     => $item->id,
                'amount'      => (int) round((float) $item->amount),
                'vat'         => (float) $item->tax_amount,
                'invoice_code'=> $invoice->code,
            ];
        }

        if (empty($toCreate)) {
            $this->info('No missing WIP entries found.');
            return 0;
        }

        $this->table(['invoice', 'item', 'project', 'amount', 'vat'], array_map(function ($r) {
            return [$r['invoice_code'], $r['item_id'], $r['project_id'] ?? '', $r['amount'], $r['vat']];
        }, $toCreate));

        if (! $apply) {
            $this->info('Dry-run complete. Use --apply to create entries.');
            return 0;
        }

        DB::transaction(function () use ($toCreate, $wipService) {
            foreach ($toCreate as $r) {
                ProjectWipEntry::create([
                    'project_id' => $r['project_id'],
                    'source_type'=> PurchaseInvoice::class,
                    'source_id'  => $r['invoice_id'],
                    'source_item_id' => $r['item_id'],
                    'cost_type'  => 'overhead',
                    'amount'     => $r['amount'],
                    'vat_amount' => $r['vat'],
                    'description'=> "Backfill PI {$r['invoice_code']} - item {$r['item_id']}",
                    'entry_date' => now(),
                    'created_by' => 1,
                ]);
            }
        });

        $this->info('Backfill applied: ' . count($toCreate) . ' entries created.');
        return 0;
    }
}
