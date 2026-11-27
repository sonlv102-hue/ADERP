<template>
  <Modal :show="!!issueId" max-width="5xl" @close="$emit('close')">
    <template #title>Chi tiết vấn đề {{ d?.issue.code }} — {{ RULE_LABELS[d?.issue.rule_code] ?? '' }}</template>
    <div v-if="loading" class="py-10 text-center text-gray-400">Đang tải...</div>
    <div v-else-if="d" class="space-y-4 text-sm">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="space-y-1">
          <div><StatusBadge :color="STATUS_COLORS[i.status]">{{ STATUS_LABELS[i.status] }}</StatusBadge>
            <span class="ml-2 text-gray-500">{{ CATEGORY_LABELS[i.category] }} · {{ SEVERITY[i.severity]?.[0] }}<span v-if="i.is_legacy"> · legacy</span></span></div>
          <div v-if="i.product_code"><b>{{ i.product_code }}</b> — {{ i.product_name }} (ID {{ i.product_id }})</div>
          <div>Kho: {{ i.warehouse ?? '—' }} <span v-if="i.project">· Dự án {{ i.project }}</span></div>
          <div>Chứng từ: <a v-if="i.document_link" :href="i.document_link" target="_blank" class="text-primary-600 hover:underline">{{ i.document_ref }}</a>
            <span v-else>{{ i.document_ref ?? i.document_codes ?? '—' }}</span></div>
          <div class="text-gray-500">Phát hiện {{ i.detected_at }} · kiểm tra gần nhất {{ i.last_checked_at }}</div>
        </div>
        <div class="grid grid-cols-2 gap-x-4 gap-y-1 bg-gray-50 rounded-lg p-3">
          <span class="text-gray-500">Tồn sổ kho</span><span class="text-right">{{ fmtNum(i.qty_book) }}</span>
          <span class="text-gray-500">Tồn AVCO</span><span class="text-right">{{ fmtNum(i.qty_avco) }}</span>
          <span class="text-gray-500">Tồn lô dự án</span><span class="text-right">{{ fmtNum(i.qty_lot) }}</span>
          <span class="text-gray-500">Tồn khả dụng</span><span class="text-right">{{ fmtNum(i.qty_available) }}</span>
          <span class="text-gray-500">Chênh SL</span><span class="text-right font-medium">{{ fmtNum(i.qty_diff) }}</span>
          <span class="text-gray-500">GT sổ kho</span><span class="text-right">{{ fmtVnd(i.value_book) }}</span>
          <span class="text-gray-500">GT đối chiếu</span><span class="text-right">{{ fmtVnd(i.value_compare) }}</span>
          <span class="text-gray-500">Chênh GT</span><span class="text-right font-medium">{{ fmtVnd(i.value_diff) }}</span>
          <template v-if="d.trace?.expected?.expected_avco_qty !== undefined">
            <span class="text-gray-500">AVCO dự kiến đúng</span>
            <span class="text-right">{{ fmtNum(d.trace.expected.expected_avco_qty) }} / {{ fmtVnd(d.trace.expected.expected_avco_value) }}</span>
          </template>
        </div>
      </div>

      <div class="space-y-1">
        <p><b>Điều kiện:</b> {{ i.rule.condition }}. <b>Công thức:</b> <span class="font-mono text-xs">{{ i.rule.formula }}</span></p>
        <p class="text-xs text-gray-500">Nguồn: {{ i.rule.sources }} · Phạm vi: {{ i.rule.scope }}</p>
        <p><b>Nguyên nhân / bằng chứng:</b> {{ i.cause }}</p>
        <p v-if="i.evidence?.merged_findings?.length"><b>Sai lệch liên quan (gộp cùng nguyên nhân, không lập vấn đề riêng):</b>
          <span v-for="(m, k) in i.evidence.merged_findings" :key="k" class="block text-gray-700">· {{ RULE_LABELS[m[0]] ?? m[0] }}: {{ m[1] }}</span></p>
        <p><b>Phương án:</b> {{ i.proposal }}</p>
        <p v-if="i.request_note"><b>Yêu cầu:</b> {{ i.request_note }}</p>
      </div>

      <CaseInfo v-if="i.case_info || i.evidence?.options?.length" :case-info="i.case_info" :options="i.evidence?.options" :resolution="i.resolution" />

      <TracePanel v-if="d.trace" :trace="d.trace" />

      <IssueActions :issue="i" :can="can" :statuses="manualStatuses" @done="reload" />

      <div>
        <h3 class="font-semibold text-gray-900 mb-1">Lịch sử xử lý</h3>
        <ul class="divide-y divide-gray-100 border border-gray-100 rounded-lg max-h-48 overflow-y-auto">
          <li v-for="(l, k) in d.logs" :key="k" class="px-3 py-1.5 text-xs">
            <span class="text-gray-500">{{ l.at }} · {{ l.user }}</span> — <b>{{ l.action }}</b>
            <span v-if="l.to"> {{ l.from ? STATUS_LABELS[l.from] + ' → ' : '' }}{{ STATUS_LABELS[l.to] }}</span>
            <span v-if="l.run_id" class="text-gray-400"> (run #{{ l.run_id }})</span>
            <div v-if="l.note" class="text-gray-700">{{ l.note }}</div>
            <div v-if="l.payload && l.action === 'resolution'" class="text-gray-600">{{ Object.entries(l.payload).map(([k, v]) => `${k}: ${v}`).join(' · ') }}</div>
          </li>
        </ul>
      </div>
    </div>
    <template #footer>
      <button type="button" class="erp-btn-secondary" @click="$emit('close')">Đóng</button>
    </template>
  </Modal>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import axios from 'axios'
import Modal from '@/Components/Shared/Modal.vue'
import StatusBadge from '@/Components/Shared/StatusBadge.vue'
import TracePanel from './TracePanel.vue'
import IssueActions from './IssueActions.vue'
import CaseInfo from './CaseInfo.vue'
import { RULE_LABELS, STATUS_LABELS, STATUS_COLORS, CATEGORY_LABELS, SEVERITY, fmtNum, fmtVnd } from '../labels'

const props = defineProps({ issueId: Number, can: Object, manualStatuses: Array })
defineEmits(['close'])
const d = ref(null)
const loading = ref(false)
const i = computed(() => d.value?.issue)

async function reload() {
  if (!props.issueId) return
  loading.value = !d.value
  try {
    d.value = (await axios.get(route('reports.inventory_integrity.show', props.issueId))).data
  } finally {
    loading.value = false
  }
}
watch(() => props.issueId, () => { d.value = null; reload() }, { immediate: true })
</script>
