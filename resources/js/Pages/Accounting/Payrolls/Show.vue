<template>
  <AppLayout>
    <div class="max-w-6xl space-y-6">
      <!-- Breadcrumb and actions -->
      <div class="flex items-center justify-between">
        <div class="flex items-center space-x-2">
          <Link :href="route('accounting.payrolls.index')" class="text-gray-400 hover:text-gray-600 text-sm">
            &larr; Danh sách bảng lương
          </Link>
        </div>
        <div class="flex items-center space-x-3">
          <button v-if="payroll.status === 'draft'" @click="confirmPayroll"
            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-semibold shadow-sm transition">
            Xác nhận bảng lương
          </button>
          <span v-else :class="{
            'bg-blue-100 text-blue-800': payroll.status === 'confirmed',
            'bg-green-100 text-green-800': payroll.status === 'paid',
          }" class="px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider">
            Bảng lương: {{ payroll.status_label }}
          </span>
        </div>
      </div>

      <!-- Flash messages -->
      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm">
        {{ $page.props.flash.success }}
      </div>
      <div v-if="$page.props.flash?.error" class="bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm">
        {{ $page.props.flash.error }}
      </div>

      <!-- Summary Card -->
      <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 grid grid-cols-1 md:grid-cols-5 gap-5">
        <div class="border-b md:border-b-0 md:border-r border-gray-100 pb-3 md:pb-0 md:pr-5">
          <p class="text-xs text-gray-400 font-semibold uppercase tracking-wider">Bảng lương</p>
          <h2 class="text-xl font-bold text-gray-900 font-mono mt-1">{{ payroll.code }}</h2>
          <p class="text-sm text-gray-500 mt-1">Tháng: <span class="font-semibold text-gray-700">{{ payroll.period }}</span></p>
          <p v-if="payroll.notes" class="text-xs text-gray-400 mt-2 italic">"{{ payroll.notes }}"</p>
        </div>

        <div class="pb-3 md:pb-0">
          <p class="text-xs text-gray-400 font-semibold uppercase tracking-wider">Tổng lương cơ bản</p>
          <p class="text-lg font-bold text-gray-800 font-mono mt-1">{{ formatVnd(payroll.total_base_salary) }}</p>
        </div>

        <div class="pb-3 md:pb-0">
          <p class="text-xs text-gray-400 font-semibold uppercase tracking-wider">Tổng phụ cấp</p>
          <p class="text-lg font-bold text-gray-800 font-mono mt-1">{{ formatVnd(payroll.total_allowance) }}</p>
        </div>

        <div class="pb-3 md:pb-0">
          <p class="text-xs text-gray-400 font-semibold uppercase tracking-wider">Tổng thưởng / phạt</p>
          <p class="text-sm font-semibold mt-1">
            <span class="text-green-600 font-mono">+{{ formatVnd(payroll.total_bonus) }}</span>
            <br />
            <span class="text-red-500 font-mono">-{{ formatVnd(payroll.total_deductions) }}</span>
          </p>
        </div>

        <div>
          <p class="text-xs text-gray-400 font-semibold uppercase tracking-wider">Tổng thực lĩnh</p>
          <p class="text-xl font-extrabold text-primary-600 font-mono mt-1">{{ formatVnd(payroll.total_net_salary) }}</p>
        </div>
      </div>

      <!-- Employees List -->
      <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
          <h3 class="font-bold text-gray-800">Chi tiết lương nhân viên</h3>
          <span class="text-xs text-gray-500 font-medium">Số nhân viên: {{ items.length }}</span>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50">
              <tr>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Nhân viên</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Lương cơ bản</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Phụ cấp</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Thưởng</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Khấu trừ</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Thực lĩnh</th>
                <th class="text-center px-5 py-3 font-semibold text-gray-600">Thanh toán</th>
                <th class="px-5 py-3"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="item in items" :key="item.id" class="hover:bg-gray-50">
                <td class="px-5 py-4">
                  <p class="font-bold text-gray-800">{{ item.employee_name }}</p>
                  <p class="text-xs text-gray-400 capitalize">{{ item.role_label }}</p>
                </td>
                <td class="px-5 py-4 text-right text-gray-700 font-mono">
                  {{ formatVnd(item.base_salary) }}
                </td>
                <td class="px-5 py-4 text-right text-gray-700 font-mono">
                  {{ formatVnd(item.allowance) }}
                </td>
                <td class="px-5 py-4 text-right text-green-600 font-mono">
                  +{{ formatVnd(item.bonus) }}
                </td>
                <td class="px-5 py-4 text-right text-red-500 font-mono">
                  -{{ formatVnd(item.deductions) }}
                </td>
                <td class="px-5 py-4 text-right font-bold text-gray-900 font-mono">
                  {{ formatVnd(item.net_salary) }}
                </td>
                <td class="px-5 py-4 text-center">
                  <span :class="{
                    'bg-yellow-100 text-yellow-800': item.status === 'pending',
                    'bg-green-100 text-green-800': item.status === 'paid',
                  }" class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-semibold">
                    {{ item.status_label }}
                  </span>
                  <p v-if="item.paid_at" class="text-[10px] text-gray-400 mt-1 font-mono">
                    {{ item.paid_at }}
                  </p>
                </td>
                <td class="px-5 py-4 text-right">
                  <div class="flex items-center justify-end space-x-2">
                    <!-- Edit salary button (Draft only) -->
                    <button v-if="payroll.status === 'draft'" @click="openEditModal(item)"
                      class="text-primary-600 hover:text-primary-800 text-xs font-bold hover:underline">
                      Sửa
                    </button>

                    <!-- Pay button (Confirmed & Pending only) -->
                    <button v-if="payroll.status === 'confirmed' && item.status === 'pending'"
                      @click="openPayModal(item)"
                      class="bg-green-600 hover:bg-green-700 text-white px-2.5 py-1 rounded text-xs font-bold transition">
                      Chi lương
                    </button>

                    <!-- Cash Voucher Link (Paid only) -->
                    <Link v-if="item.cash_voucher"
                      :href="route('accounting.cash-vouchers.show', item.cash_voucher.id)"
                      class="text-green-600 hover:text-green-800 text-xs font-mono font-bold hover:underline">
                      {{ item.cash_voucher.code }}
                    </Link>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Edit Salary Modal (Draft) -->
    <div v-if="showEditModal" class="fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-40 flex items-center justify-center p-4">
      <div class="bg-white rounded-xl border border-gray-200 shadow-xl max-w-md w-full overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
          <h3 class="font-bold text-gray-900">Cập nhật lương nhân viên</h3>
          <button @click="showEditModal = false" class="text-gray-400 hover:text-gray-600 text-lg font-bold">&times;</button>
        </div>

        <form @submit.prevent="submitEdit" class="p-5 space-y-4">
          <p class="text-sm font-semibold text-gray-800">Nhân viên: <span class="text-primary-600">{{ activeItem.employee_name }}</span></p>

          <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Lương cơ bản *</label>
            <input type="number" v-model="editForm.base_salary" min="0" required
              class="w-full border-gray-300 rounded-lg shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm font-mono text-right" />
          </div>

          <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Phụ cấp cố định *</label>
            <input type="number" v-model="editForm.allowance" min="0" required
              class="w-full border-gray-300 rounded-lg shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm font-mono text-right" />
          </div>

          <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Tiền thưởng (KPI, Dự án)</label>
            <input type="number" v-model="editForm.bonus" min="0" required
              class="w-full border-gray-300 rounded-lg shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm font-mono text-right" />
          </div>

          <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Khấu trừ (Bảo hiểm, Kỷ luật)</label>
            <input type="number" v-model="editForm.deductions" min="0" required
              class="w-full border-gray-300 rounded-lg shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm font-mono text-right" />
          </div>

          <div class="bg-primary-50 rounded-lg p-3 text-right">
            <span class="text-xs text-primary-700 block">Thực lĩnh dự tính:</span>
            <span class="text-lg font-extrabold text-primary-900 font-mono">{{ formatVnd(calculatedNetSalary) }}</span>
          </div>

          <div class="flex items-center justify-end space-x-3 pt-3 border-t border-gray-100">
            <button type="button" @click="showEditModal = false"
              class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 font-semibold">
              Hủy
            </button>
            <button type="submit" :disabled="editForm.processing"
              class="px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg text-sm font-semibold disabled:opacity-50">
              Lưu thay đổi
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Pay Salary Modal (Confirmed) -->
    <div v-if="showPayModal" class="fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-40 flex items-center justify-center p-4">
      <div class="bg-white rounded-xl border border-gray-200 shadow-xl max-w-md w-full overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
          <h3 class="font-bold text-gray-900">Chi lương cho nhân viên</h3>
          <button @click="showPayModal = false" class="text-gray-400 hover:text-gray-600 text-lg font-bold">&times;</button>
        </div>

        <form @submit.prevent="submitPay" class="p-5 space-y-4">
          <div class="space-y-1">
            <p class="text-sm font-medium text-gray-500">Nhân viên thực nhận:</p>
            <p class="text-base font-bold text-gray-900">{{ activeItem.employee_name }}</p>
          </div>

          <div class="space-y-1">
            <p class="text-sm font-medium text-gray-500">Số tiền chi trả:</p>
            <p class="text-xl font-extrabold text-green-600 font-mono">{{ formatVnd(activeItem.net_salary) }}</p>
          </div>

          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">Chọn Quỹ / Tài khoản trích chi *</label>
            <select v-model="payForm.fund_id" required
              class="w-full border-gray-300 rounded-lg shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm">
              <option value="" disabled>-- Chọn tài khoản ngân hàng hoặc quỹ tiền mặt --</option>
              <option v-for="fund in funds" :key="fund.id" :value="fund.id">
                {{ fund.name }} ({{ fund.type === 'cash' ? 'Tiền mặt' : 'Ngân hàng' }})
              </option>
            </select>
          </div>

          <div class="bg-amber-50 border border-amber-200 text-amber-800 p-3 rounded-lg text-xs leading-relaxed">
            <strong>Lưu ý:</strong> Khi thực hiện xác nhận, hệ thống sẽ tự động tạo một Phiếu chi tiền mặt/tiền gửi tương ứng ở trạng thái ĐÃ XÁC NHẬN để trích trừ số dư của quỹ ngay lập tức.
          </div>

          <div class="flex items-center justify-end space-x-3 pt-3 border-t border-gray-100">
            <button type="button" @click="showPayModal = false"
              class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 font-semibold">
              Hủy
            </button>
            <button type="submit" :disabled="payForm.processing"
              class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-semibold disabled:opacity-50">
              Xác nhận chi tiền
            </button>
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
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  payroll: Object,
  items: Array,
  funds: Array,
});

const { formatVnd } = useCurrency();

// Confirm entire payroll
const confirmPayroll = () => {
  if (confirm('Bạn có chắc chắn muốn xác nhận bảng lương tháng này? Sau khi xác nhận, thông tin lương sẽ không thể chỉnh sửa và sẵn sàng để chi trả.')) {
    router.post(route('accounting.payrolls.confirm', props.payroll.id));
  }
};

// Edit Salary modal state
const showEditModal = ref(false);
const activeItem = ref({});
const editForm = useForm({
  base_salary: 0,
  allowance: 0,
  bonus: 0,
  deductions: 0,
});

const openEditModal = (item) => {
  activeItem.value = item;
  editForm.base_salary = item.base_salary;
  editForm.allowance = item.allowance;
  editForm.bonus = item.bonus;
  editForm.deductions = item.deductions;
  showEditModal.value = true;
};

const calculatedNetSalary = computed(() => {
  const base = parseFloat(editForm.base_salary) || 0;
  const allow = parseFloat(editForm.allowance) || 0;
  const bon = parseFloat(editForm.bonus) || 0;
  const ded = parseFloat(editForm.deductions) || 0;
  return Math.max(0, base + allow + bon - ded);
});

const submitEdit = () => {
  editForm.put(route('accounting.payrolls.items.update', {
    payroll: props.payroll.id,
    item: activeItem.value.id
  }), {
    onSuccess: () => {
      showEditModal.value = false;
    }
  });
};

// Pay employee salary modal state
const showPayModal = ref(false);
const payForm = useForm({
  fund_id: '',
});

const openPayModal = (item) => {
  activeItem.value = item;
  payForm.fund_id = '';
  showPayModal.value = true;
};

const submitPay = () => {
  payForm.post(route('accounting.payrolls.items.pay', {
    payroll: props.payroll.id,
    item: activeItem.value.id
  }), {
    onSuccess: () => {
      showPayModal.value = false;
    }
  });
};
</script>
