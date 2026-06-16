<template>
  <AppLayout>
    <div class="max-w-6xl space-y-5">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Bảng lương tháng</h1>
          <p class="text-sm text-gray-500">Quản lý lương nhân viên, phụ cấp, thưởng phạt và chi trả lương.</p>
        </div>
        <Link :href="route('accounting.payrolls.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          + Lập bảng lương mới
        </Link>
      </div>

      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm">
        {{ $page.props.flash.success }}
      </div>
      <div v-if="$page.props.flash?.error" class="bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm">
        {{ $page.props.flash.error }}
      </div>

      <!-- Filters -->
      <div class="bg-white rounded-xl border border-gray-200 px-4 py-3 flex flex-wrap items-end gap-3">
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">Tháng/Năm (YYYY-MM)</label>
          <input v-model="filterPeriod" type="month" @change="applyFilters"
            class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">Trạng thái</label>
          <select v-model="filterStatus" @change="applyFilters"
            class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-1 focus:ring-primary-500 focus:border-primary-500">
            <option value="">Tất cả</option>
            <option value="draft">Nháp</option>
            <option value="confirmed">Đã xác nhận</option>
            <option value="paid">Đã chi</option>
          </select>
        </div>
        <button v-if="filterPeriod || filterStatus" @click="clearFilters"
          class="text-xs text-gray-500 hover:text-gray-700 border border-gray-200 px-3 py-1.5 rounded-lg hover:bg-gray-50">
          Xóa bộ lọc
        </button>
        <span v-if="filterPeriod || filterStatus" class="text-xs text-gray-400">
          {{ payrolls.total }} kết quả
        </span>
      </div>

      <!-- Payroll tables list -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden shadow-sm">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50">
              <tr>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã bảng lương</th>
                <th class="text-center px-5 py-3 font-semibold text-gray-600">Tháng</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng lương cơ bản</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng phụ cấp</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng thưởng</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng khấu trừ</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng thực lĩnh</th>
                <th class="text-center px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
                <th class="px-5 py-3"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="p in payrolls.data" :key="p.id" class="hover:bg-gray-50">
                <td class="px-5 py-4 font-mono text-xs font-semibold text-gray-800">
                  {{ p.code }}
                </td>
                <td class="px-5 py-4 text-center text-gray-700 font-medium">
                  {{ p.period }}
                </td>
                <td class="px-5 py-4 text-right text-gray-600 font-mono">
                  {{ formatVnd(p.total_base_salary) }}
                </td>
                <td class="px-5 py-4 text-right text-gray-600 font-mono">
                  {{ formatVnd(p.total_allowance) }}
                </td>
                <td class="px-5 py-4 text-right text-green-600 font-mono">
                  +{{ formatVnd(p.total_bonus) }}
                </td>
                <td class="px-5 py-4 text-right text-red-600 font-mono">
                  -{{ formatVnd(p.total_deductions) }}
                </td>
                <td class="px-5 py-4 text-right font-bold text-gray-900 font-mono">
                  {{ formatVnd(p.total_net_salary) }}
                </td>
                <td class="px-5 py-4 text-center">
                  <span :class="{
                    'bg-gray-100 text-gray-700': p.status === 'draft',
                    'bg-blue-100 text-blue-700': p.status === 'confirmed',
                    'bg-green-100 text-green-700': p.status === 'paid',
                  }" class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium">
                    {{ p.status_label }}
                  </span>
                </td>
                <td class="px-5 py-4 text-center">
                  <div class="flex items-center justify-end space-x-3">
                    <Link :href="route('accounting.payrolls.show', p.id)"
                      class="text-primary-600 hover:text-primary-800 text-xs font-semibold">
                      Chi tiết
                    </Link>
                    <button v-if="p.status === 'draft'" @click="deletePayroll(p.id)"
                      class="text-red-500 hover:text-red-700 text-xs font-semibold">
                      Xóa
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="!payrolls.data.length">
                <td colspan="9" class="px-5 py-12 text-center text-gray-400 text-sm">
                  Chưa có bảng lương tháng nào được lập.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  payrolls: Object,
  filters:  Object,
});

const { formatVnd } = useCurrency();

const filterPeriod = ref(props.filters?.period ?? '');
const filterStatus = ref(props.filters?.status ?? '');

function applyFilters() {
  const params = {};
  if (filterPeriod.value) params.period = filterPeriod.value;
  if (filterStatus.value) params.status = filterStatus.value;
  router.get(route('accounting.payrolls.index'), params, { preserveState: true, replace: true });
}

function clearFilters() {
  filterPeriod.value = '';
  filterStatus.value = '';
  router.get(route('accounting.payrolls.index'), {}, { preserveState: false });
}

const deletePayroll = (id) => {
  if (confirm('Bạn có chắc chắn muốn xóa bảng lương nháp này không?')) {
    router.delete(route('accounting.payrolls.destroy', id));
  }
};
</script>
