<template>
  <div>
    <div class="flex border-b border-gray-200 overflow-x-auto text-sm">
      <button v-for="t in tabs" :key="t.key" type="button" @click="tab = t.key"
        :class="['px-3 py-2 whitespace-nowrap border-b-2 -mb-px', tab === t.key ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-gray-500 hover:text-gray-700']">
        {{ t.label }} <span class="text-xs text-gray-400">({{ t.count }})</span>
      </button>
    </div>
    <DataTableWrapper class="mt-2" max-height="320px">
      <table class="min-w-full text-xs">
        <thead class="bg-gray-50 erp-table-sticky-head">
          <tr><th v-for="h in current.head" :key="h" class="text-left px-2 py-1.5 font-semibold text-gray-600 whitespace-nowrap">{{ h }}</th></tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="(r, idx) in current.rows" :key="idx" :class="r._cls">
            <td v-for="(c, j) in r.cells" :key="j" class="px-2 py-1 whitespace-nowrap align-top">
              <a v-if="c && c.href" :href="c.href" target="_blank" class="text-primary-600 hover:underline">{{ c.text }}</a>
              <span v-else :title="typeof c === 'string' ? c : ''">{{ c ?? '—' }}</span>
            </td>
          </tr>
          <tr v-if="!current.rows.length"><td :colspan="current.head.length" class="px-2 py-4 text-center text-gray-400">Không có dữ liệu</td></tr>
        </tbody>
      </table>
    </DataTableWrapper>
    <p v-if="trace.accounting_hidden" class="text-xs text-gray-500 mt-1">Bút toán/WIP ẩn do tài khoản không có quyền xem kế toán.</p>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import DataTableWrapper from '@/Components/Shared/DataTableWrapper.vue'
import { fmtNum, fmtVnd } from '../labels'

const props = defineProps({ trace: Object })
const tab = ref('mv')
const link = (text, href) => (href ? { text, href } : text)

const snapshotText = (s) => s ? `${s.description ?? ''} — SL ${s.deleted_item?.quantity ?? s.old?.quantity ?? '?'} × ${s.deleted_item?.unit_price ?? s.old?.unit_price ?? '?'}` : null

const tabs = computed(() => {
  const t = props.trace
  const list = [
    { key: 'mv', label: 'Movement gốc/đảo', head: ['Thời điểm', 'Chứng từ', 'TT chứng từ', 'Loại', 'SL', 'Đơn giá', 'Giá trị', 'TT movement', 'Dòng hàng', 'Snapshot / ghi chú'],
      rows: t.movements.map(m => ({ _cls: m.status === 'voided' ? 'text-gray-400' : '', cells: [m.created_at, link(m.document, m.link), m.document_status, m.kind,
        fmtNum(m.quantity), fmtVnd(m.unit_cost), fmtVnd(m.amount), m.status,
        m.source_item_id ? `#${m.source_item_id}${m.item_exists === false ? (m.snapshot ? ' (đã xóa, có snapshot)' : ' (đã xóa)') : ''}` : null,
        snapshotText(m.snapshot) ?? m.notes] })) },
    { key: 'avco', label: 'AVCO', head: ['SP', 'Kho', 'SL AVCO', 'Giá trị', 'Giá BQ', 'Khởi tạo'],
      rows: t.balances.map(b => ({ cells: [b.product_id, b.warehouse_id, fmtNum(b.qty_on_hand), fmtVnd(b.value_on_hand), fmtVnd(b.avg_cost), b.initialized_from] })) },
    { key: 'lot', label: 'Lô FIFO / phân bổ', head: ['Loại', 'ID', 'Dự án / phiếu xuất', 'Nhận / phân bổ', 'Đã xuất', 'Đơn giá', 'Trạng thái'],
      rows: [...t.lots.map(l => ({ cells: ['Lô', l.id, l.project_code, fmtNum(l.received_qty), fmtNum(l.issued_qty), fmtVnd(l.unit_cost), l.status] })),
        ...t.allocations.map(a => ({ _cls: a.voided_at ? 'text-gray-400' : '', cells: ['Phân bổ', a.id, a.exit_code, fmtNum(a.allocated_qty), `lô #${a.project_inventory_lot_id}`, fmtVnd(a.unit_cost), a.voided_at ? 'void' : 'hiệu lực'] }))] },
    { key: 'cost', label: 'Giá nhập / giá vốn', head: ['Chứng từ', 'Trạng thái', 'SP', 'SL', 'Đơn giá / giá vốn ĐV', 'Tổng giá vốn', 'Nguồn giá'],
      rows: [...t.entry_prices.map(e => ({ cells: [e.code, e.status, e.product_id, fmtNum(e.quantity), fmtVnd(e.unit_price), null, 'giá nhập'] })),
        ...t.exit_costs.map(x => ({ cells: [x.code, x.status, x.product_id, fmtNum(x.quantity), fmtVnd(x.source_cost), fmtVnd(x.total_cost), `${x.cost_source ?? 'n/a'} · ${x.issue_purpose ?? ''}`] }))] },
    { key: 'hist', label: 'Lịch sử chứng từ', head: ['Thời điểm', 'Đối tượng', 'Nội dung'],
      rows: t.document_history.map(h => ({ cells: [h.created_at, `${(h.subject_type ?? '').split('\\').pop()}#${h.subject_id}`, h.description] })) },
  ]
  if (t.journals) {
    list.splice(4, 0, { key: 'je', label: 'Bút toán / WIP', head: ['JE', 'Ngày', 'TT', 'Tham chiếu', 'TK', 'Nợ', 'Có', 'Dự án'],
      rows: [...t.journals.map(j => ({ _cls: j.status !== 'posted' ? 'text-gray-400' : '', cells: [link(j.code, j.link), j.entry_date, j.status,
        j.reference_type ? `${j.reference_type}#${j.reference_id}` : 'thủ công', j.account_code, fmtVnd(j.debit), fmtVnd(j.credit), j.project_id] })),
        ...(t.wip ?? []).map(w => ({ cells: ['WIP 154', w.entry_date, w.status, `XK#${w.source_id}`, '154', fmtVnd(w.amount), null, null] }))] })
  }
  return list.map(x => ({ ...x, count: x.rows.length }))
})
const current = computed(() => tabs.value.find(t => t.key === tab.value) ?? tabs.value[0])
</script>
