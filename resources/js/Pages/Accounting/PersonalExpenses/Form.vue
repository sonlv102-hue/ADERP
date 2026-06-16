<template>
  <AppLayout>
    <div class="max-w-3xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('accounting.personal-expenses.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">Tạo phiếu ghi nhận chi hộ</h1>
      </div>

      <!-- JE preview note -->
      <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 text-xs text-blue-800 mb-5 space-y-1">
        <p class="font-semibold">Bút toán khi ghi sổ:</p>
        <p>Nợ TK chi phí/tài sản (các dòng) + Nợ 1331 (VAT nếu có)</p>
        <p>Có 3388 — Phải trả, phải nộp khác (người chi hộ: {{ personDisplay || '...' }})</p>
      </div>

      <form @submit.prevent="submit" class="space-y-5">
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <h3 class="font-semibold text-gray-800 text-sm">Thông tin người chi hộ</h3>

          <div class="flex gap-4 flex-wrap">
            <label v-for="t in personTypes" :key="t.value" class="flex items-center gap-2 cursor-pointer">
              <input type="radio" v-model="form.person_type" :value="t.value" class="accent-primary-600" />
              <span class="text-sm">{{ t.label }}</span>
            </label>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">
                {{ form.person_type === 'employee' ? 'Nhân viên' : form.person_type === 'shareholder' ? 'Thành viên/Cổ đông' : 'Họ tên' }}
                <span class="text-red-500">*</span>
              </label>
              <select v-if="form.person_type === 'employee'" v-model="form.employee_id"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500">
                <option :value="null">-- Chọn nhân viên --</option>
                <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
              </select>
              <select v-else-if="form.person_type === 'shareholder'" v-model="form.shareholder_id"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500">
                <option :value="null">-- Chọn thành viên --</option>
                <option v-for="s in shareholders" :key="s.id" :value="s.id">{{ s.name }}</option>
              </select>
              <input v-else v-model="form.person_name" type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ngày chi <span class="text-red-500">*</span></label>
              <input v-model="form.expense_date" type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500"
                :class="{ 'border-red-500': form.errors.expense_date }" />
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nội dung chứng từ <span class="text-red-500">*</span></label>
            <input v-model="form.description" type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500"
              :class="{ 'border-red-500': form.errors.description }" />
            <p v-if="form.errors.description" class="mt-1 text-xs text-red-600">{{ form.errors.description }}</p>
          </div>
        </div>

        <!-- Lines -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="flex items-center justify-between px-4 py-3 bg-gray-50 border-b border-gray-200">
            <h3 class="text-sm font-semibold text-gray-700">Chi tiết khoản chi hộ</h3>
            <button type="button" @click="addLine" class="text-sm text-primary-600 hover:text-primary-700 font-medium">+ Thêm dòng</button>
          </div>

          <div v-if="form.errors.lines" class="px-4 py-2 text-xs text-red-600 bg-red-50">{{ form.errors.lines }}</div>

          <table class="w-full text-sm">
            <thead class="border-b border-gray-100">
              <tr>
                <th class="text-left px-4 py-2 text-xs font-medium text-gray-500 w-32">TK chi phí</th>
                <th class="text-left px-4 py-2 text-xs font-medium text-gray-500">Diễn giải</th>
                <th class="text-right px-4 py-2 text-xs font-medium text-gray-500 w-36">Số tiền (incl VAT)</th>
                <th class="text-right px-4 py-2 text-xs font-medium text-gray-500 w-24">VAT %</th>
                <th class="text-right px-4 py-2 text-xs font-medium text-gray-500 w-28">Tiền VAT</th>
                <th class="w-8"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="(line, i) in form.lines" :key="i">
                <td class="px-3 py-2">
                  <input v-model="line.expense_account" type="text" list="account-list"
                    placeholder="641, 6422..."
                    class="w-full px-2 py-1.5 border border-gray-300 rounded text-xs font-mono outline-none focus:ring-1 focus:ring-primary-500" />
                </td>
                <td class="px-3 py-2">
                  <input v-model="line.description" type="text"
                    class="w-full px-2 py-1.5 border border-gray-300 rounded text-xs outline-none focus:ring-1 focus:ring-primary-500" />
                </td>
                <td class="px-3 py-2">
                  <input v-model.number="line.amount" type="number" min="0" step="1"
                    class="w-full px-2 py-1.5 border border-gray-300 rounded text-xs text-right outline-none focus:ring-1 focus:ring-primary-500" />
                </td>
                <td class="px-3 py-2">
                  <select v-model.number="line.vat_rate"
                    class="w-full px-2 py-1.5 border border-gray-300 rounded text-xs outline-none focus:ring-1 focus:ring-primary-500">
                    <option :value="0">0%</option>
                    <option :value="5">5%</option>
                    <option :value="8">8%</option>
                    <option :value="10">10%</option>
                  </select>
                </td>
                <td class="px-3 py-2 text-right text-xs text-gray-600 font-medium">
                  {{ formatVnd(calcVat(line)) }}
                </td>
                <td class="px-3 py-2">
                  <button type="button" @click="removeLine(i)" class="text-red-400 hover:text-red-600 text-xs">✕</button>
                </td>
              </tr>
            </tbody>
            <tfoot class="border-t border-gray-200 bg-gray-50">
              <tr>
                <td colspan="2" class="px-4 py-2 text-xs font-semibold text-gray-700 text-right">Tổng cộng:</td>
                <td class="px-3 py-2 text-right text-sm font-bold text-gray-900">{{ formatVnd(totalAmount) }}</td>
                <td></td>
                <td class="px-3 py-2 text-right text-xs font-semibold text-gray-700">{{ formatVnd(totalVat) }}</td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>

        <div class="flex gap-3">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : 'Tạo phiếu' }}
          </button>
          <Link :href="route('accounting.personal-expenses.index')"
            class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</Link>
        </div>
      </form>

      <datalist id="account-list">
        <option v-for="a in expenseAccounts" :key="a.code" :value="a.code">{{ a.name }}</option>
      </datalist>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ report: Object, employees: Array, shareholders: Array, expenseAccounts: Array });
const { formatVnd } = useCurrency();

const personTypes = [
  { value: 'employee', label: 'Nhân viên' },
  { value: 'shareholder', label: 'Thành viên/Cổ đông' },
  { value: 'other', label: 'Khác' },
];

const form = useForm({
  person_type:    'employee',
  employee_id:    null,
  shareholder_id: null,
  person_name:    '',
  expense_date:   new Date().toISOString().slice(0, 10),
  description:    '',
  lines:          [{ expense_account: '', description: '', amount: null, vat_rate: 0 }],
});

const personDisplay = computed(() => {
  if (form.person_type === 'employee') return props.employees?.find(e => e.id === form.employee_id)?.name;
  if (form.person_type === 'shareholder') return props.shareholders?.find(s => s.id === form.shareholder_id)?.name;
  return form.person_name;
});

function calcVat(line) {
  if (!line.amount || !line.vat_rate) return 0;
  return Math.round(line.amount * line.vat_rate / (100 + line.vat_rate));
}

const totalAmount = computed(() => form.lines.reduce((s, l) => s + (l.amount || 0), 0));
const totalVat    = computed(() => form.lines.reduce((s, l) => s + calcVat(l), 0));

function addLine() {
  form.lines.push({ expense_account: '', description: '', amount: null, vat_rate: 0 });
}
function removeLine(i) {
  if (form.lines.length > 1) form.lines.splice(i, 1);
}
function submit() {
  form.post(route('accounting.personal-expenses.store'));
}
</script>
