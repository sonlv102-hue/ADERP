<template>
  <div class="bg-white rounded-xl border border-gray-200 p-4 space-y-3 text-sm">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
      <h2 class="font-semibold text-gray-900">Đối chiếu giá trị tồn kho – kế toán</h2>
      <span class="text-xs text-gray-500">Ngưỡng: số lượng {{ rec.thresholds.qty }} · giá trị {{ rec.thresholds.value }}đ · theo lần audit toàn hệ thống gần nhất</span>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2">
      <div v-for="t in totals" :key="t.label" class="bg-gray-50 rounded-lg p-2">
        <div class="text-xs text-gray-500">{{ t.label }}</div>
        <div class="font-semibold text-gray-900">{{ fmtVnd(t.value) }}</div>
      </div>
    </div>

    <div v-for="c in rec.comparisons" :key="c.key" class="border border-gray-100 rounded-lg">
      <div class="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2 bg-gray-50">
        <span class="font-medium text-gray-900">{{ c.label }}</span>
        <span class="text-gray-600">{{ fmtVnd(c.left) }} − {{ fmtVnd(c.right) }} = <b>{{ fmtVnd(c.diff) }}</b></span>
        <StatusBadge :color="STATUS[c.status][1]">{{ STATUS[c.status][0] }}</StatusBadge>
        <span v-if="c.status !== 'zero'" class="text-xs" :class="Math.abs(c.unexplained) > rec.thresholds.value ? 'text-red-600' : 'text-gray-500'">
          Chưa giải trình: {{ fmtVnd(c.unexplained) }}
        </span>
      </div>
      <DataTableWrapper v-if="c.items.length">
        <table class="min-w-full text-xs">
          <tbody class="divide-y divide-gray-100">
            <tr v-for="(i, k) in c.items" :key="k">
              <td class="px-3 py-1.5 text-gray-800">{{ i.label }}</td>
              <td class="px-3 py-1.5 text-gray-500 whitespace-nowrap">{{ KIND[i.kind] }}</td>
              <td class="px-3 py-1.5 text-right text-gray-500 whitespace-nowrap">{{ i.count !== null ? i.count + ' dòng/chứng từ' : '' }}</td>
              <td class="px-3 py-1.5 text-right font-medium whitespace-nowrap">{{ fmtVnd(i.amount) }}</td>
            </tr>
          </tbody>
        </table>
      </DataTableWrapper>
    </div>
    <p class="text-xs text-gray-500">
      Các khoản trong mỗi mục cộng lại đúng bằng chênh lệch của mục đó; mục 3 = mục 1 + mục 2 (không cộng trùng).
      "Khớp dưới ngưỡng" khác "bằng 0": tổng nhỏ vẫn có thể gồm các khoản lớn bù trừ — xem từng khoản.
      Số dư TK 154 ({{ fmtVnd(rec.totals.gl154_total) }}) đã trừ kết chuyển giá vốn nên không so trực tiếp với WIP hiệu lực ({{ fmtVnd(rec.totals.wip_active_total) }}).
    </p>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import DataTableWrapper from '@/Components/Shared/DataTableWrapper.vue'
import StatusBadge from '@/Components/Shared/StatusBadge.vue'
import { fmtVnd } from '../labels'

const props = defineProps({ rec: Object })
const STATUS = {
  zero: ['Bằng 0', 'green'], below_threshold: ['Khớp dưới ngưỡng', 'blue'], explained: ['Đã giải trình đủ', 'yellow'], unexplained: ['Còn chênh chưa giải trình', 'red'],
}
const KIND = { issue: 'Gắn vấn đề', rounding: 'Làm tròn', unexplained: 'Chưa giải trình', ref: 'Tham chiếu' }
const totals = computed(() => [
  { label: 'Tổng AVCO', value: props.rec.totals.avco },
  { label: 'Lô dự án (FIFO)', value: props.rec.totals.lot },
  { label: 'Sổ kho (movement)', value: props.rec.totals.book },
  ...Object.entries(props.rec.totals.gl156).map(([a, v]) => ({ label: `TK ${a}`, value: v })),
  { label: 'TK 156 tổng', value: props.rec.totals.gl156_total },
])
</script>
