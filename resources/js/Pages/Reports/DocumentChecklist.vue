<template>
  <AppLayout>
    <div class="space-y-5">
      <h1 class="text-2xl font-bold text-gray-900">Bảng kê chứng từ</h1>

      <!-- Tabs -->
      <div class="flex gap-1 bg-gray-100 p-1 rounded-xl w-fit">
        <button @click="switchTab('sales')"
          :class="activeTab === 'sales'
            ? 'bg-white text-gray-900 shadow-sm'
            : 'text-gray-500 hover:text-gray-700'"
          class="px-5 py-2 rounded-lg text-sm font-medium transition-all">
          Đơn hàng bán
        </button>
        <button @click="switchTab('purchases')"
          :class="activeTab === 'purchases'
            ? 'bg-white text-gray-900 shadow-sm'
            : 'text-gray-500 hover:text-gray-700'"
          class="px-5 py-2 rounded-lg text-sm font-medium transition-all">
          Đơn hàng mua
        </button>
      </div>

      <!-- Filters -->
      <div class="bg-white rounded-xl border border-gray-200 p-4 flex flex-wrap gap-3 items-center">
        <div class="flex gap-2 items-center">
          <label class="text-sm text-gray-600 whitespace-nowrap">Từ ngày</label>
          <input v-model="filters.date_from" type="date" @change="applyFilters"
            class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
        </div>
        <div class="flex gap-2 items-center">
          <label class="text-sm text-gray-600 whitespace-nowrap">Đến ngày</label>
          <input v-model="filters.date_to" type="date" @change="applyFilters"
            class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
        </div>
        <select v-model="filters.partner_id" @change="applyFilters"
          class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none min-w-44">
          <option value="">{{ activeTab === 'sales' ? 'Tất cả khách hàng' : 'Tất cả nhà cung cấp' }}</option>
          <option v-for="p in partnerList" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
        <label class="flex items-center gap-2 cursor-pointer select-none">
          <input v-model="filters.missing" type="checkbox" true-value="1" false-value="" @change="applyFilters"
            class="h-4 w-4 rounded border-gray-300 text-primary-600" />
          <span class="text-sm text-gray-700">Chỉ hiện đơn thiếu chứng từ</span>
        </label>

        <!-- Summary chips -->
        <div class="ml-auto flex gap-2 text-xs">
          <span class="px-2.5 py-1 rounded-full bg-gray-100 text-gray-600 font-medium">
            Tổng: {{ rows.length }} đơn
          </span>
          <span class="px-2.5 py-1 rounded-full bg-green-100 text-green-700 font-medium">
            Đủ: {{ completeCount }}
          </span>
          <span class="px-2.5 py-1 rounded-full bg-red-100 text-red-700 font-medium">
            Thiếu: {{ rows.length - completeCount }}
          </span>
        </div>
      </div>

      <!-- Sales table -->
      <div v-if="activeTab === 'sales'" class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Đơn hàng</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Khách hàng</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Ngày đặt</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="text-center px-4 py-3 font-semibold text-gray-600">Hợp đồng</th>
              <th class="text-center px-4 py-3 font-semibold text-gray-600">Hóa đơn</th>
              <th class="text-center px-4 py-3 font-semibold text-gray-600">Xuất kho</th>
              <th class="text-center px-4 py-3 font-semibold text-gray-600">HQ Khai báo</th>
              <th class="text-center px-4 py-3 font-semibold text-gray-600">Tình trạng</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="r in rows" :key="r.id" :class="r.is_complete ? '' : 'bg-red-50/30'">
              <td class="px-4 py-3 font-mono text-xs">
                <Link :href="route('sales.orders.show', r.id)" class="text-primary-600 hover:underline font-medium">
                  {{ r.code }}
                </Link>
              </td>
              <td class="px-4 py-3 text-gray-800">
                {{ r.partner }}
                <span v-if="r.is_fdi" class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-semibold bg-amber-100 text-amber-700 border border-amber-200">FDI</span>
              </td>
              <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ r.date }}</td>
              <td class="px-4 py-3">
                <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">{{ r.status }}</span>
              </td>
              <td class="px-4 py-3 text-center"><DocBadge :ok="r.has_contract" /></td>
              <td class="px-4 py-3 text-center"><DocBadge :ok="r.has_invoice" /></td>
              <td class="px-4 py-3 text-center"><DocBadge :ok="r.has_stock_exit" /></td>
              <td class="px-4 py-3 text-center">
                <DocBadge v-if="r.needs_customs" :ok="r.has_customs" :label="r.customs_status === 'declared' ? 'Đã khai' : 'Chờ khai'" />
                <span v-else class="text-gray-300 text-xs">—</span>
              </td>
              <td class="px-4 py-3 text-center">
                <span v-if="r.is_complete"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                  <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                  </svg>
                  Đủ chứng từ
                </span>
                <span v-else
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                  </svg>
                  Thiếu: {{ r.missing_docs.join(', ') }}
                </span>
              </td>
            </tr>
            <tr v-if="!rows.length">
              <td colspan="9" class="px-5 py-10 text-center text-gray-400">Không có dữ liệu</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Purchases table -->
      <div v-if="activeTab === 'purchases'" class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Đơn mua</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Nhà cung cấp</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Ngày đặt</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="text-center px-4 py-3 font-semibold text-gray-600">Hợp đồng mua</th>
              <th class="text-center px-4 py-3 font-semibold text-gray-600">Hóa đơn NCC</th>
              <th class="text-center px-4 py-3 font-semibold text-gray-600">Nhập kho</th>
              <th class="text-center px-4 py-3 font-semibold text-gray-600">Tình trạng</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="r in rows" :key="r.id" :class="r.is_complete ? '' : 'bg-red-50/30'">
              <td class="px-4 py-3 font-mono text-xs">
                <Link :href="route('purchasing.purchase-orders.show', r.id)" class="text-primary-600 hover:underline font-medium">
                  {{ r.code }}
                </Link>
              </td>
              <td class="px-4 py-3 text-gray-800">{{ r.partner }}</td>
              <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ r.date }}</td>
              <td class="px-4 py-3">
                <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">{{ r.status }}</span>
              </td>
              <td class="px-4 py-3 text-center"><DocBadge :ok="r.has_contract" /></td>
              <td class="px-4 py-3 text-center"><DocBadge :ok="r.has_invoice" /></td>
              <td class="px-4 py-3 text-center"><DocBadge :ok="r.has_stock_entry" /></td>
              <td class="px-4 py-3 text-center">
                <span v-if="r.is_complete"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                  <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                  </svg>
                  Đủ chứng từ
                </span>
                <span v-else
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                  </svg>
                  Thiếu: {{ r.missing_docs.join(', ') }}
                </span>
              </td>
            </tr>
            <tr v-if="!rows.length">
              <td colspan="8" class="px-5 py-10 text-center text-gray-400">Không có dữ liệu</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, defineComponent, h } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  tab: String, sales: Array, purchases: Array,
  customers: Array, suppliers: Array, filters: Object,
});

// Inline DocBadge component
const DocBadge = defineComponent({
  props: { ok: Boolean, label: String },
  setup(p) {
    return () => p.ok
      ? h('span', { class: 'inline-flex items-center justify-center w-6 h-6 rounded-full bg-green-100 text-green-600' },
          h('svg', { class: 'w-4 h-4', fill: 'currentColor', viewBox: '0 0 20 20' },
            h('path', { 'fill-rule': 'evenodd', d: 'M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z', 'clip-rule': 'evenodd' })
          )
        )
      : h('span', { class: 'inline-flex items-center justify-center w-6 h-6 rounded-full bg-red-100 text-red-500' },
          h('svg', { class: 'w-4 h-4', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' },
            h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M6 18L18 6M6 6l12 12' })
          )
        );
  },
});

const activeTab = ref(props.tab ?? 'sales');

const filters = ref({
  date_from:  props.filters.date_from ?? new Date(new Date().getFullYear(), 0, 1).toISOString().slice(0, 10),
  date_to:    props.filters.date_to   ?? new Date().toISOString().slice(0, 10),
  partner_id: props.filters.partner_id ?? '',
  missing:    props.filters.missing ?? '',
});

const rows = computed(() => activeTab.value === 'sales' ? props.sales : props.purchases);
const completeCount = computed(() => rows.value.filter(r => r.is_complete).length);
const partnerList = computed(() => activeTab.value === 'sales' ? props.customers : props.suppliers);

function switchTab(tab) {
  activeTab.value = tab;
  filters.value.partner_id = '';
  applyFilters();
}

function applyFilters() {
  router.get(route('reports.document_checklist'), {
    tab:        activeTab.value,
    date_from:  filters.value.date_from,
    date_to:    filters.value.date_to,
    partner_id: filters.value.partner_id || undefined,
    missing:    filters.value.missing || undefined,
  }, { preserveState: true });
}
</script>
