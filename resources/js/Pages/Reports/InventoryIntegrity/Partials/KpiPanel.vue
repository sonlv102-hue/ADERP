<template>
  <div class="space-y-4">
    <!-- Thông tin lần audit -->
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-sm text-gray-600 flex flex-wrap gap-x-6 gap-y-1">
      <span v-if="lastAllRun">Audit toàn hệ thống gần nhất: <b class="text-gray-900">#{{ lastAllRun.id }} · {{ lastAllRun.finished_at }}</b></span>
      <span v-else class="text-red-600 font-medium">Chưa có lần audit toàn hệ thống — số liệu bên dưới chưa đầy đủ.</span>
      <span v-if="lastRun && lastRun.id !== lastAllRun?.id">Lần gần nhất: #{{ lastRun.id }} ({{ scopeLabel(lastRun) }}) · {{ lastRun.finished_at }}</span>
      <span v-if="lastAllRun">Phiên bản quy tắc: {{ lastAllRun.rules_version }}</span>
      <span v-if="lastAllRun">Dữ liệu legacy (không tính là lỗi): {{ lastAllRun.legacy_rows }} dòng SP×kho</span>
      <span v-if="lastFailed && (!lastRun || lastFailed.id > lastRun.id)" class="text-red-600">
        Run #{{ lastFailed.id }} thất bại — giữ nguyên kết quả trước: {{ lastFailed.error }}
      </span>
    </div>

    <!-- KPI chính -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
      <KpiCard label="Sản phẩm đã kiểm tra" :value="lastAllRun?.products_checked" />
      <KpiCard label="Kho đã kiểm tra" :value="lastAllRun?.warehouses_checked" />
      <KpiCard label="Sản phẩm có sai lệch" :value="kpis.products_with_issues" tone="red" @click="$emit('drill', { status: 'open' })" />
      <KpiCard label="Vấn đề đang mở" :value="kpis.open_issues" tone="red" @click="$emit('drill', { status: 'open' })" />
      <KpiCard v-for="(n, g) in kpis.groups" :key="g" :label="KPI_GROUP_LABELS[g]" :value="n" :tone="n ? 'yellow' : 'green'"
        @click="$emit('drill', { group: g, status: 'open' })" />
      <KpiCard v-for="(n, b) in kpis.buckets" :key="'b' + b" :label="BUCKET_LABELS[b]" :value="n"
        :tone="b === 'resolved' ? 'green' : (b === 'accepted' ? 'gray' : 'blue')"
        @click="$emit('drill', { bucket: b, status: 'all' })" />
    </div>

    <!-- Chênh lệch giá trị theo cơ sở đối chiếu -->
    <DataTableWrapper class="bg-white rounded-xl border border-gray-200">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="text-left px-4 py-2 font-semibold text-gray-600">Cơ sở đối chiếu giá trị (vấn đề đang mở)</th>
            <th class="text-right px-4 py-2 font-semibold text-gray-600">Số vấn đề</th>
            <th class="text-right px-4 py-2 font-semibold text-gray-600">Tăng</th>
            <th class="text-right px-4 py-2 font-semibold text-gray-600">Giảm</th>
            <th class="text-right px-4 py-2 font-semibold text-gray-600">Ròng</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="v in kpis.values" :key="v.basis">
            <td class="px-4 py-2 text-gray-900">{{ v.label }}</td>
            <td class="px-4 py-2 text-right">{{ v.count }}</td>
            <td class="px-4 py-2 text-right">{{ fmtVnd(v.increase) }}</td>
            <td class="px-4 py-2 text-right text-red-600">{{ fmtVnd(v.decrease) }}</td>
            <td class="px-4 py-2 text-right font-medium">{{ fmtVnd(v.net) }}</td>
          </tr>
          <tr v-if="!kpis.values.length"><td colspan="5" class="px-4 py-4 text-center text-gray-400">Không có chênh lệch giá trị đang mở</td></tr>
        </tbody>
      </table>
      <p class="px-4 py-2 text-xs text-gray-500 border-t border-gray-100">
        Mỗi cơ sở đối chiếu tính riêng; không cộng giữa các cơ sở và không phải "tổng thiệt hại".
      </p>
    </DataTableWrapper>
  </div>
</template>

<script setup>
import { h } from 'vue'
import DataTableWrapper from '@/Components/Shared/DataTableWrapper.vue'
import { KPI_GROUP_LABELS, BUCKET_LABELS, SCOPE_LABELS, fmtVnd } from '../labels'

defineProps({ kpis: Object, lastRun: Object, lastAllRun: Object, lastFailed: Object })
defineEmits(['drill'])

const scopeLabel = (r) => SCOPE_LABELS[r.scope_type] + (r.scope_value ? `: ${r.scope_value}` : '')

const TONES = { red: 'text-red-600', yellow: 'text-yellow-600', green: 'text-green-600', blue: 'text-primary-700', gray: 'text-gray-700' }
const KpiCard = (props, { emit }) => h('button', {
  type: 'button', title: 'Xem chi tiết', onClick: () => emit('click'),
  class: 'bg-white rounded-xl border border-gray-200 p-3 text-left hover:border-primary-300 hover:bg-primary-50/40 transition-colors',
}, [
  h('div', { class: 'text-xs text-gray-500 leading-tight' }, props.label),
  h('div', { class: `text-xl font-bold mt-1 ${TONES[props.tone] ?? 'text-gray-900'}` }, props.value ?? '—'),
])
KpiCard.props = ['label', 'value', 'tone']
KpiCard.emits = ['click']
</script>
