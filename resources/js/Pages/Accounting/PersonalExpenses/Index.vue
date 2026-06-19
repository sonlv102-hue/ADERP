<template>
  <AppLayout>
    <div class="max-w-6xl space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <h1 class="text-2xl font-bold text-gray-900">Chi hộ cá nhân (TK 3388)</h1>
        <Link :href="route('accounting.personal-expenses.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-semibold">
          + Tạo phiếu chi hộ
        </Link>
      </div>

      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm">
        {{ $page.props.flash.success }}
      </div>

      <!-- Filters -->
      <div class="flex gap-3 flex-wrap">
        <select v-model="filter.status" @change="applyFilters"
          class="px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">-- Tất cả trạng thái --</option>
          <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
        <select v-model="filter.person_type" @change="applyFilters"
          class="px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">-- Tất cả đối tượng --</option>
          <option value="employee">Nhân viên</option>
          <option value="shareholder">Thành viên/Cổ đông</option>
          <option value="other">Khác</option>
        </select>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-4 py-3 font-medium text-gray-600">Số phiếu</th>
              <th class="text-left px-4 py-3 font-medium text-gray-600">Ngày chi</th>
              <th class="text-left px-4 py-3 font-medium text-gray-600">Người chi hộ</th>
              <th class="text-left px-4 py-3 font-medium text-gray-600">Nội dung</th>
              <th class="text-right px-4 py-3 font-medium text-gray-600">Tổng tiền</th>
              <th class="text-center px-4 py-3 font-medium text-gray-600">Trạng thái</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="reports.data.length === 0">
              <td colspan="6" class="px-4 py-8 text-center text-gray-400">Chưa có phiếu chi hộ nào.</td>
            </tr>
            <tr v-for="r in reports.data" :key="r.id" class="hover:bg-gray-50 cursor-pointer"
              @click="$inertia.visit(route('accounting.personal-expenses.show', r.id))">
              <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ r.report_no }}</td>
              <td class="px-4 py-3 text-gray-600">{{ r.expense_date }}</td>
              <td class="px-4 py-3 font-medium text-gray-900">{{ r.person_name }}</td>
              <td class="px-4 py-3 text-gray-600 truncate max-w-xs">{{ r.description }}</td>
              <td class="px-4 py-3 text-right font-medium">{{ formatVnd(r.total_amount) }}</td>
              <td class="px-4 py-3 text-center">
                <span :class="statusClass(r.status_color)" class="px-2 py-0.5 rounded-full text-xs font-medium">
                  {{ r.status_label }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ reports: Object, filters: Object, statuses: Array });
const { formatVnd } = useCurrency();

const filter = ref({ status: props.filters?.status || '', person_type: props.filters?.person_type || '' });

function applyFilters() {
  router.get(route('accounting.personal-expenses.index'), filter.value, { preserveState: true, replace: true });
}
function statusClass(color) {
  const map = { gray: 'bg-gray-100 text-gray-600', blue: 'bg-blue-100 text-blue-700', green: 'bg-green-100 text-green-700' };
  return map[color] ?? 'bg-gray-100 text-gray-600';
}
</script>
