<template>
  <AppLayout>
    <div class="space-y-6">
      <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>

      <!-- KPI Cards -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <KpiCard title="Khách hàng"       :value="stats.total_customers"  color="bg-blue-500"   />
        <KpiCard title="Sản phẩm đang bán" :value="stats.total_products"   color="bg-emerald-500" />
        <KpiCard title="Ticket đang mở"   :value="stats.open_tickets"     color="bg-yellow-500" />
        <KpiCard title="Dự án đang chạy"  :value="stats.active_projects"  color="bg-purple-500" />
      </div>

      <!-- Charts row 1 -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <!-- Revenue bar chart -->
        <div class="bg-white rounded-xl border border-gray-200 p-5">
          <h3 class="text-base font-semibold text-gray-900 mb-4">Doanh thu theo tháng (12 tháng gần nhất)</h3>
          <div class="h-56">
            <Bar :data="revenueChartData" :options="barOptions" />
          </div>
        </div>

        <!-- Top customers horizontal bar -->
        <div class="bg-white rounded-xl border border-gray-200 p-5">
          <h3 class="text-base font-semibold text-gray-900 mb-4">Top 5 khách hàng theo doanh thu</h3>
          <div v-if="topCustomers.length" class="h-56">
            <Bar :data="topCustomersData" :options="horizontalBarOptions" />
          </div>
          <div v-else class="h-56 flex items-center justify-center text-gray-400 text-sm">
            Chưa có dữ liệu thanh toán
          </div>
        </div>
      </div>

      <!-- Over-delivery alerts -->
      <div v-if="overDeliveryAlerts.length" class="bg-white rounded-xl border border-red-300 overflow-hidden">
        <div class="px-5 py-4 border-b border-red-200 flex items-center justify-between bg-red-50">
          <div class="flex items-center gap-2">
            <svg class="w-5 h-5 text-red-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
            </svg>
            <h3 class="text-base font-semibold text-red-800">
              Xuất kho vượt đơn hàng — cần bổ sung ({{ overDeliveryAlerts.length }})
            </h3>
          </div>
          <span class="text-xs text-red-600 font-medium">Sẽ tự mất khi đơn bổ sung hoàn thành</span>
        </div>
        <div class="divide-y divide-gray-100">
          <div v-for="alert in overDeliveryAlerts" :key="alert.order_id" class="px-5 py-3">
            <div class="flex items-center justify-between mb-2">
              <div class="flex items-center gap-2">
                <span class="font-semibold text-gray-900 text-sm">{{ alert.order_code }}</span>
                <span class="text-xs text-gray-500">{{ alert.customer }}</span>
              </div>
              <div class="flex items-center gap-2 flex-wrap justify-end">
                <span v-if="alert.pending_supplementary"
                  class="text-xs px-2 py-1 bg-yellow-100 text-yellow-800 rounded-lg font-medium">
                  Đang bổ sung: {{ alert.pending_supplementary.code }}
                </span>
                <a :href="`/sales/sales-returns/create?from_order=${alert.order_id}`"
                  class="text-xs px-3 py-1.5 bg-orange-500 text-white rounded-lg hover:bg-orange-600 font-medium whitespace-nowrap">
                  ↩ Trả hàng
                </a>
                <a v-if="alert.contract" :href="`/sales/contracts/${alert.contract.id}`"
                  class="text-xs px-3 py-1.5 bg-white text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 font-medium whitespace-nowrap">
                  Hợp đồng {{ alert.contract.code }}
                </a>
                <a :href="`/sales/orders/create?supplementary_for=${alert.order_id}`"
                  class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium whitespace-nowrap">
                  + Đơn bổ sung
                </a>
              </div>
            </div>
            <div class="space-y-1">
              <div v-for="p in alert.products" :key="p.name"
                class="flex items-center justify-between text-xs bg-red-50 rounded px-2 py-1">
                <span class="text-gray-700">{{ p.name }}</span>
                <span class="text-red-700 font-semibold">Vượt {{ p.over_quantity }} đơn vị</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Accounting alerts -->
      <div v-if="accountingAlerts.has_alerts" class="bg-white rounded-xl border border-amber-300 overflow-hidden">
        <div class="px-5 py-4 border-b border-amber-200 flex items-center gap-2 bg-amber-50">
          <svg class="w-5 h-5 text-amber-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
          </svg>
          <h3 class="text-base font-semibold text-amber-800">Cảnh báo kế toán</h3>
          <span class="text-xs text-amber-600 ml-auto">Cần xử lý để đảm bảo số liệu chính xác</span>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-y divide-amber-100">
          <!-- Overdue invoices -->
          <div class="px-5 py-4">
            <p class="text-xs text-gray-500 mb-1">HĐ quá hạn</p>
            <p class="text-2xl font-bold" :class="accountingAlerts.overdue_invoices > 0 ? 'text-red-600' : 'text-gray-400'">
              {{ accountingAlerts.overdue_invoices }}
            </p>
            <p class="text-xs text-gray-500 mt-1">{{ fmtVnd(accountingAlerts.overdue_amount) }} chưa thu</p>
            <a href="/accounting/invoices?status=overdue" class="text-xs text-primary-600 hover:underline mt-1 block">Xem chi tiết →</a>
          </div>
          <!-- Invoices sent but not yet marked overdue -->
          <div class="px-5 py-4">
            <p class="text-xs text-gray-500 mb-1">HĐ sắp quá hạn chưa cập nhật</p>
            <p class="text-2xl font-bold" :class="accountingAlerts.pending_overdue_invoices > 0 ? 'text-orange-500' : 'text-gray-400'">
              {{ accountingAlerts.pending_overdue_invoices }}
            </p>
            <p class="text-xs text-gray-500 mt-1">Đã qua due_date, cần chuyển Overdue</p>
            <a href="/accounting/invoices?status=sent" class="text-xs text-primary-600 hover:underline mt-1 block">Xem →</a>
          </div>
          <!-- Unreconciled bank -->
          <div class="px-5 py-4">
            <p class="text-xs text-gray-500 mb-1">GD ngân hàng chưa đối chiếu</p>
            <p class="text-2xl font-bold" :class="accountingAlerts.unreconciled_bank > 0 ? 'text-blue-600' : 'text-gray-400'">
              {{ accountingAlerts.unreconciled_bank }}
            </p>
            <p class="text-xs text-gray-500 mt-1">Cần ghép với bút toán TK 112</p>
            <a href="/accounting/bank-accounts" class="text-xs text-primary-600 hover:underline mt-1 block">Đối chiếu →</a>
          </div>
          <!-- Pending payrolls -->
          <div class="px-5 py-4">
            <p class="text-xs text-gray-500 mb-1">Bảng lương chưa xác nhận</p>
            <p class="text-2xl font-bold" :class="accountingAlerts.pending_payrolls > 0 ? 'text-purple-600' : 'text-gray-400'">
              {{ accountingAlerts.pending_payrolls }}
            </p>
            <p class="text-xs text-gray-500 mt-1">Cần xác nhận để hạch toán lương</p>
            <a href="/accounting/payrolls" class="text-xs text-primary-600 hover:underline mt-1 block">Xem bảng lương →</a>
          </div>
        </div>
      </div>

      <!-- Unfulfilled orders warning -->
      <div v-if="unfulfilledOrders.length" class="bg-white rounded-xl border border-orange-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-orange-100 flex items-center gap-2">
          <svg class="w-5 h-5 text-orange-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
          </svg>
          <h3 class="text-base font-semibold text-orange-800">Đơn hàng chưa giao đủ ({{ unfulfilledOrders.length }})</h3>
        </div>
        <div class="divide-y divide-gray-100">
          <div v-for="order in unfulfilledOrders" :key="order.id" class="px-5 py-3">
            <div class="flex items-center justify-between mb-1.5">
              <div class="flex items-center gap-2">
                <span class="font-semibold text-gray-900 text-sm">{{ order.code }}</span>
                <span class="text-xs px-1.5 py-0.5 rounded-full font-medium"
                  :class="{
                    'bg-gray-100 text-gray-700': order.status === 'pending',
                    'bg-blue-100 text-blue-700': order.status === 'processing',
                    'bg-orange-100 text-orange-700': order.status === 'partial_delivered',
                    'bg-green-100 text-green-700': order.status === 'completed',
                  }">
                  {{ order.status_label }}
                </span>
              </div>
              <span class="text-xs text-gray-500">{{ order.customer }}</span>
            </div>
            <div class="space-y-1">
              <div v-for="item in order.items" :key="item.product_name"
                class="flex items-center justify-between text-xs">
                <span class="text-gray-700">{{ item.product_name }}</span>
                <div class="flex items-center gap-3 text-right">
                  <span class="text-gray-500">Cần giao: <strong>{{ item.remaining }}</strong></span>
                  <span class="text-gray-500">Tồn kho:
                    <strong :class="item.shortage > 0 ? 'text-red-600' : 'text-green-600'">{{ item.stock }}</strong>
                  </span>
                  <span v-if="item.shortage > 0" class="text-red-600 font-semibold">Thiếu {{ item.shortage }}</span>
                  <span v-else class="text-green-600 font-semibold">Đủ hàng, chưa xuất</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Charts row 2 -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <!-- Ticket status donut -->
        <div class="bg-white rounded-xl border border-gray-200 p-5">
          <h3 class="text-base font-semibold text-gray-900 mb-4">Ticket theo trạng thái</h3>
          <div class="flex items-center gap-6">
            <div class="h-48 w-48 flex-shrink-0">
              <Doughnut :data="ticketDonutData" :options="donutOptions" />
            </div>
            <div class="space-y-2">
              <div v-for="t in ticketStats" :key="t.status" class="flex items-center gap-2 text-sm">
                <span class="w-3 h-3 rounded-full flex-shrink-0" :style="{ backgroundColor: ticketColorMap[t.status] }"></span>
                <span class="text-gray-700">{{ t.label }}</span>
                <span class="ml-auto font-semibold text-gray-900">{{ t.count }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Stock overview table -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-base font-semibold text-gray-900">Tồn kho sản phẩm</h3>
            <div class="flex items-center gap-3 text-xs text-gray-500">
              <span class="flex items-center gap-1">
                <span class="w-2 h-2 rounded-full bg-red-500 inline-block"></span>Dưới mức tối thiểu
              </span>
              <span class="flex items-center gap-1">
                <span class="w-2 h-2 rounded-full bg-green-500 inline-block"></span>Bình thường
              </span>
            </div>
          </div>
          <div v-if="stockOverview.length" class="overflow-y-auto max-h-72">
            <table class="w-full text-sm">
              <thead class="bg-gray-50 sticky top-0">
                <tr>
                  <th class="text-left px-5 py-2.5 font-semibold text-gray-600 text-xs">Sản phẩm</th>
                  <th class="text-right px-5 py-2.5 font-semibold text-gray-600 text-xs">Tồn kho</th>
                  <th class="text-right px-5 py-2.5 font-semibold text-gray-600 text-xs">Tối thiểu</th>
                  <th class="text-center px-5 py-2.5 font-semibold text-gray-600 text-xs">Trạng thái</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="p in stockOverview" :key="p.id"
                  :class="isLowStock(p) ? 'bg-red-50' : 'hover:bg-gray-50'">
                  <td class="px-5 py-2.5">
                    <div class="font-medium text-gray-900">{{ p.name }}</div>
                    <div class="text-xs text-gray-400">{{ p.code }}</div>
                  </td>
                  <td class="px-5 py-2.5 text-right font-bold"
                    :class="isLowStock(p) ? 'text-red-600' : 'text-gray-800'">
                    {{ p.stock }} <span class="font-normal text-gray-400 text-xs">{{ p.unit }}</span>
                  </td>
                  <td class="px-5 py-2.5 text-right text-gray-500 text-xs">
                    {{ p.min_stock > 0 ? p.min_stock : '—' }}
                  </td>
                  <td class="px-5 py-2.5 text-center">
                    <span v-if="isLowStock(p)"
                      class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">
                      Thiếu {{ p.min_stock - p.stock }}
                    </span>
                    <span v-else-if="p.stock === 0"
                      class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">
                      Hết hàng
                    </span>
                    <span v-else
                      class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                      {{ p.stock }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="px-5 py-10 text-center text-gray-400 text-sm">
            Chưa có sản phẩm nào
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, h } from 'vue';
import { Bar, Doughnut } from 'vue-chartjs';
import {
  Chart as ChartJS,
  Title, Tooltip, Legend,
  BarElement, CategoryScale, LinearScale,
  ArcElement,
} from 'chart.js';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { formatVnd } from '@/composables/useCurrency';

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale, ArcElement);

const props = defineProps({
  stats:             { type: Object, default: () => ({}) },
  revenueChart:      { type: Array,  default: () => [] },
  topCustomers:      { type: Array,  default: () => [] },
  stockOverview:     { type: Array,  default: () => [] },
  ticketStats:       { type: Array,  default: () => [] },
  unfulfilledOrders:   { type: Array,  default: () => [] },
  overDeliveryAlerts:  { type: Array,  default: () => [] },
  accountingAlerts:    { type: Object, default: () => ({}) },
});

const isLowStock = (p) => p.min_stock > 0 && p.stock <= p.min_stock;

const fmtVnd = (v) => new Intl.NumberFormat('vi-VN').format(v || 0) + ' ₫';

// ----- KPI card -----
const KpiCard = {
  props: ['title', 'value', 'color'],
  setup(props) {
    return () => h('div', { class: 'bg-white rounded-xl border border-gray-200 p-5 flex items-center gap-4' }, [
      h('div', { class: [props.color, 'w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0'] }, [
        h('span', { class: 'text-white font-bold text-lg' }, String(props.value ?? 0)),
      ]),
      h('p', { class: 'text-sm text-gray-600 font-medium' }, props.title),
    ]);
  },
};

// ----- Revenue chart -----
const revenueChartData = computed(() => ({
  labels: props.revenueChart.map(r => r.month),
  datasets: [{
    label: 'Doanh thu (đ)',
    data:  props.revenueChart.map(r => r.amount),
    backgroundColor: 'rgba(37, 99, 235, 0.7)',
    borderColor:     'rgba(37, 99, 235, 1)',
    borderWidth: 1,
    borderRadius: 4,
  }],
}));

const barOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: ctx => formatVnd(ctx.parsed.y),
      },
    },
  },
  scales: {
    y: {
      ticks: {
        callback: v => new Intl.NumberFormat('vi-VN', { notation: 'compact' }).format(v),
      },
    },
  },
};

// ----- Top customers chart -----
const topCustomersData = computed(() => ({
  labels: props.topCustomers.map(c => c.name),
  datasets: [{
    label: 'Doanh thu',
    data:  props.topCustomers.map(c => c.total),
    backgroundColor: [
      'rgba(37, 99, 235, 0.7)',
      'rgba(5, 150, 105, 0.7)',
      'rgba(217, 119, 6, 0.7)',
      'rgba(124, 58, 237, 0.7)',
      'rgba(220, 38, 38, 0.7)',
    ],
    borderRadius: 4,
  }],
}));

const horizontalBarOptions = {
  indexAxis: 'y',
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: ctx => formatVnd(ctx.parsed.x),
      },
    },
  },
  scales: {
    x: {
      ticks: {
        callback: v => new Intl.NumberFormat('vi-VN', { notation: 'compact' }).format(v),
      },
    },
  },
};

// ----- Ticket donut -----
const ticketColorMap = {
  open:        '#f59e0b',
  in_progress: '#3b82f6',
  resolved:    '#10b981',
  closed:      '#6b7280',
};

const ticketDonutData = computed(() => ({
  labels: props.ticketStats.map(t => t.label),
  datasets: [{
    data:            props.ticketStats.map(t => t.count),
    backgroundColor: props.ticketStats.map(t => ticketColorMap[t.status] ?? '#d1d5db'),
    borderWidth: 2,
    borderColor: '#fff',
  }],
}));

const donutOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
  },
  cutout: '65%',
};
</script>
