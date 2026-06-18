<template>
  <AppLayout>
    <div class="max-w-4xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('accounting.small-tools.issues.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">Tạo phiếu xuất dùng CCDC</h1>
      </div>

      <form @submit.prevent="submit" class="space-y-5">
        <!-- Header -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="erp-label">Mã phiếu</label>
              <input :value="nextCode" disabled class="erp-input bg-gray-50 text-gray-500" />
            </div>
            <div>
              <label class="erp-label">Ngày xuất dùng <span class="text-red-500">*</span></label>
              <input v-model="form.issue_date" type="date" class="erp-input"
                :class="{ 'border-red-500': form.errors.issue_date }" />
            </div>
            <div>
              <label class="erp-label">Bộ phận</label>
              <input v-model="form.department" type="text" class="erp-input" placeholder="Kế toán, Kỹ thuật..." />
            </div>
            <div>
              <label class="erp-label">Nhân viên nhận</label>
              <SearchableSelect v-model="form.responsible_employee_id" :options="employeeOptions" placeholder="-- Chọn nhân viên --" />
            </div>
            <div>
              <label class="erp-label">Dự án (nếu dùng cho dự án)</label>
              <SearchableSelect v-model="form.project_id" :options="projectOptions" placeholder="-- Chọn dự án --" />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="erp-label">Ghi nhận chi phí <span class="text-red-500">*</span></label>
              <select v-model="form.recognition_method" class="erp-input">
                <option value="immediate">Một lần (Nợ TK chi phí / Có 1531)</option>
                <option value="allocation">Phân bổ nhiều kỳ (Nợ 2422 / Có 1531)</option>
              </select>
            </div>
            <div v-if="form.recognition_method === 'allocation'">
              <label class="erp-label">Số kỳ phân bổ</label>
              <input v-model.number="form.allocation_periods" type="number" min="1" class="erp-input" />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="erp-label">TK chi phí <span class="text-red-500">*</span></label>
              <select v-model="form.expense_account_code" class="erp-input">
                <option value="6422">6422 — Chi phí quản lý doanh nghiệp</option>
                <option value="6421">6421 — Chi phí bán hàng</option>
                <option value="1541">1541 — Chi phí sản xuất/dự án (154)</option>
              </select>
            </div>
            <div v-if="form.recognition_method === 'allocation'">
              <label class="erp-label">Ngày bắt đầu phân bổ</label>
              <input v-model="form.allocation_start_date" type="date" class="erp-input" />
            </div>
          </div>
          <div>
            <label class="erp-label">Ghi chú</label>
            <input v-model="form.notes" type="text" class="erp-input" />
          </div>
        </div>

        <!-- CCDC chọn -->
        <div class="bg-white rounded-xl border border-gray-200 p-6">
          <h2 class="text-base font-semibold text-gray-800 mb-4">Chọn CCDC xuất dùng</h2>

          <div v-if="!selectedTools.length" class="text-gray-400 text-sm mb-3">Chưa chọn CCDC nào.</div>

          <div v-for="(item, idx) in form.items" :key="idx" class="flex items-center gap-3 mb-3 p-3 bg-gray-50 rounded-lg">
            <div class="flex-1">
              <SearchableSelect v-model="item.small_tool_id" :options="availableOptions" placeholder="-- Chọn CCDC --"
                @change="(opt) => onToolSelected(idx, opt)" />
            </div>
            <div class="w-40">
              <input v-model.number="item.amount" type="number" min="0" step="1" class="erp-input"
                placeholder="Giá trị xuất dùng" />
            </div>
            <button type="button" @click="removeItem(idx)" class="text-red-500 hover:text-red-700 text-xs">Xóa</button>
          </div>

          <button type="button" @click="addItem" class="text-primary-600 text-sm hover:text-primary-800">+ Thêm CCDC</button>

          <div class="mt-4 text-sm text-gray-600">
            Tổng giá trị xuất: <span class="font-mono font-bold">{{ formatVnd(totalAmount) }}</span>
          </div>
        </div>

        <!-- JE preview -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 text-xs text-blue-800 space-y-1">
          <p class="font-semibold">Bút toán sau khi xác nhận (mỗi CCDC riêng):</p>
          <p v-if="form.recognition_method === 'allocation'">Nợ 2422 — CCDC chờ phân bổ</p>
          <p v-else>Nợ {{ form.expense_account_code }} — Chi phí CCDC</p>
          <p>Có 1531 — Xuất kho CCDC</p>
        </div>

        <div class="flex items-center gap-2">
          <input id="auto_confirm" type="checkbox" v-model="form.auto_confirm" class="accent-primary-600" />
          <label for="auto_confirm" class="text-sm">Xác nhận và tạo bút toán ngay</label>
        </div>

        <div class="flex gap-3">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : 'Tạo phiếu xuất dùng' }}
          </button>
          <Link :href="route('accounting.small-tools.issues.index')"
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
import { useCurrency } from '@/composables/useCurrency';

const { formatVnd } = useCurrency();
const props = defineProps({ nextCode: String, inStockTools: Array, employees: Array, projects: Array });

const form = useForm({
  issue_date:              new Date().toISOString().slice(0, 10),
  department:              '',
  responsible_employee_id: null,
  project_id:              null,
  recognition_method:      'immediate',
  allocation_periods:      null,
  allocation_start_date:   '',
  expense_account_code:    '6422',
  notes:                   '',
  auto_confirm:            false,
  items:                   [{ small_tool_id: null, amount: 0 }],
});

const selectedIds      = computed(() => form.items.map(i => i.small_tool_id).filter(Boolean));
const availableOptions = computed(() => (props.inStockTools ?? []).map(t => ({
  value: t.id, code: t.code, label: t.name,
  meta: `${t.quantity} ${t.unit}`,
})));
const selectedTools    = computed(() => form.items.filter(i => i.small_tool_id));
const totalAmount      = computed(() => form.items.reduce((s, i) => s + (i.amount || 0), 0));
const employeeOptions  = computed(() => (props.employees ?? []).map(e => ({ value: e.id, code: e.code, label: e.name })));
const projectOptions   = computed(() => (props.projects  ?? []).map(p => ({ value: p.id, code: p.code, label: p.name })));

function addItem()       { form.items.push({ small_tool_id: null, amount: 0 }); }
function removeItem(idx) { form.items.splice(idx, 1); }
function onToolSelected(idx, opt) {
  if (!opt) return;
  const tool = props.inStockTools?.find(t => t.id === opt.value);
  if (tool) form.items[idx].amount = Number(tool.original_cost) || 0;
}

function submit() {
  form.post(route('accounting.small-tools.issues.store'));
}
</script>
