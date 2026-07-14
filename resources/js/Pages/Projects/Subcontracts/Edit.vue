<template>
  <AppLayout>
    <div class="max-w-4xl space-y-6">
      <div class="flex items-center gap-3">
        <Link :href="route('projects.projects.subcontracts.show', [project.id, subcontract.id])" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </Link>
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Sửa hợp đồng khoán</h1>
          <p class="text-sm text-gray-500 mt-0.5">{{ project.code }} — {{ project.name }}</p>
        </div>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div v-for="ct in contractorTypes" :key="ct.value"
            @click="form.contractor_type = ct.value"
            :class="['p-3 border-2 rounded-lg cursor-pointer text-sm', form.contractor_type === ct.value ? 'border-primary-500 bg-primary-50' : 'border-gray-200']">
            <p class="font-medium text-gray-800">{{ ct.label }}</p>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Số hợp đồng</label>
            <input v-model="form.contract_no" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày ký</label>
            <input v-model="form.contract_date" type="date" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tên nhà thầu / đội nhóm</label>
            <input v-model="form.contractor_name" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Hạng mục</label>
            <select v-model="form.cost_group" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
              <option value="subcontractor">Nhà thầu phụ</option>
              <option value="labor">Nhân công</option>
              <option value="equipment">Máy thi công</option>
              <option value="transport">Vận chuyển</option>
              <option value="other">Khác</option>
            </select>
          </div>
          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Phạm vi công việc</label>
            <textarea v-model="form.scope_of_work" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Giá trị trước VAT</label>
            <input v-model="form.amount_before_vat" type="number" min="0" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" @input="computeVat" />
          </div>
          <div v-if="form.contractor_type === 'company'">
            <label class="block text-sm font-medium text-gray-700 mb-1">VAT %</label>
            <input v-model="form.vat_rate" type="number" min="0" max="100" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" @input="computeVat" />
          </div>
          <div v-if="form.contractor_type === 'company'">
            <label class="block text-sm font-medium text-gray-700 mb-1">Tiền VAT</label>
            <input v-model="form.vat_amount" type="number" min="0" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">% Tạm ứng dự kiến</label>
            <input v-model="form.advance_rate" type="number" min="0" max="100" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">% Giữ lại bảo hành</label>
            <input v-model="form.retention_rate" type="number" min="0" max="100" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày bắt đầu</label>
            <input v-model="form.start_date" type="date" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày kết thúc</label>
            <input v-model="form.end_date" type="date" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
            <textarea v-model="form.notes" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
          </div>
        </div>

        <p v-if="form.errors.general" class="text-red-600 text-xs bg-red-50 px-3 py-2 rounded-lg">{{ form.errors.general }}</p>

        <div class="flex justify-end gap-2 pt-2 border-t border-gray-100">
          <Link :href="route('projects.projects.subcontracts.show', [project.id, subcontract.id])" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</Link>
          <button type="button" @click="submit" :disabled="form.processing"
            class="px-6 py-2 bg-primary-600 hover:bg-primary-700 disabled:bg-gray-300 text-white rounded-lg text-sm font-medium">
            {{ form.processing ? 'Đang xử lý...' : 'Lưu thay đổi' }}
          </button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  project:      Object,
  subcontract:  Object,
  suppliers:    { type: Array, default: () => [] },
});

const contractorTypes = [
  { value: 'company',    label: 'Có hóa đơn (pháp nhân)' },
  { value: 'team',       label: 'Đội nhóm' },
  { value: 'individual', label: 'Cá nhân' },
];

const form = useForm({
  contractor_id:     props.subcontract.contractor_id,
  contractor_name:   props.subcontract.contractor_name,
  contractor_type:   props.subcontract.contractor_type,
  contract_no:       props.subcontract.contract_no,
  contract_date:     props.subcontract.contract_date,
  scope_of_work:     props.subcontract.scope_of_work,
  cost_group:        props.subcontract.cost_group,
  amount_before_vat: props.subcontract.amount_before_vat,
  vat_rate:          props.subcontract.vat_rate,
  vat_amount:        props.subcontract.vat_amount,
  advance_rate:      props.subcontract.advance_rate,
  retention_rate:    props.subcontract.retention_rate,
  start_date:        props.subcontract.start_date,
  end_date:          props.subcontract.end_date,
  notes:             props.subcontract.notes,
});

function computeVat() {
  const amount  = parseFloat(form.amount_before_vat) || 0;
  const vatRate = parseFloat(form.vat_rate) || 0;
  form.vat_amount = form.contractor_type === 'company' && vatRate > 0 ? Math.round(amount * vatRate / 100) : 0;
}

function submit() {
  form.put(route('projects.projects.subcontracts.update', [props.project.id, props.subcontract.id]));
}
</script>
