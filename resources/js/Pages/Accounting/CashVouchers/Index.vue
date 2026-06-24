<template>
  <AppLayout>
    <div class="max-w-6xl space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <h1 class="text-2xl font-bold text-gray-900">Phiếu thu / Phiếu chi</h1>
        <div class="flex gap-2 flex-wrap">
          <ExportExcelButton :endpoint="route('accounting.cash-vouchers.export-excel')" :filters="filters" />
          <Link :href="route('accounting.cash-vouchers.create', { type: 'receipt' })"
            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            + Phiếu thu
          </Link>
          <Link :href="route('accounting.cash-vouchers.create', { type: 'payment' })"
            class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            + Phiếu chi
          </Link>
        </div>
      </div>

      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm">
        {{ $page.props.flash.success }}
      </div>

      <!-- Filters -->
      <div class="bg-white rounded-xl border border-gray-200 p-4 flex flex-wrap gap-3">
        <select v-model="filters.type" @change="applyFilters"
          class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none">
          <option value="">Tất cả loại</option>
          <option value="receipt">Phiếu thu</option>
          <option value="payment">Phiếu chi</option>
        </select>
        <select v-model="filters.status" @change="applyFilters"
          class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none">
          <option value="">Tất cả trạng thái</option>
          <option value="draft">Nháp</option>
          <option value="confirmed">Đã xác nhận</option>
          <option value="cancelled">Đã hủy</option>
        </select>
        <select v-model="filters.fund_id" @change="applyFilters"
          class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none">
          <option value="">Tất cả quỹ</option>
          <option v-for="f in funds" :key="f.id" :value="f.id">{{ f.name }}</option>
        </select>
        <input v-model="filters.search" @keyup.enter="applyFilters" type="text" placeholder="Tìm mã, diễn giải..."
          class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none flex-1 min-w-40" />
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã phiếu</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Loại</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Quỹ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Đối tác</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Số tiền</th>
              <th class="text-center px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="v in vouchers.data" :key="v.id"
              class="hover:bg-gray-50 cursor-pointer"
              @click="$inertia.visit(route('accounting.cash-vouchers.show', v.id))">
              <td class="px-5 py-3 font-mono font-medium text-gray-800">{{ v.code }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="v.type_color">{{ v.type_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-gray-600">{{ v.fund }}</td>
              <td class="px-5 py-3 text-gray-600">{{ v.counterparty ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ v.voucher_date }}</td>
              <td class="px-5 py-3 text-right font-semibold"
                :class="v.type === 'receipt' ? 'text-green-700' : 'text-red-700'">
                {{ v.type === 'receipt' ? '+' : '-' }}{{ formatVnd(v.amount) }}
              </td>
              <td class="px-5 py-3 text-center">
                <StatusBadge :color="v.status_color">{{ v.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-right" @click.stop>
                <button v-if="v.status === 'cancelled'" @click="handleDelete(v)"
                  class="text-red-600 hover:text-red-800 text-xs font-medium">Xóa</button>
              </td>
            </tr>
            <tr v-if="!vouchers.data.length">
              <td colspan="8" class="px-5 py-8 text-center text-gray-400">Không có phiếu nào</td>
            </tr>
          </tbody>
        </table>
        <Pagination :links="vouchers.links" class="px-5 py-3 border-t border-gray-200" />
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import ExportExcelButton from '@/Components/Shared/ExportExcelButton.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ vouchers: Object, funds: Array, filters: Object });
const { formatVnd } = useCurrency();

const filters = ref({
  type:    props.filters.type    ?? '',
  status:  props.filters.status  ?? '',
  fund_id: props.filters.fund_id ?? '',
  search:  props.filters.search  ?? '',
});

function applyFilters() {
  router.get(route('accounting.cash-vouchers.index'), filters.value, { preserveState: true });
}

function handleDelete(v) {
  if (window.confirm(`Xóa vĩnh viễn phiếu ${v.code}? Không thể hoàn tác.`)) {
    router.delete(route('accounting.cash-vouchers.destroy', v.id), {}, { preserveScroll: true });
  }
}
</script>
