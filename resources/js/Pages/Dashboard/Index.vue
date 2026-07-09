<template>
  <AppLayout>
    <div class="space-y-6">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
        <div class="flex items-center gap-3">
          <span class="text-xs text-gray-400">Cập nhật lúc {{ loadedAtDisplay }}</span>
          <button @click="refresh" :disabled="isLoading"
            class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
            <svg class="h-3.5 w-3.5" :class="{ 'animate-spin': isLoading }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            Làm mới
          </button>
        </div>
      </div>

      <!-- KPI Cards -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <KpiCard title="Khách hàng"       :value="stats.total_customers"  color="bg-blue-500"   />
        <KpiCard title="Sản phẩm đang bán" :value="stats.total_products"   color="bg-emerald-500" />
        <KpiCard title="Ticket đang mở"   :value="stats.open_tickets"     color="bg-yellow-500" />
        <KpiCard title="Dự án đang chạy"  :value="stats.active_projects"  color="bg-purple-500" />
      </div>
      
      <!-- Thống kê giao dịch tháng này -->
      <div class="space-y-3">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">
          Giao dịch trong tháng {{ currentMonthLabel }}
        </h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
          <!-- Đơn hàng bán -->
          <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow-md transition duration-200 flex items-center justify-between">
            <div class="space-y-1">
              <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Số đơn bán</span>
              <div class="flex items-baseline gap-1.5">
                <span class="text-2xl font-bold text-gray-900">{{ stats.sales_orders_count }}</span>
                <span class="text-xs text-gray-500">đơn</span>
              </div>
            </div>
            <div class="p-2.5 rounded-xl bg-blue-50 text-blue-600">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
              </svg>
            </div>
          </div>

          <!-- Doanh số bán -->
          <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow-md transition duration-200 flex items-center justify-between">
            <div class="space-y-1">
              <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Doanh số bán</span>
              <div>
                <span class="text-xl font-bold text-gray-900">{{ fmtVnd(stats.sales_orders_total) }}</span>
              </div>
            </div>
            <div class="p-2.5 rounded-xl bg-indigo-50 text-indigo-600">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
          </div>

          <!-- Số đơn mua -->
          <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow-md transition duration-200 flex items-center justify-between">
            <div class="space-y-1">
              <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Số đơn mua</span>
              <div class="flex items-baseline gap-1.5">
                <span class="text-2xl font-bold text-gray-900">{{ stats.purchase_orders_count }}</span>
                <span class="text-xs text-gray-500">đơn</span>
              </div>
            </div>
            <div class="p-2.5 rounded-xl bg-teal-50 text-teal-600">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
              </svg>
            </div>
          </div>

          <!-- Tổng tiền mua -->
          <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow-md transition duration-200 flex items-center justify-between">
            <div class="space-y-1">
              <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Tổng tiền mua</span>
              <div>
                <span class="text-xl font-bold text-gray-900">{{ fmtVnd(stats.purchase_orders_total) }}</span>
              </div>
            </div>
            <div class="p-2.5 rounded-xl bg-rose-50 text-rose-600">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
              </svg>
          </div>
        </div>
      </div>
    </div>
      
      <!-- Thống kê lương & bảo hiểm tháng trước (chỉ dành cho Kế toán / Nhân sự) -->
      <div v-if="can('accounting.view') || can('hr.employees.view')" class="space-y-3">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">
          Nhân sự & Lương tháng {{ stats.last_month_label }}
        </h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
          <!-- Lương thực lĩnh -->
          <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow-md transition duration-200 flex items-center justify-between">
            <div class="space-y-1">
              <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Lương thực lĩnh</span>
              <div>
                <span class="text-xl font-bold text-gray-900">{{ fmtVnd(stats.last_month_salary_paid) }}</span>
              </div>
            </div>
            <div class="p-2.5 rounded-xl bg-emerald-50 text-emerald-600">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
              </svg>
            </div>
          </div>

          <!-- BH Công ty nộp -->
          <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow-md transition duration-200 flex items-center justify-between">
            <div class="space-y-1">
              <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider">BH Công ty đóng</span>
              <div>
                <span class="text-xl font-bold text-gray-900">{{ fmtVnd(stats.last_month_insurance_employer) }}</span>
              </div>
            </div>
            <div class="p-2.5 rounded-xl bg-blue-50 text-blue-600">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
              </svg>
            </div>
          </div>

          <!-- BH NLĐ đóng -->
          <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow-md transition duration-200 flex items-center justify-between">
            <div class="space-y-1">
              <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider">BH NLĐ đóng</span>
              <div>
                <span class="text-xl font-bold text-gray-900">{{ fmtVnd(stats.last_month_insurance_employee) }}</span>
              </div>
            </div>
            <div class="p-2.5 rounded-xl bg-orange-50 text-orange-600">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
              </svg>
            </div>
          </div>
        </div>
      </div>


      <!-- Financial KPI (chỉ hiển thị nếu có quyền accounting.view) -->
      <div v-if="financialKpi" class="bg-white rounded-xl border border-gray-200">
        <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
          <h3 class="text-sm font-semibold text-gray-700">
            Kết quả kinh doanh — tháng {{ financialKpi.period_label }}
          </h3>
          <a :href="`/reports/trial-balance?date_from=${financialKpi.date_from}&date_to=${financialKpi.date_to}`"
            class="text-xs text-blue-600 hover:underline">
            Xem Trial Balance →
          </a>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-gray-100">
          <!-- Doanh thu -->
          <div class="px-5 py-4">
            <p class="text-xs text-gray-500 mb-1">Doanh thu (TK 511x)</p>
            <p class="text-2xl font-bold text-blue-700">
              {{ fmtVnd(financialKpi.current.revenue) }}
            </p>
            <div class="mt-1.5 flex items-center gap-1.5 text-xs">
              <template v-if="kpiDelta(financialKpi.current.revenue, financialKpi.previous.revenue) !== null">
                <span :class="financialKpi.current.revenue >= financialKpi.previous.revenue ? 'text-green-600' : 'text-red-500'"
                  class="flex items-center gap-0.5 font-medium">
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      :d="financialKpi.current.revenue >= financialKpi.previous.revenue
                        ? 'M5 10l7-7m0 0l7 7m-7-7v18'
                        : 'M19 14l-7 7m0 0l-7-7m7 7V3'" />
                  </svg>
                  {{ Math.abs(kpiDelta(financialKpi.current.revenue, financialKpi.previous.revenue)).toFixed(1) }}%
                </span>
                <span class="text-gray-400">so tháng {{ financialKpi.prev_label }}</span>
              </template>
              <span v-else class="text-gray-400">Tháng {{ financialKpi.prev_label }}: 0</span>
            </div>
          </div>

          <!-- Giá vốn -->
          <div class="px-5 py-4">
            <p class="text-xs text-gray-500 mb-1">Giá vốn (TK 632x)</p>
            <p class="text-2xl font-bold text-orange-600">
              {{ fmtVnd(financialKpi.current.cogs) }}
            </p>
            <div class="mt-1.5 flex items-center gap-1.5 text-xs">
              <template v-if="kpiDelta(financialKpi.current.cogs, financialKpi.previous.cogs) !== null">
                <span :class="financialKpi.current.cogs <= financialKpi.previous.cogs ? 'text-green-600' : 'text-red-500'"
                  class="flex items-center gap-0.5 font-medium">
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      :d="financialKpi.current.cogs <= financialKpi.previous.cogs
                        ? 'M5 10l7-7m0 0l7 7m-7-7v18'
                        : 'M19 14l-7 7m0 0l-7-7m7 7V3'" />
                  </svg>
                  {{ Math.abs(kpiDelta(financialKpi.current.cogs, financialKpi.previous.cogs)).toFixed(1) }}%
                </span>
                <span class="text-gray-400">so tháng {{ financialKpi.prev_label }}</span>
              </template>
              <span v-else class="text-gray-400">Tháng {{ financialKpi.prev_label }}: 0</span>
            </div>
          </div>

          <!-- Lợi nhuận gộp -->
          <div class="px-5 py-4">
            <p class="text-xs text-gray-500 mb-1">Lợi nhuận gộp</p>
            <p class="text-2xl font-bold"
              :class="financialKpi.current.gross_profit >= 0 ? 'text-emerald-700' : 'text-red-600'">
              {{ fmtVnd(financialKpi.current.gross_profit) }}
            </p>
            <div class="mt-1.5 flex items-center gap-1.5 text-xs">
              <template v-if="kpiDelta(financialKpi.current.gross_profit, financialKpi.previous.gross_profit) !== null">
                <span :class="financialKpi.current.gross_profit >= financialKpi.previous.gross_profit ? 'text-green-600' : 'text-red-500'"
                  class="flex items-center gap-0.5 font-medium">
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      :d="financialKpi.current.gross_profit >= financialKpi.previous.gross_profit
                        ? 'M5 10l7-7m0 0l7 7m-7-7v18'
                        : 'M19 14l-7 7m0 0l-7-7m7 7V3'" />
                  </svg>
                  {{ Math.abs(kpiDelta(financialKpi.current.gross_profit, financialKpi.previous.gross_profit)).toFixed(1) }}%
                </span>
                <span class="text-gray-400">so tháng {{ financialKpi.prev_label }}</span>
              </template>
              <span v-else class="text-gray-400">Tháng {{ financialKpi.prev_label }}: 0</span>
            </div>
          </div>
        </div>
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
      <div v-if="overDeliveryAlerts.length" class="bg-white rounded-xl border border-red-300 overflow-x-auto">
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
      <div v-if="accountingAlerts.has_alerts" class="bg-white rounded-xl border border-amber-300 overflow-x-auto">
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
      <div v-if="unfulfilledOrders.length" class="bg-white rounded-xl border border-orange-200 overflow-x-auto">
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
        <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
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
            <table class="min-w-full text-sm">
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
import { computed, h, ref, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
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
  financialKpi:        { type: Object, default: null },
});

const isLowStock = (p) => p.min_stock > 0 && p.stock <= p.min_stock;

const isLoading = ref(false);
const loadedAt  = ref(new Date());

const removeStart  = router.on('start',  () => { isLoading.value = true; });
const removeFinish = router.on('finish', () => {
  isLoading.value = false;
  loadedAt.value  = new Date();
});
onUnmounted(() => { removeStart(); removeFinish(); });

const loadedAtDisplay = computed(() =>
  loadedAt.value.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' })
);

const currentMonthLabel = computed(() => {
  const date = new Date();
  return (date.getMonth() + 1) + '/' + date.getFullYear();
});

function refresh() {
  router.reload({ preserveScroll: true });
}

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

// ----- Financial KPI helpers -----
function kpiDelta(current, previous) {
  if (!previous || previous === 0) return null;
  return ((current - previous) / Math.abs(previous)) * 100;
}

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
