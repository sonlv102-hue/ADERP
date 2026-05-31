<template>
  <AppLayout>
    <div class="max-w-2xl mx-auto space-y-6">
      <div class="flex items-center gap-3">
        <Link :href="route('accounting.prepaid-expenses.index')" class="text-gray-400 hover:text-gray-600">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">Tạo chi phí trả trước</h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Diễn giải *</label>
          <input v-model="form.description" required placeholder="Ví dụ: Tiền thuê văn phòng 12 tháng..."
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          <p v-if="form.errors.description" class="text-red-600 text-xs mt-1">{{ form.errors.description }}</p>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nhà cung cấp</label>
            <select v-model="form.supplier_id"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
              <option :value="null">— Không chọn —</option>
              <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">TK chi phí trả trước *</label>
            <select v-model="form.account_code" required
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
              <option v-for="opt in accountOptions" :key="opt.code" :value="opt.code">{{ opt.label }}</option>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">TK phân bổ vào chi phí *</label>
            <select v-model="form.expense_account" required
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
              <option v-for="opt in expenseOptions" :key="opt.code" :value="opt.code">{{ opt.label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">TK đối ứng (nguồn tiền) *</label>
            <select v-model="form.credit_account" required
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
              <option v-for="opt in creditOptions" :key="opt.code" :value="opt.code">{{ opt.label }}</option>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tổng tiền (VND) *</label>
            <input v-model.number="form.total_amount" type="number" min="1" step="any" required
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày bắt đầu *</label>
            <input v-model="form.start_date" type="date" required
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Số tháng phân bổ *</label>
            <input v-model.number="form.months" type="number" min="1" max="120" required
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
        </div>

        <!-- Preview -->
        <div v-if="form.total_amount > 0 && form.months > 0"
          class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 text-sm text-blue-800">
          Mỗi tháng phân bổ: <strong>{{ fmt(Math.ceil(form.total_amount / form.months)) }} ₫</strong>
          / tháng trong {{ form.months }} tháng
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
          <textarea v-model="form.notes" rows="2"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
        </div>

        <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
          <Link :href="route('accounting.prepaid-expenses.index')"
            class="px-4 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Hủy</Link>
          <button type="submit" :disabled="form.processing"
            class="px-5 py-2 text-sm bg-primary-600 text-white rounded-lg hover:bg-primary-700 disabled:opacity-50">
            Tạo chi phí trả trước
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  suppliers:      Array,
  accountOptions: Array,
  expenseOptions: Array,
  creditOptions:  Array,
});

const { formatVnd: fmt } = useCurrency();

const today = new Date().toISOString().slice(0, 10);
const form = useForm({
  description:     '',
  supplier_id:     null,
  account_code:    '242',
  expense_account: '642',
  credit_account:  '331',
  total_amount:    0,
  start_date:      today,
  months:          12,
  notes:           '',
});

function submit() {
  form.post(route('accounting.prepaid-expenses.store'));
}
</script>
