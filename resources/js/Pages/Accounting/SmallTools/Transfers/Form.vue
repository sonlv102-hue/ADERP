<template>
  <AppLayout>
    <div class="max-w-xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('accounting.small-tools.show', tool.id)" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">Điều chuyển CCDC</h1>
      </div>

      <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-sm text-blue-800 mb-5">
        <span class="font-semibold">{{ tool.code }}</span> — {{ tool.name }}
        <span class="ml-2 text-blue-600">{{ { in_stock: 'Trong kho', in_use: 'Đang sử dụng', allocating: 'Đang phân bổ' }[tool.status] }}</span>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <div>
          <label class="erp-label">Ngày điều chuyển <span class="text-red-500">*</span></label>
          <input v-model="form.transfer_date" type="date" class="erp-input"
            :class="{ 'border-red-500': form.errors.transfer_date }" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="erp-label text-gray-400">Từ bộ phận</label>
            <input :value="tool.department || '—'" disabled class="erp-input bg-gray-50 text-gray-400" />
          </div>
          <div>
            <label class="erp-label">Đến bộ phận</label>
            <input v-model="form.to_department" type="text" class="erp-input" placeholder="Nhập bộ phận mới..." />
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="erp-label text-gray-400">Nhân viên hiện tại</label>
            <input :value="tool.responsible_employee_id ? '' : '—'" disabled class="erp-input bg-gray-50 text-gray-400" />
          </div>
          <div>
            <label class="erp-label">Nhân viên nhận mới</label>
            <SearchableSelect v-model="form.to_employee_id" :options="employeeOptions" placeholder="-- Chọn nhân viên --" />
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="erp-label text-gray-400">Dự án hiện tại</label>
            <input :value="'—'" disabled class="erp-input bg-gray-50 text-gray-400" />
          </div>
          <div>
            <label class="erp-label">Dự án mới</label>
            <SearchableSelect v-model="form.to_project_id" :options="projectOptions" placeholder="-- Chọn dự án --" />
          </div>
        </div>

        <div v-if="tool.status === 'allocating'">
          <label class="erp-label">TK chi phí mới (nếu thay đổi)</label>
          <select v-model="form.new_expense_account_code" class="erp-input">
            <option value="">Giữ nguyên ({{ tool.expense_account_code }})</option>
            <option value="6422">6422 — Quản lý DN</option>
            <option value="6421">6421 — Bán hàng</option>
            <option value="1541">154 — Dự án</option>
          </select>
          <p v-if="form.new_expense_account_code" class="mt-1 text-xs text-orange-600">
            ⚠ Các kỳ phân bổ chưa post sẽ được cập nhật sang TK mới.
          </p>
        </div>

        <div>
          <label class="erp-label">Lý do điều chuyển</label>
          <textarea v-model="form.reason" rows="2" class="erp-input" />
        </div>

        <div class="flex gap-3 pt-2">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : 'Xác nhận điều chuyển' }}
          </button>
          <Link :href="route('accounting.small-tools.show', tool.id)"
            class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import SearchableSelect from '@/Components/Shared/SearchableSelect.vue';

const props = defineProps({ tool: Object, employees: Array, projects: Array, warehouses: Array });

const form = useForm({
  transfer_date:           new Date().toISOString().slice(0, 10),
  to_department:           '',
  to_employee_id:          null,
  to_project_id:           null,
  to_warehouse_id:         null,
  new_expense_account_code: '',
  reason:                  '',
  notes:                   '',
});

const employeeOptions = computed(() => (props.employees ?? []).map(e => ({ value: e.id, code: e.code, label: e.name })));
const projectOptions  = computed(() => (props.projects  ?? []).map(p => ({ value: p.id, code: p.code, label: p.name })));

function submit() {
  form.post(route('accounting.small-tools.transfers.store', props.tool.id));
}
</script>
