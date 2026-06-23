<template>
  <AppLayout>
    <div class="space-y-5">
      <!-- Header -->
      <div class="erp-page-header">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Bảng cân đối kế toán</h1>
          <p class="text-sm text-gray-500 mt-0.5">
            {{ reportMeta?.report_name }} — Mẫu {{ reportMeta?.report_code }}
            ({{ reportMeta?.circular }})
          </p>
        </div>
        <div class="flex gap-2 flex-wrap">
          <a :href="exportUrl" class="erp-btn-secondary flex items-center gap-1.5 text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
            Excel
          </a>
          <a :href="exportPdfUrl" target="_blank" class="erp-btn-secondary flex items-center gap-1.5 text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
            </svg>
            PDF
          </a>
          <button onclick="window.print()" class="erp-btn-secondary flex items-center gap-1.5 text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
            </svg>
            In
          </button>
        </div>
      </div>

      <!-- Filter + Mode toggle -->
      <div class="flex gap-3 items-center flex-wrap bg-white rounded-xl border border-gray-200 px-4 py-3">
        <!-- Mode toggle -->
        <div class="flex items-center gap-1 bg-gray-100 rounded-lg p-1">
          <button @click="setMode('management')"
            class="px-3 py-1.5 text-xs font-medium rounded-md transition-all"
            :class="mode === 'management'
              ? 'bg-white shadow text-primary-700'
              : 'text-gray-600 hover:text-gray-800'">
            Quản trị
          </button>
          <button @click="setMode('official')"
            class="px-3 py-1.5 text-xs font-medium rounded-md transition-all"
            :class="mode === 'official'
              ? 'bg-white shadow text-primary-700'
              : 'text-gray-600 hover:text-gray-800'">
            BCTC chính thức
          </button>
        </div>

        <div class="flex items-center gap-2">
          <label class="text-sm text-gray-600 font-medium">Tại ngày:</label>
          <input v-model="asOf" type="date"
            class="erp-input text-sm w-40" />
        </div>
        <button @click="applyFilters" :disabled="isLoading"
          class="erp-btn-primary text-sm flex items-center gap-2">
          <svg v-if="isLoading" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          Tính lại
        </button>
      </div>

      <!-- Management mode info banner -->
      <div v-if="reportMode === 'management' && provisionalPnl !== null"
        class="bg-blue-50 border border-blue-300 rounded-lg px-4 py-3 flex items-start gap-2">
        <svg class="w-4 h-4 text-blue-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <div>
          <p class="text-sm font-semibold text-blue-800">Chế độ quản trị — lãi/lỗ tạm tính</p>
          <p class="text-xs text-blue-700 mt-0.5">
            TK doanh thu/chi phí chưa kết chuyển ({{ unclosedIncomeExpense.join(', ') }}).
            Lãi/lỗ tạm tính <strong>{{ fmt(provisionalPnl) }}</strong> đã được cộng vào mã 417 để B01a cân.
          </p>
        </div>
      </div>

      <!-- Official mode warning -->
      <div v-if="reportMode === 'official' && unclosedIncomeExpense?.length"
        class="bg-amber-50 border border-amber-300 rounded-lg px-4 py-3 flex items-start gap-2">
        <svg class="w-4 h-4 text-amber-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
        </svg>
        <p class="text-sm text-amber-800">
          Các TK {{ unclosedIncomeExpense.join(', ') }} còn số dư chưa kết chuyển.
          Vui lòng chạy kết chuyển kỳ hoặc chuyển sang chế độ Quản trị.
        </p>
      </div>

      <!-- Warnings -->
      <div v-if="warnings?.length" class="space-y-2">
        <div v-for="(w, i) in warnings" :key="i"
          class="bg-yellow-50 border border-yellow-300 rounded-lg px-4 py-3 text-sm text-yellow-800">
          {{ w }}
        </div>
      </div>

      <!-- Balance status -->
      <div v-if="!summary.balanced"
        class="bg-red-50 border border-red-300 rounded-lg px-4 py-3 text-sm text-red-800 flex items-center gap-2">
        <svg class="w-4 h-4 text-red-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
        </svg>
        <span>
          <strong>Báo cáo chưa cân — Mã 200 ≠ Mã 500.</strong>
          Tổng tài sản {{ fmt(summary.total_assets) }} / Tổng nguồn vốn {{ fmt(summary.total_liabilities_equity) }}
          (lệch {{ fmt(Math.abs(summary.difference)) }}).
        </span>
      </div>
      <div v-else class="bg-green-50 border border-green-200 rounded-lg px-4 py-2.5 text-sm text-green-700 flex items-center gap-2">
        <svg class="w-4 h-4 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
        Báo cáo đã cân — Tổng tài sản = Tổng nguồn vốn = {{ fmt(summary.total_assets) }}
      </div>

      <!-- Tabs -->
      <div class="flex gap-1 border-b border-gray-200 overflow-x-auto print:hidden">
        <button v-for="tab in tabs" :key="tab.id" @click="activeTab = tab.id"
          class="px-4 py-2 text-sm font-medium rounded-t-lg transition-colors whitespace-nowrap"
          :class="activeTab === tab.id
            ? 'bg-white border border-b-white border-gray-200 text-primary-700 -mb-px'
            : 'text-gray-500 hover:text-gray-700'">
          {{ tab.label }}
          <span v-if="tab.id === 'unmapped' && unmappedAccounts?.length"
            class="ml-1.5 inline-flex items-center justify-center w-5 h-5 rounded-full bg-orange-500 text-white text-xs font-bold">
            {{ unmappedAccounts.length }}
          </span>
        </button>
      </div>

      <!-- Tab: B01a-DNN (TT133 format — bảng dọc 5 cột) -->
      <div v-show="activeTab === 'report'" class="transition-opacity print:block" :class="{ 'opacity-60': isLoading }">
        <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto print:border-0 print:rounded-none">
          <!-- Print header -->
          <div class="hidden print:block px-6 pt-5 pb-2">
            <div class="flex justify-between items-start">
              <div class="text-sm">
                <div class="font-bold text-base">{{ company?.company_name }}</div>
                <div class="text-gray-500">Địa chỉ: {{ company?.company_address }}</div>
              </div>
              <div class="text-right text-xs italic text-gray-500">
                <div class="font-bold not-italic text-sm">Mẫu số B01a-DNN</div>
                <div>(Ban hành theo Thông tư số 133/2016/TT-BTC</div>
                <div>ngày 26/8/2016 của Bộ Tài chính)</div>
              </div>
            </div>
            <h2 class="text-center text-xl font-bold uppercase tracking-wide mt-4 mb-1">
              Bảng cân đối kế toán
            </h2>
            <p class="text-center text-sm italic mb-1">Tại ngày {{ asOf }}</p>
            <p class="text-right text-xs italic">Đơn vị tính: Đồng Việt Nam</p>
          </div>

          <!-- Screen section header -->
          <div class="print:hidden bg-gray-50 border-b border-gray-200 px-5 py-3 flex items-center justify-between">
            <h2 class="font-semibold text-gray-800">B01a-DNN — Tại ngày {{ asOf }}</h2>
            <span class="text-xs text-gray-400">Đơn vị: Đồng</span>
          </div>

          <table class="min-w-full text-sm">
            <thead>
              <tr class="bg-slate-700 text-white">
                <th class="text-left px-4 py-2.5 font-medium text-xs w-[44%]">CHỈ TIÊU</th>
                <th class="text-center px-2 py-2.5 font-medium text-xs w-[8%]">Mã số</th>
                <th class="text-center px-2 py-2.5 font-medium text-xs w-[8%]">Thuyết minh</th>
                <th class="text-right px-4 py-2.5 font-medium text-xs w-[20%]">Số cuối năm</th>
                <th class="text-right px-4 py-2.5 font-medium text-xs w-[20%]">Số đầu năm</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <!-- TÀI SẢN section header -->
              <tr class="bg-blue-50 border-y border-blue-200">
                <td colspan="5" class="px-4 py-2 text-sm font-bold text-blue-900">PHẦN I — TÀI SẢN</td>
              </tr>

              <template v-for="(row, i) in assetRows" :key="'a' + i">
                <tr :class="rowClass(row, 'blue')">
                  <td class="py-2 text-gray-800"
                    :class="[
                      row.level === 2 ? 'pl-8 pr-3' : 'pl-4 pr-3',
                      (row.is_total || (row.level === 1 && row.is_formula)) ? 'font-semibold text-gray-900' : ''
                    ]">
                    {{ row.item_name }}
                  </td>
                  <td class="px-2 py-2 text-center text-xs font-mono"
                    :class="row.is_total ? 'font-bold text-blue-700' : 'text-gray-400'">
                    {{ row.item_code ?? '' }}
                  </td>
                  <td class="px-2 py-2 text-center text-xs text-gray-400">{{ row.thuyetminh ?? '' }}</td>
                  <td class="px-4 py-2 text-right font-medium"
                    :class="amtClass(row.amount, row.is_total, 'blue')">
                    {{ row.amount !== 0 || row.is_total ? fmt(row.amount) : '—' }}
                  </td>
                  <td class="px-4 py-2 text-right text-gray-400"
                    :class="row.is_total ? 'font-bold' : ''">
                    {{ (row.prior_amount ?? 0) !== 0 || row.is_total ? fmt(row.prior_amount ?? 0) : '—' }}
                  </td>
                </tr>
              </template>

              <!-- NGUỒN VỐN section header -->
              <tr class="bg-green-50 border-y border-green-200">
                <td colspan="5" class="px-4 py-2 text-sm font-bold text-green-900">PHẦN II — NGUỒN VỐN</td>
              </tr>

              <template v-for="(row, i) in sourceRows" :key="'s' + i">
                <tr :class="rowClass(row, 'green')">
                  <td class="py-2 text-gray-800"
                    :class="[
                      row.level === 2 && !row.is_section_header ? 'pl-8 pr-3' : 'pl-4 pr-3',
                      (row.is_total || row.is_section_header || (row.level === 1 && row.is_formula))
                        ? 'font-semibold text-gray-900' : ''
                    ]">
                    {{ row.item_name }}
                  </td>
                  <td class="px-2 py-2 text-center text-xs font-mono"
                    :class="row.is_total || row.is_section_header ? 'font-bold text-green-700' : 'text-gray-400'">
                    {{ row.item_code ?? '' }}
                  </td>
                  <td class="px-2 py-2 text-center text-xs text-gray-400">{{ row.thuyetminh ?? '' }}</td>
                  <td class="px-4 py-2 text-right font-medium"
                    :class="amtClass(row.amount, row.is_total || row.is_section_header, 'green')">
                    {{ row.amount !== 0 || row.is_total || row.is_section_header ? fmt(row.amount) : '—' }}
                  </td>
                  <td class="px-4 py-2 text-right text-gray-400"
                    :class="row.is_total || row.is_section_header ? 'font-bold' : ''">
                    {{ (row.prior_amount ?? 0) !== 0 || row.is_total || row.is_section_header
                        ? fmt(row.prior_amount ?? 0) : '—' }}
                  </td>
                </tr>
              </template>
            </tbody>
          </table>

          <!-- Signature block -->
          <div class="px-6 py-6 border-t border-gray-200 print:mt-8">
            <p class="text-sm italic text-right mb-6 text-gray-500">
              Lập, ngày &nbsp;&nbsp;&nbsp; tháng &nbsp;&nbsp;&nbsp; năm {{ asOf?.slice(0, 4) }}
            </p>
            <div class="grid grid-cols-3 gap-4 text-center">
              <div>
                <p class="font-semibold text-xs uppercase">Người lập biểu</p>
                <p class="text-xs text-gray-500 italic">(Ký, họ tên)</p>
                <p class="mt-16 text-sm">&nbsp;</p>
              </div>
              <div>
                <p class="font-semibold text-xs uppercase">Kế toán trưởng</p>
                <p class="text-xs text-gray-500 italic">(Ký, họ tên)</p>
                <p class="mt-16 text-sm">&nbsp;</p>
              </div>
              <div>
                <p class="font-semibold text-xs uppercase">Người đại diện theo pháp luật</p>
                <p class="text-xs text-gray-500 italic">(Ký, họ tên, đóng dấu)</p>
                <p class="mt-16 text-sm">&nbsp;</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Tab: TK chưa map -->
      <div v-show="activeTab === 'unmapped'" class="bg-white rounded-xl border border-gray-200 overflow-x-auto print:hidden">
        <div class="px-5 py-4 border-b border-gray-200 bg-orange-50 flex items-start justify-between gap-3">
          <div>
            <h2 class="font-semibold text-orange-800">Tài khoản có số dư nhưng chưa map vào B01a-DNN</h2>
            <p class="text-xs text-orange-600 mt-0.5">
              Nhấn "Map ngay" để chỉ định chỉ tiêu báo cáo.
            </p>
          </div>
        </div>
        <div v-if="!canManageAccounting && unmappedAccounts?.length"
          class="mx-5 mt-3 mb-1 rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-xs text-gray-500">
          Bạn không có quyền chỉnh mapping. Liên hệ kế toán trưởng (quyền <code>accounting.manage</code>).
        </div>
        <div v-if="!unmappedAccounts?.length" class="px-5 py-8 text-center text-green-600 text-sm">
          <svg class="w-8 h-8 mx-auto mb-2 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          Tất cả tài khoản có số dư đã được map.
        </div>
        <table v-else class="w-full text-sm">
          <thead class="border-b border-gray-200 bg-gray-50">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã TK</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tên tài khoản</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Số dư</th>
              <th v-if="canManageAccounting" class="px-5 py-3 font-semibold text-gray-600 text-center w-28">Thao tác</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="acc in unmappedAccounts" :key="acc.code" class="hover:bg-orange-50">
              <td class="px-5 py-3 font-mono font-semibold text-orange-700">{{ acc.code }}</td>
              <td class="px-5 py-3 text-gray-700">{{ acc.name }}</td>
              <td class="px-5 py-3 text-right" :class="acc.balance < 0 ? 'text-red-600 font-semibold' : 'text-gray-900 font-medium'">
                {{ fmt(acc.balance) }}
              </td>
              <td v-if="canManageAccounting" class="px-5 py-3 text-center">
                <button @click="openMapModal(acc)"
                  class="inline-flex items-center gap-1 text-xs bg-orange-100 hover:bg-orange-200 text-orange-800 px-3 py-1.5 rounded-lg font-medium transition-colors">
                  Map ngay
                </button>
              </td>
            </tr>
          </tbody>
          <tfoot class="border-t-2 border-gray-300 bg-orange-50">
            <tr>
              <td :colspan="canManageAccounting ? 2 : 3" class="px-5 py-3 font-semibold text-gray-700">Tổng giá trị chưa map</td>
              <td class="px-5 py-3 text-right font-bold text-orange-700">{{ fmt(unmappedTotal) }}</td>
              <td v-if="canManageAccounting"></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Tab: Kiểm tra cân đối -->
      <div v-show="activeTab === 'check'" class="space-y-4 print:hidden">
        <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
          <div class="px-5 py-4 border-b border-gray-200 bg-gray-50">
            <h2 class="font-semibold text-gray-800">Kiểm tra cân đối</h2>
          </div>
          <table class="min-w-full text-sm">
            <tbody class="divide-y divide-gray-100">
              <tr class="hover:bg-gray-50">
                <td class="px-5 py-3 text-gray-600">Tổng tài sản (Mã 200)</td>
                <td class="px-5 py-3 text-right font-semibold">{{ fmt(summary.total_assets) }}</td>
                <td class="w-8"></td>
              </tr>
              <tr class="hover:bg-gray-50">
                <td class="px-5 py-3 text-gray-600">Nợ phải trả (Mã 300)</td>
                <td class="px-5 py-3 text-right font-semibold">{{ fmt(summary.total_liabilities) }}</td>
                <td></td>
              </tr>
              <tr class="hover:bg-gray-50">
                <td class="px-5 py-3 text-gray-600">Vốn chủ sở hữu (Mã 400)</td>
                <td class="px-5 py-3 text-right font-semibold">{{ fmt(summary.total_equity) }}</td>
                <td></td>
              </tr>
              <tr class="hover:bg-gray-50">
                <td class="px-5 py-3 text-gray-600">Tổng nguồn vốn (Mã 500)</td>
                <td class="px-5 py-3 text-right font-semibold">{{ fmt(summary.total_liabilities_equity) }}</td>
                <td></td>
              </tr>
              <tr class="bg-gray-50 border-t-2 border-gray-300">
                <td class="px-5 py-3 font-bold text-gray-800">Chênh lệch (200 − 500)</td>
                <td class="px-5 py-3 text-right font-bold text-xl"
                  :class="summary.balanced ? 'text-green-600' : 'text-red-600'">
                  {{ fmt(summary.difference) }}
                </td>
                <td class="px-5 py-3">
                  <svg v-if="summary.balanced" class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                  </svg>
                  <svg v-else class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="trialBalance" class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
          <div class="px-5 py-4 border-b border-gray-200 bg-gray-50">
            <h2 class="font-semibold text-gray-800">Trạng thái Trial Balance</h2>
          </div>
          <table class="min-w-full text-sm">
            <tbody class="divide-y divide-gray-100">
              <tr class="hover:bg-gray-50">
                <td class="px-5 py-3 text-gray-600">Tổng phát sinh Nợ</td>
                <td class="px-5 py-3 text-right font-semibold">{{ fmt(trialBalance.total_debit) }}</td>
              </tr>
              <tr class="hover:bg-gray-50">
                <td class="px-5 py-3 text-gray-600">Tổng phát sinh Có</td>
                <td class="px-5 py-3 text-right font-semibold">{{ fmt(trialBalance.total_credit) }}</td>
              </tr>
              <tr class="bg-gray-50 border-t-2 border-gray-300">
                <td class="px-5 py-3 font-bold text-gray-800">Chênh lệch</td>
                <td class="px-5 py-3 text-right font-bold"
                  :class="trialBalance.balanced ? 'text-green-600' : 'text-red-600'">
                  {{ fmt(Math.abs(trialBalance.difference)) }}
                  <span class="ml-1 text-xs">{{ trialBalance.balanced ? '✓ Cân' : '⚠ Lệch' }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="unmappedAccounts?.length"
          class="bg-orange-50 border border-orange-200 rounded-xl px-5 py-4 text-sm text-orange-800">
          <p class="font-semibold">⚠ {{ unmappedAccounts.length }} tài khoản chưa được map vào báo cáo</p>
          <p class="mt-1 text-orange-600">
            Tổng giá trị chưa phản ánh: <strong>{{ fmt(unmappedTotal) }}</strong>
          </p>
        </div>
      </div>

      <!-- Tab: Đối soát GL -->
      <div v-show="activeTab === 'gl'" class="space-y-4 print:hidden">
        <div class="flex items-center justify-between flex-wrap gap-y-3">
          <p class="text-xs text-gray-500">Nhấn vào dòng chỉ tiêu để xem danh sách tài khoản GL đóng góp.</p>
          <div class="flex gap-2">
            <button @click="expandAllGl" class="text-xs text-primary-600 hover:text-primary-800 underline">Mở tất cả</button>
            <button @click="collapseAllGl" class="text-xs text-gray-500 hover:text-gray-700 underline">Thu tất cả</button>
          </div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
          <div class="bg-blue-50 border-b border-gray-200 px-5 py-3">
            <h2 class="font-semibold text-blue-800">PHẦN I — TÀI SẢN</h2>
          </div>
          <table class="min-w-full text-sm">
            <thead class="border-b border-gray-100 bg-gray-50 text-xs text-gray-500">
              <tr>
                <th class="w-6 px-2 py-2"></th>
                <th class="w-14 text-center px-3 py-2 font-medium">Mã</th>
                <th class="text-left px-3 py-2 font-medium">Chỉ tiêu / Tài khoản</th>
                <th class="text-right px-3 py-2 font-medium">Số dư</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="item in glAssets" :key="item.item_code">
                <tr class="border-b border-gray-100 cursor-pointer select-none hover:bg-blue-50"
                    @click="toggleGl(item.item_code)">
                  <td class="px-2 py-2.5 text-center text-gray-400">
                    <svg class="w-3.5 h-3.5 inline transition-transform duration-150"
                         :class="expandedGl[item.item_code] ? 'rotate-90' : ''"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                  </td>
                  <td class="px-3 py-2.5 text-center font-mono text-xs font-semibold text-blue-700">{{ item.item_code }}</td>
                  <td class="px-3 py-2.5 font-medium text-gray-800">{{ item.item_name }}</td>
                  <td class="px-3 py-2.5 text-right font-semibold"
                      :class="item.total < 0 ? 'text-red-600' : 'text-gray-900'">
                    {{ item.total !== 0 ? fmt(item.total) : '—' }}
                  </td>
                </tr>
                <template v-if="expandedGl[item.item_code]">
                  <tr v-for="acc in item.accounts" :key="acc.code"
                      class="border-b border-gray-50"
                      :class="Math.abs(acc.balance) < 1 ? 'bg-gray-50 opacity-50' : 'bg-blue-50/30'">
                    <td class="px-2 py-1.5"></td>
                    <td class="px-3 py-1.5 text-center font-mono text-xs text-gray-500">{{ acc.code }}</td>
                    <td class="pl-8 pr-3 py-1.5 text-xs text-gray-600">{{ acc.name }}</td>
                    <td class="px-3 py-1.5 text-right font-mono text-xs"
                        :class="Math.abs(acc.balance) >= 1 ? 'text-gray-900 font-semibold' : 'text-gray-400'">
                      {{ Math.abs(acc.balance) >= 1 ? fmt(acc.balance) : '—' }}
                    </td>
                  </tr>
                </template>
              </template>
            </tbody>
          </table>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
          <div class="bg-green-50 border-b border-gray-200 px-5 py-3">
            <h2 class="font-semibold text-green-800">PHẦN II — NGUỒN VỐN</h2>
          </div>
          <table class="min-w-full text-sm">
            <thead class="border-b border-gray-100 bg-gray-50 text-xs text-gray-500">
              <tr>
                <th class="w-6 px-2 py-2"></th>
                <th class="w-14 text-center px-3 py-2 font-medium">Mã</th>
                <th class="text-left px-3 py-2 font-medium">Chỉ tiêu / Tài khoản</th>
                <th class="text-right px-3 py-2 font-medium">Số dư</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="item in glSources" :key="item.item_code">
                <tr class="border-b border-gray-100 cursor-pointer select-none hover:bg-green-50"
                    @click="toggleGl(item.item_code)">
                  <td class="px-2 py-2.5 text-center text-gray-400">
                    <svg class="w-3.5 h-3.5 inline transition-transform duration-150"
                         :class="expandedGl[item.item_code] ? 'rotate-90' : ''"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                  </td>
                  <td class="px-3 py-2.5 text-center font-mono text-xs font-semibold text-green-700">{{ item.item_code }}</td>
                  <td class="px-3 py-2.5 font-medium text-gray-800">{{ item.item_name }}</td>
                  <td class="px-3 py-2.5 text-right font-semibold"
                      :class="item.total < 0 ? 'text-red-600' : 'text-gray-900'">
                    {{ item.total !== 0 ? fmt(item.total) : '—' }}
                  </td>
                </tr>
                <template v-if="expandedGl[item.item_code]">
                  <tr v-for="acc in item.accounts" :key="acc.code"
                      class="border-b border-gray-50"
                      :class="Math.abs(acc.balance) < 1 ? 'bg-gray-50 opacity-50' : 'bg-green-50/30'">
                    <td class="px-2 py-1.5"></td>
                    <td class="px-3 py-1.5 text-center font-mono text-xs text-gray-500">{{ acc.code }}</td>
                    <td class="pl-8 pr-3 py-1.5 text-xs text-gray-600">{{ acc.name }}</td>
                    <td class="px-3 py-1.5 text-right font-mono text-xs"
                        :class="Math.abs(acc.balance) >= 1 ? 'text-gray-900 font-semibold' : 'text-gray-400'">
                      {{ Math.abs(acc.balance) >= 1 ? fmt(acc.balance) : '—' }}
                    </td>
                  </tr>
                </template>
              </template>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Modal Map ngay -->
    <Modal :show="showMapModal" max-width="md" @close="showMapModal = false">
      <template #title>Map tài khoản vào chỉ tiêu B01a-DNN</template>

      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Tài khoản</label>
          <div class="flex items-center gap-2 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2">
            <span class="font-mono font-semibold text-orange-700">{{ mapForm.account_code }}</span>
            <span class="text-gray-500 text-sm">—</span>
            <span class="text-gray-700 text-sm">{{ mapForm.account_name }}</span>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Chỉ tiêu B01a-DNN <span class="text-red-500">*</span></label>
          <select v-model="mapForm.item_code" class="erp-input text-sm">
            <option value="">-- Chọn chỉ tiêu --</option>
            <optgroup label="PHẦN I — TÀI SẢN">
              <option v-for="item in assetItems" :key="item.item_code" :value="item.item_code">
                {{ item.item_code }} — {{ item.item_name }}
              </option>
            </optgroup>
            <optgroup label="PHẦN II — NGUỒN VỐN">
              <option v-for="item in equityItems" :key="item.item_code" :value="item.item_code">
                {{ item.item_code }} — {{ item.item_name }}
              </option>
            </optgroup>
          </select>
        </div>

        <div v-if="mapError" class="bg-red-50 border border-red-200 rounded-lg px-3 py-2 text-sm text-red-700">
          {{ mapError }}
        </div>
      </div>

      <template #footer>
        <button @click="showMapModal = false" class="erp-btn-secondary">Hủy</button>
        <button @click="submitMapping" :disabled="!mapForm.item_code || mapSaving" class="erp-btn-primary flex items-center gap-2">
          <svg v-if="mapSaving" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          Lưu mapping
        </button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import Modal from '@/Components/Shared/Modal.vue';
import { useCurrency } from '@/composables/useCurrency';
import { useInertiaLoading } from '@/composables/useInertiaLoading';

const props = defineProps({
  balanceSheet:           Array,
  summary:                Object,
  warnings:               Array,
  trialBalance:           Object,
  unmappedAccounts:       { type: Array,   default: () => [] },
  reportMeta:             Object,
  reportItems:            { type: Array,   default: () => [] },
  company:                { type: Object,  default: () => ({}) },
  canManageAccounting:    { type: Boolean, default: false },
  filters:                Object,
  reportMode:             { type: String,  default: 'management' },
  provisionalPnl:         { type: Number,  default: null },
  unclosedIncomeExpense:  { type: Array,   default: () => [] },
  glBreakdown:            { type: Array,   default: () => [] },
});

const { formatVnd: fmt } = useCurrency();
const { isLoading }      = useInertiaLoading();

const asOf      = ref(props.filters?.as_of ?? new Date().toISOString().slice(0, 10));
const mode      = ref(props.filters?.mode ?? 'management');
const activeTab = ref('report');

const tabs = computed(() => [
  { id: 'report',   label: 'B01a-DNN (TT133)' },
  { id: 'unmapped', label: 'TK chưa map' },
  { id: 'check',    label: 'Kiểm tra cân đối' },
  { id: 'gl',       label: 'Đối soát GL' },
]);

// GL accordion
const expandedGl = reactive({});
function toggleGl(code) { expandedGl[code] = !expandedGl[code]; }
function expandAllGl()  { props.glBreakdown.forEach(i => { expandedGl[i.item_code] = true; }); }
function collapseAllGl() { props.glBreakdown.forEach(i => { expandedGl[i.item_code] = false; }); }
const glAssets  = computed(() => (props.glBreakdown ?? []).filter(i => i.section === 'asset'));
const glSources = computed(() => (props.glBreakdown ?? []).filter(i => i.section === 'source'));

const assetRows  = computed(() => (props.balanceSheet ?? []).filter(r => r.section === 'asset'));
const sourceRows = computed(() => (props.balanceSheet ?? []).filter(r => r.section === 'source'));

const unmappedTotal = computed(() =>
  (props.unmappedAccounts ?? []).reduce((sum, a) => sum + Math.abs(a.balance), 0)
);

const assetItems  = computed(() => (props.reportItems ?? []).filter(i => i.section === 'asset'));
const equityItems = computed(() => (props.reportItems ?? []).filter(i => i.section === 'equity'));

const exportUrl    = computed(() => route('reports.balance_sheet.export') + '?as_of=' + asOf.value + '&mode=' + mode.value);
const exportPdfUrl = computed(() => route('reports.balance_sheet.pdf')    + '?as_of=' + asOf.value + '&mode=' + mode.value);

function rowClass(row, color) {
  if (row.is_total) return `bg-${color}-50`;
  if (row.is_section_header) return `bg-${color}-50`;
  if (row.level === 1 && row.is_formula) return 'bg-gray-50';
  return 'hover:bg-gray-50';
}

function amtClass(amount, isBold, color) {
  if (isBold) return `font-bold text-${color}-800`;
  if (amount < 0) return 'text-red-600';
  return 'text-gray-800';
}

// Map modal
const showMapModal = ref(false);
const mapSaving    = ref(false);
const mapError     = ref('');
const mapForm      = ref({ account_code: '', account_name: '', item_code: '' });

function openMapModal(acc) {
  mapForm.value  = { account_code: acc.code, account_name: acc.name, item_code: '' };
  mapError.value = '';
  showMapModal.value = true;
}

function submitMapping() {
  if (!mapForm.value.item_code) return;
  mapSaving.value = true;
  mapError.value  = '';
  router.post(
    route('reports.balance_sheet.map_account'),
    { account_code: mapForm.value.account_code, item_code: mapForm.value.item_code },
    {
      preserveScroll: true,
      onSuccess: () => {
        showMapModal.value = false;
        router.get(route('reports.balance_sheet'), { as_of: asOf.value }, { replace: true });
      },
      onError: (errors) => { mapError.value = Object.values(errors).flat().join(' '); },
      onFinish: () => { mapSaving.value = false; },
    }
  );
}

function applyFilters() {
  router.get(route('reports.balance_sheet'), { as_of: asOf.value, mode: mode.value }, {
    preserveState: true, replace: true,
  });
}

function setMode(newMode) {
  mode.value = newMode;
  router.get(route('reports.balance_sheet'), { as_of: asOf.value, mode: newMode }, {
    preserveState: true, replace: true,
  });
}
</script>
