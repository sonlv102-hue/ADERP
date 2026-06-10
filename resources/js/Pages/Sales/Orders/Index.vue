<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-slate-900">Đơn hàng</h1>
        <Link :href="route('sales.orders.create')" class="erp-btn-primary">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Tạo đơn hàng
        </Link>
      </div>

      <!-- Search -->
      <div class="flex gap-3 flex-wrap">
        <input v-model="search" @input="doSearch" type="text"
          placeholder="Tìm đơn hàng, khách hàng, mã chứng từ..."
          class="border border-slate-300 rounded-lg px-3 py-2 text-sm w-72 focus:outline-none focus:ring-2 focus:ring-primary-500" />
        <select v-model="statusFilter" @change="doSearch"
          class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả trạng thái</option>
          <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
        <button v-if="search || statusFilter" @click="clearSearch"
          class="text-slate-500 hover:text-slate-700 px-3 py-2 text-sm">Xóa lọc</button>
      </div>

      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 border-b border-slate-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Mã ĐH</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Khách hàng</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Ngày đặt</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">BG liên kết</th>
              <th class="text-right px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Tổng tiền</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Duyệt đơn</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Hợp đồng</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Hải quan</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Giao hàng</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="o in orders.data" :key="o.id" class="hover:bg-slate-50/70 transition-colors">
              <td class="px-5 py-3 font-mono text-xs font-semibold text-slate-700">{{ o.code }}</td>
              <td class="px-5 py-3 text-slate-800 font-medium">{{ o.customer }}</td>
              <td class="px-5 py-3 text-slate-600 whitespace-nowrap">
                {{ o.order_date }}
                <span v-if="o.expected_delivery" class="block text-xs text-slate-400">Giao: {{ o.expected_delivery }}</span>
              </td>
              <td class="px-5 py-3 font-mono text-xs text-slate-500">{{ o.quotation_code ?? '—' }}</td>
              <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ formatVnd(o.total) }}</td>

              <!-- Duyệt đơn -->
              <td class="px-5 py-3">
                <StatusBadge :color="o.status_color">{{ o.status_label }}</StatusBadge>
              </td>

              <!-- Hợp đồng -->
              <td class="px-5 py-3">
                <span v-if="o.has_contract"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                  <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                  </svg>
                  Có HĐ
                </span>
                <span v-else-if="o.status !== 'cancelled'"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200">
                  Chưa có HĐ
                </span>
                <span v-else class="text-slate-300 text-xs">—</span>
              </td>

              <!-- Hải quan -->
              <td class="px-5 py-3">
                <span v-if="o.customs_status === 'pending'"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-700 border border-amber-300">
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                  </svg>
                  Chờ khai HQ
                </span>
                <span v-else-if="o.customs_status === 'declared'"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                  <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                  </svg>
                  Đã khai HQ
                </span>
                <span v-else class="text-slate-300 text-xs">—</span>
              </td>

              <!-- Giao hàng -->
              <td class="px-5 py-3">
                <span v-if="o.delivery_status === 'done'"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                  <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                  </svg>
                  Đã giao
                </span>
                <span v-else-if="o.delivery_status === 'partial'"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                  </svg>
                  Đang giao
                </span>
                <span v-else-if="o.delivery_status === 'none'"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-500">
                  Chưa giao
                </span>
                <span v-else class="text-slate-300 text-xs">—</span>
              </td>

              <td class="px-5 py-3 text-right">
                <Link :href="route('sales.orders.show', o.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium text-xs">Xem →</Link>
              </td>
            </tr>
            <tr v-if="!orders.data?.length">
              <td colspan="10" class="px-5 py-14 text-center text-slate-400">
                <svg class="w-8 h-8 mx-auto mb-2 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
                Chưa có đơn hàng nào
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="orders.links" :meta="orders.meta" />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ orders: Object, filters: Object, statuses: Array });

const { formatVnd } = useCurrency();

const search       = ref(props.filters?.q ?? '');
const statusFilter = ref(props.filters?.status ?? '');
let searchTimer    = null;

function doSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    router.get(route('sales.orders.index'), {
      q:      search.value || undefined,
      status: statusFilter.value || undefined,
    }, { preserveState: true, replace: true });
  }, 300);
}
function clearSearch() {
  search.value       = '';
  statusFilter.value = '';
  doSearch();
}
</script>
