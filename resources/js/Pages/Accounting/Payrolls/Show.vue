<template>
  <AppLayout>
    <div class="max-w-7xl space-y-6">
      <!-- Breadcrumb + confirm -->
      <div class="flex items-center justify-between">
        <Link :href="route('accounting.payrolls.index')" class="text-gray-400 hover:text-gray-600 text-sm">
          &larr; Danh sách bảng lương
        </Link>
        <div class="flex items-center gap-3">
          <button v-if="payroll.status === 'draft'" @click="confirmPayroll"
            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-semibold shadow-sm">
            Xác nhận bảng lương
          </button>
          <span v-else :class="{
            'bg-blue-100 text-blue-800': payroll.status === 'confirmed',
            'bg-green-100 text-green-800': payroll.status === 'paid',
          }" class="px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider">
            {{ payroll.status_label }}
          </span>
        </div>
      </div>

      <!-- Flash -->
      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm">{{ $page.props.flash.success }}</div>
      <div v-if="$page.props.flash?.error"   class="bg-red-50   border border-red-200   text-red-800   rounded-xl px-4 py-3 text-sm">{{ $page.props.flash.error }}</div>

      <!-- Summary Grid -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-xs text-gray-500 mb-1">Tổng gross</p>
          <p class="text-lg font-bold text-gray-800 font-mono">{{ fv(payroll.total_gross) }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-xs text-gray-500 mb-1">BHXH/BHYT/BHTN nhân viên</p>
          <p class="text-lg font-bold text-orange-600 font-mono">-{{ fv(payroll.total_insurance_employee) }}</p>
          <p class="text-xs text-gray-400 mt-1">Employer: {{ fv(payroll.total_insurance_employer) }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-xs text-gray-500 mb-1">Thuế TNCN (PIT)</p>
          <p class="text-lg font-bold text-red-600 font-mono">-{{ fv(payroll.total_pit) }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 border-l-4 border-primary-500">
          <p class="text-xs text-gray-500 mb-1">Tổng thực lĩnh</p>
          <p class="text-xl font-extrabold text-primary-600 font-mono">{{ fv(payroll.total_net_salary) }}</p>
          <p class="text-xs text-gray-400 mt-1">{{ payroll.period }} · {{ payroll.code }}</p>
        </div>
      </div>

      <!-- Employee detail table -->
      <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b bg-gray-50 flex justify-between items-center">
          <h3 class="font-bold text-gray-800">Chi tiết lương nhân viên</h3>
          <span class="text-xs text-gray-500">{{ items.length }} nhân viên</span>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm whitespace-nowrap">
            <thead class="bg-gray-50 text-gray-600 text-xs uppercase">
              <tr>
                <th class="px-4 py-3 text-left">Nhân viên</th>
                <th class="px-4 py-3 text-right">Gross</th>
                <th class="px-4 py-3 text-right">BHXH NV</th>
                <th class="px-4 py-3 text-right">BHYT NV</th>
                <th class="px-4 py-3 text-right">BHTN NV</th>
                <th class="px-4 py-3 text-right">PIT</th>
                <th class="px-4 py-3 text-right font-bold">Thực lĩnh</th>
                <th class="px-4 py-3 text-center">TT</th>
                <th class="px-4 py-3"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="item in items" :key="item.id" class="hover:bg-gray-50">
                <td class="px-4 py-3">
                  <p class="font-medium text-gray-900">{{ item.employee_name }}</p>
                  <p class="text-xs text-gray-400">{{ item.pit_tax_code || 'Chưa có MST' }} · {{ item.dependents_count }} NPT</p>
                </td>
                <td class="px-4 py-3 text-right font-mono text-gray-700">{{ fv(item.gross_salary) }}</td>
                <td class="px-4 py-3 text-right font-mono text-orange-600">{{ fv(item.bhxh_employee) }}</td>
                <td class="px-4 py-3 text-right font-mono text-orange-600">{{ fv(item.bhyt_employee) }}</td>
                <td class="px-4 py-3 text-right font-mono text-orange-600">{{ fv(item.bhtn_employee) }}</td>
                <td class="px-4 py-3 text-right font-mono text-red-600">{{ fv(item.pit) }}</td>
                <td class="px-4 py-3 text-right font-bold font-mono text-gray-900">{{ fv(item.net_salary) }}</td>
                <td class="px-4 py-3 text-center">
                  <span :class="{
                    'badge-yellow': item.status === 'pending',
                    'badge-green':  item.status === 'paid',
                  }">{{ item.status_label }}</span>
                  <p v-if="item.paid_at" class="text-[10px] text-gray-400 mt-1">{{ item.paid_at }}</p>
                </td>
                <td class="px-4 py-3 text-right">
                  <div class="flex items-center justify-end gap-2">
                    <button v-if="payroll.status === 'draft'" @click="openEditModal(item)"
                      class="text-primary-600 hover:underline text-xs font-semibold">Sửa</button>
                    <button v-if="payroll.status === 'confirmed' && item.status === 'pending'"
                      @click="openPayModal(item)"
                      class="bg-green-600 hover:bg-green-700 text-white px-2 py-1 rounded text-xs font-bold">
                      Chi lương
                    </button>
                    <Link v-if="item.cash_voucher"
                      :href="route('accounting.cash-vouchers.show', item.cash_voucher.id)"
                      class="text-green-600 hover:underline text-xs font-mono font-bold">
                      {{ item.cash_voucher.code }}
                    </Link>
                  </div>
                </td>
              </tr>
            </tbody>
            <!-- Totals row -->
            <tfoot class="bg-gray-50 font-semibold border-t-2 border-gray-300 text-xs">
              <tr>
                <td class="px-4 py-2 text-gray-700">Cộng</td>
                <td class="px-4 py-2 text-right font-mono">{{ fv(payroll.total_gross) }}</td>
                <td class="px-4 py-2 text-right font-mono text-orange-600">{{ fv(sumItems('bhxh_employee')) }}</td>
                <td class="px-4 py-2 text-right font-mono text-orange-600">{{ fv(sumItems('bhyt_employee')) }}</td>
                <td class="px-4 py-2 text-right font-mono text-orange-600">{{ fv(sumItems('bhtn_employee')) }}</td>
                <td class="px-4 py-2 text-right font-mono text-red-600">{{ fv(payroll.total_pit) }}</td>
                <td class="px-4 py-2 text-right font-mono text-primary-700">{{ fv(payroll.total_net_salary) }}</td>
                <td colspan="2"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- Employer insurance summary -->
      <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm">
        <p class="font-semibold text-amber-800 mb-2">Chi phí bảo hiểm do công ty chịu (không trừ vào lương NV)</p>
        <div class="grid grid-cols-3 gap-4 text-center">
          <div>
            <p class="text-xs text-amber-600">BHXH (17.5%)</p>
            <p class="font-bold text-amber-900 font-mono">{{ fv(sumItems('bhxh_employer')) }}</p>
          </div>
          <div>
            <p class="text-xs text-amber-600">BHYT (3%)</p>
            <p class="font-bold text-amber-900 font-mono">{{ fv(sumItems('bhyt_employer')) }}</p>
          </div>
          <div>
            <p class="text-xs text-amber-600">BHTN (1%)</p>
            <p class="font-bold text-amber-900 font-mono">{{ fv(sumItems('bhtn_employer')) }}</p>
          </div>
        </div>
        <p class="text-center text-sm font-bold text-amber-900 mt-3 border-t border-amber-200 pt-2">
          Tổng công ty đóng: {{ fv(payroll.total_insurance_employer) }}
        </p>
      </div>
    </div>

    <!-- Edit Modal -->
    <div v-if="showEditModal" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4">
      <div class="bg-white rounded-xl shadow-xl max-w-md w-full">
        <div class="px-5 py-4 border-b bg-gray-50 flex justify-between">
          <h3 class="font-bold text-gray-900">Cập nhật lương — {{ activeItem.employee_name }}</h3>
          <button @click="showEditModal = false" class="text-gray-400 hover:text-gray-600 text-lg">&times;</button>
        </div>
        <form @submit.prevent="submitEdit" class="p-5 space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="form-label text-xs">Lương cơ bản</label>
              <input type="number" v-model.number="editForm.base_salary" min="0" step="1"
                class="form-input text-right font-mono text-sm" />
            </div>
            <div>
              <label class="form-label text-xs">Phụ cấp</label>
              <input type="number" v-model.number="editForm.allowance" min="0" step="1"
                class="form-input text-right font-mono text-sm" />
            </div>
            <div>
              <label class="form-label text-xs">Thưởng</label>
              <input type="number" v-model.number="editForm.bonus" min="0" step="1"
                class="form-input text-right font-mono text-sm" />
            </div>
            <div>
              <label class="form-label text-xs">Số người phụ thuộc</label>
              <input type="number" v-model.number="editForm.dependents_count" min="0" max="10"
                class="form-input text-right text-sm" />
            </div>
          </div>

          <!-- Live preview -->
          <div class="bg-blue-50 rounded-lg p-3 text-xs space-y-1">
            <div class="flex justify-between">
              <span class="text-gray-600">Gross:</span>
              <span class="font-mono font-semibold">{{ fv(previewGross) }}</span>
            </div>
            <div class="flex justify-between text-orange-600">
              <span>BHXH/BHYT/BHTN (NV):</span>
              <span class="font-mono">-{{ fv(previewInsEmp) }}</span>
            </div>
            <div class="flex justify-between text-red-600">
              <span>Thuế TNCN:</span>
              <span class="font-mono">-{{ fv(previewPit) }}</span>
            </div>
            <div class="flex justify-between border-t border-blue-200 pt-1 font-bold text-primary-700 text-sm">
              <span>Thực lĩnh:</span>
              <span class="font-mono">{{ fv(previewNet) }}</span>
            </div>
          </div>

          <div class="flex gap-3 pt-2">
            <button type="submit" :disabled="editForm.processing" class="btn-primary flex-1">Lưu</button>
            <button type="button" @click="showEditModal = false" class="btn-secondary">Huỷ</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Pay Modal -->
    <div v-if="showPayModal" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4">
      <div class="bg-white rounded-xl shadow-xl max-w-md w-full">
        <div class="px-5 py-4 border-b bg-gray-50 flex justify-between">
          <h3 class="font-bold">Chi lương — {{ activeItem.employee_name }}</h3>
          <button @click="showPayModal = false" class="text-gray-400 hover:text-gray-600 text-lg">&times;</button>
        </div>
        <form @submit.prevent="submitPay" class="p-5 space-y-4">
          <p class="text-xl font-extrabold text-green-600 font-mono text-center">{{ fv(activeItem.net_salary) }}</p>
          <div>
            <label class="form-label">Quỹ / Tài khoản</label>
            <select v-model="payForm.fund_id" required class="form-input">
              <option value="" disabled>-- Chọn quỹ --</option>
              <option v-for="fund in funds" :key="fund.id" :value="fund.id">
                {{ fund.name }} ({{ fund.type === 'cash' ? 'Tiền mặt' : 'Ngân hàng' }})
              </option>
            </select>
          </div>
          <div class="flex gap-3">
            <button type="submit" :disabled="payForm.processing" class="btn-primary flex-1">Xác nhận chi tiền</button>
            <button type="button" @click="showPayModal = false" class="btn-secondary">Huỷ</button>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({ payroll: Object, items: Array, funds: Array });

function fv(val) {
  return new Intl.NumberFormat('vi-VN').format(val || 0) + ' ₫';
}

function sumItems(field) {
  return props.items.reduce((s, i) => s + (i[field] || 0), 0);
}

// -- Payroll PIT rates (mirror of PHP PitCalculatorService) --
const PERSONAL_DED  = 11_000_000;
const DEPENDENT_DED = 4_400_000;
const INS_CAP       = 46_800_000;
const BRACKETS = [[5e6,5],[10e6,10],[18e6,15],[32e6,20],[52e6,25],[80e6,30],[null,35]];

function calcInsEmp(gross) {
  const base = Math.min(gross, INS_CAP);
  return Math.round(base * (0.08 + 0.015 + 0.01));
}

function calcPit(gross, deps) {
  const insEmp   = calcInsEmp(gross);
  const deduction = PERSONAL_DED + (deps * DEPENDENT_DED);
  let taxable    = Math.max(0, gross - insEmp - deduction);
  let tax = 0, prev = 0;
  for (const [cap, rate] of BRACKETS) {
    if (taxable <= prev) break;
    const upper = cap ?? 1e15;
    tax  += (Math.min(taxable, upper) - prev) * rate / 100;
    prev  = upper;
    if (!cap || taxable <= cap) break;
  }
  return Math.round(tax);
}

// Confirm
function confirmPayroll() {
  if (confirm('Xác nhận bảng lương? Sau khi xác nhận, lương sẽ không thể chỉnh sửa.')) {
    router.post(route('accounting.payrolls.confirm', props.payroll.id));
  }
}

// Edit modal
const showEditModal = ref(false);
const activeItem    = ref({});
const editForm = useForm({ base_salary: 0, allowance: 0, bonus: 0, dependents_count: 0 });

const previewGross  = computed(() => (editForm.base_salary || 0) + (editForm.allowance || 0) + (editForm.bonus || 0));
const previewInsEmp = computed(() => calcInsEmp(previewGross.value));
const previewPit    = computed(() => calcPit(previewGross.value, editForm.dependents_count || 0));
const previewNet    = computed(() => previewGross.value - previewInsEmp.value - previewPit.value);

function openEditModal(item) {
  activeItem.value = item;
  editForm.base_salary      = item.base_salary;
  editForm.allowance        = item.allowance;
  editForm.bonus            = item.bonus;
  editForm.dependents_count = item.dependents_count;
  showEditModal.value = true;
}

function submitEdit() {
  editForm.put(route('accounting.payrolls.items.update', { payroll: props.payroll.id, item: activeItem.value.id }), {
    onSuccess: () => { showEditModal.value = false; },
  });
}

// Pay modal
const showPayModal = ref(false);
const payForm = useForm({ fund_id: '' });

function openPayModal(item) {
  activeItem.value = item;
  payForm.fund_id  = '';
  showPayModal.value = true;
}

function submitPay() {
  payForm.post(route('accounting.payrolls.items.pay', { payroll: props.payroll.id, item: activeItem.value.id }), {
    onSuccess: () => { showPayModal.value = false; },
  });
}
</script>
