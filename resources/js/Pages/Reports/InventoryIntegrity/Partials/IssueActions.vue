<template>
  <div class="border border-gray-200 rounded-lg p-3 space-y-3">
    <div class="flex flex-wrap gap-2">
      <button v-if="can.audit && issue.source === 'auto'" type="button" class="erp-btn-secondary" :disabled="busy" @click="recheck">Kiểm tra lại vấn đề này</button>
      <button v-for="m in modes" :key="m.key" type="button" :class="mode === m.key ? 'erp-btn-primary' : 'erp-btn-secondary'" @click="mode = mode === m.key ? null : m.key">{{ m.label }}</button>
    </div>
    <p v-if="issue.source === 'auto'" class="text-xs text-gray-500">Không đánh dấu "Đã xử lý" thủ công: sau khi điều chỉnh chứng từ/JE, bấm "Kiểm tra lại" — hệ thống tự đóng khi hết sai lệch và tự mở lại nếu tái phát.</p>

    <div v-if="mode === 'status'" class="grid grid-cols-1 sm:grid-cols-3 gap-2">
      <select v-model="form.status" class="erp-input"><option v-for="s in statuses" :key="s" :value="s">{{ STATUS_LABELS[s] }}</option></select>
      <input v-model="form.note" class="erp-input sm:col-span-2" placeholder="Ghi chú (tuỳ chọn)" />
    </div>
    <div v-if="mode === 'note'"><textarea v-model="form.note" rows="2" class="erp-input w-full" placeholder="Ghi chú nghiệp vụ" /></div>
    <div v-if="mode === 'resolution'" class="grid grid-cols-1 sm:grid-cols-2 gap-2">
      <textarea v-model="form.check_result" rows="2" class="erp-input" placeholder="Kết quả kiểm tra" />
      <textarea v-model="form.cause" rows="2" class="erp-input" placeholder="Nguyên nhân" />
      <input v-model="form.chosen_option" class="erp-input" placeholder="Phương án đã chọn" />
      <input v-model="form.adjustment_ref" class="erp-input" placeholder="Chứng từ / JE điều chỉnh (mã)" />
      <textarea v-model="form.business_note" rows="2" class="erp-input sm:col-span-2" placeholder="Ghi chú nghiệp vụ" />
    </div>
    <div v-if="mode === 'accept'">
      <textarea v-model="form.justification" rows="2" class="erp-input w-full"
        :placeholder="issue.source === 'policy' ? 'Nội dung quyết định chính sách (bắt buộc)' : 'Giải trình vì sao chênh lệch là hợp lệ (bắt buộc, ≥ 10 ký tự)'" />
    </div>
    <p v-for="(e, k) in errors" :key="k" class="text-xs text-red-600">{{ e }}</p>
    <div v-if="mode" class="flex justify-end"><button type="button" class="erp-btn-primary" :disabled="busy" @click="submit">Lưu</button></div>
  </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { STATUS_LABELS } from '../labels'

const props = defineProps({ issue: Object, can: Object, statuses: Array })
const emit = defineEmits(['done'])
const mode = ref(null)
const busy = ref(false)
const errors = ref({})
const form = reactive({ status: props.issue.status, note: '', check_result: '', cause: '', chosen_option: '', adjustment_ref: '', business_note: '', justification: '' })
const closed = computed(() => ['RESOLVED', 'ACCEPTED_DIFFERENCE'].includes(props.issue.status))

const modes = computed(() => [
  props.can.assign && !closed.value && { key: 'status', label: 'Đổi trạng thái' },
  props.can.note && { key: 'note', label: 'Ghi chú' },
  props.can.note && { key: 'resolution', label: 'Kết quả kiểm tra' },
  props.can.accept && !closed.value && { key: 'accept', label: props.issue.source === 'policy' ? 'Ghi nhận quyết định' : 'Xác nhận chênh lệch hợp lệ' },
].filter(Boolean))

const ENDPOINTS = {
  status: () => ['patch', 'status', { status: form.status, note: form.note }],
  note: () => ['post', 'note', { note: form.note }],
  resolution: () => ['post', 'resolution', { check_result: form.check_result, cause: form.cause, chosen_option: form.chosen_option, adjustment_ref: form.adjustment_ref, business_note: form.business_note }],
  accept: () => ['post', 'accept', { justification: form.justification }],
}

function send(method, url, data) {
  busy.value = true
  errors.value = {}
  router[method](url, data, {
    preserveScroll: true, preserveState: true,
    onSuccess: () => { mode.value = null; form.note = ''; form.justification = ''; emit('done') },
    onError: (e) => { errors.value = e },
    onFinish: () => { busy.value = false },
  })
}

function submit() {
  const [method, action, data] = ENDPOINTS[mode.value]()
  send(method, route(`reports.inventory_integrity.${action}`, props.issue.id), data)
}

function recheck() {
  send('post', route('reports.inventory_integrity.run'), { scope: 'issue', value: String(props.issue.id) })
}
</script>
