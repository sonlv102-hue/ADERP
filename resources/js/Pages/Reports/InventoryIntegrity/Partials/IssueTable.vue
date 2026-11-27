<template>
  <DataTableWrapper class="bg-white rounded-xl border border-gray-200">
    <table class="min-w-full text-sm">
      <thead class="bg-gray-50 border-b border-gray-200 erp-table-sticky-head">
        <tr>
          <th v-if="selectable" class="px-3 py-3 w-8">
            <input type="checkbox" :checked="allChecked" aria-label="Chọn tất cả" @change="toggleAll($event.target.checked)" />
          </th>
          <th v-for="c in columns" :key="c.key" :class="['px-4 py-3 font-semibold text-gray-600 whitespace-nowrap', c.right ? 'text-right' : 'text-left',
              c.sort ? 'cursor-pointer select-none hover:text-gray-900' : '']" @click="c.sort && $emit('sort', c.sort)">
            {{ c.label }}<span v-if="c.sort && sort === c.sort">{{ direction === 'desc' ? ' ↓' : ' ↑' }}</span>
          </th>
          <th class="px-4 py-3"></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <tr v-for="i in rows" :key="i.id" class="hover:bg-gray-50">
          <td v-if="selectable" class="px-3 py-2">
            <input type="checkbox" :value="i.id" :checked="modelValue.includes(i.id)" :aria-label="'Chọn ' + i.code" @change="toggle(i.id)" />
          </td>
          <td class="px-4 py-2 font-mono font-medium text-primary-700 whitespace-nowrap">{{ i.code }}</td>
          <td class="px-4 py-2 whitespace-nowrap">
            <div class="text-gray-900">{{ RULE_LABELS[i.rule_code] ?? i.rule_code }}</div>
            <div class="text-xs text-gray-500">{{ CATEGORY_LABELS[i.category] }} · <span :class="sevClass(i.severity)">{{ SEVERITY[i.severity]?.[0] }}</span>
              <span v-if="i.is_legacy" class="ml-1 text-gray-400">· legacy</span></div>
          </td>
          <td class="px-4 py-2 min-w-[180px]">
            <template v-if="i.product_code"><span class="font-mono text-xs text-gray-500">{{ i.product_code }}</span>
              <div class="text-gray-900 truncate max-w-[240px]" :title="i.product_name">{{ i.product_name }}</div></template>
            <span v-else class="text-gray-400">{{ i.source === 'policy' ? 'Chính sách' : 'Nhiều SP / tổng hợp' }}</span>
          </td>
          <td class="px-4 py-2 text-gray-600 whitespace-nowrap">{{ i.warehouse ?? '—' }}<span v-if="i.project"> · {{ i.project }}</span></td>
          <td class="px-4 py-2 text-gray-600 max-w-[200px] truncate" :title="i.document_codes">{{ i.document_ref ?? i.document_codes ?? '—' }}</td>
          <td class="px-4 py-2 text-right whitespace-nowrap" :class="Number(i.qty_diff) ? 'text-red-600 font-medium' : ''">{{ fmtNum(i.qty_diff) }}</td>
          <td class="px-4 py-2 text-right whitespace-nowrap" :class="Number(i.value_diff) ? 'text-red-600 font-medium' : ''">{{ fmtVnd(i.value_diff) }}</td>
          <td class="px-4 py-2 whitespace-nowrap"><StatusBadge :color="STATUS_COLORS[i.status]">{{ STATUS_LABELS[i.status] }}</StatusBadge>
            <div v-if="i.reopen_count" class="text-xs text-orange-600 mt-0.5">Mở lại {{ i.reopen_count }} lần</div></td>
          <td class="px-4 py-2 text-gray-600 whitespace-nowrap">{{ i.assignee ?? '—' }}<div v-if="i.due_date" class="text-xs text-gray-400">Hạn {{ i.due_date }}</div></td>
          <td class="px-4 py-2 text-gray-500 text-xs whitespace-nowrap">{{ i.last_checked_at }}</td>
          <td class="px-4 py-2 text-right whitespace-nowrap">
            <button type="button" class="text-primary-600 hover:text-primary-800 text-xs font-medium" @click="$emit('open', i)">Chi tiết</button>
          </td>
        </tr>
        <tr v-if="!rows.length">
          <td :colspan="columns.length + (selectable ? 2 : 1)" class="px-5 py-10 text-center text-gray-400">Không có vấn đề nào theo bộ lọc</td>
        </tr>
      </tbody>
    </table>
  </DataTableWrapper>
</template>

<script setup>
import { computed } from 'vue'
import DataTableWrapper from '@/Components/Shared/DataTableWrapper.vue'
import StatusBadge from '@/Components/Shared/StatusBadge.vue'
import { RULE_LABELS, STATUS_LABELS, STATUS_COLORS, CATEGORY_LABELS, SEVERITY, fmtNum, fmtVnd } from '../labels'

const props = defineProps({ rows: Array, modelValue: { type: Array, default: () => [] }, selectable: Boolean, sort: String, direction: String })
const emit = defineEmits(['update:modelValue', 'open', 'sort'])

const columns = [
  { key: 'code', label: 'Mã', sort: 'id' }, { key: 'rule', label: 'Nhóm sai lệch', sort: 'rule_code' }, { key: 'product', label: 'Sản phẩm' },
  { key: 'wh', label: 'Kho / DA' }, { key: 'doc', label: 'Chứng từ' }, { key: 'qd', label: 'Chênh SL', right: true, sort: 'qty_diff' },
  { key: 'vd', label: 'Chênh GT', right: true, sort: 'value_diff' }, { key: 'st', label: 'Trạng thái', sort: 'status' },
  { key: 'as', label: 'Phụ trách' }, { key: 'lc', label: 'Kiểm tra gần nhất', sort: 'last_checked_at' },
]

const allChecked = computed(() => props.rows.length > 0 && props.rows.every(r => props.modelValue.includes(r.id)))
const sevClass = (s) => ({ high: 'text-red-600', medium: 'text-yellow-700', low: 'text-blue-600' }[s] ?? 'text-gray-500')

function toggle(id) {
  emit('update:modelValue', props.modelValue.includes(id) ? props.modelValue.filter(x => x !== id) : [...props.modelValue, id])
}
function toggleAll(on) {
  emit('update:modelValue', on ? props.rows.map(r => r.id) : [])
}
</script>
