<template>
  <AppLayout>
    <div class="max-w-full space-y-4">

      <!-- Print-only document header -->
      <div class="print-only mb-3">
        <div class="text-center mb-2">
          <p class="text-sm font-bold uppercase tracking-wide">{{ $page.props.company?.company_name }}</p>
          <p v-if="$page.props.company?.company_address" class="text-xs text-gray-600">{{ $page.props.company?.company_address }}</p>
          <p v-if="$page.props.company?.company_tax_code" class="text-xs text-gray-600">MST: {{ $page.props.company?.company_tax_code }}</p>
        </div>
        <div class="text-center border-b-2 border-gray-800 pb-2 mb-2">
          <h2 class="text-base font-bold uppercase">Bảng tính - Thanh toán tiền lương</h2>
          <p class="text-sm">Tháng {{ formatPeriod(payroll.period) }} &nbsp;·&nbsp; {{ payroll.code }}</p>
        </div>
      </div>

      <!-- Header -->
      <div class="no-print flex items-center justify-between flex-wrap gap-3">
        <div class="flex items-center gap-3">
          <Link :href="route('accounting.payrolls.index')" class="text-gray-400 hover:text-gray-600 text-sm">
            &larr; Danh sách bảng lương
          </Link>
          <div>
            <h1 class="text-xl font-bold text-gray-900">BẢNG TÍNH - THANH TOÁN TIỀN LƯƠNG</h1>
            <p class="text-sm text-gray-500">Tháng {{ formatPeriod(payroll.period) }} · {{ payroll.code }}</p>
          </div>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
          <!-- Lock badge -->
          <span v-if="payroll.is_locked"
            class="inline-flex items-center gap-1 px-3 py-1 bg-red-100 text-red-700 rounded-full text-xs font-bold border border-red-200">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
            ĐÃ KHÓA · {{ payroll.locked_by_name }}
          </span>

          <button @click="printPayroll"
            class="flex items-center gap-1.5 px-3 py-1.5 border border-gray-300 rounded-lg text-sm text-gray-600 hover:bg-gray-50">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
            </svg>
            In bảng lương
          </button>

          <a :href="route('accounting.payrolls.export-excel', payroll.id)"
            class="no-print flex items-center gap-1.5 px-3 py-1.5 border border-green-300 rounded-lg text-sm text-green-700 hover:bg-green-50">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            Xuất Excel
          </a>
          <a :href="route('accounting.payrolls.export-pdf', payroll.id)"
            class="no-print flex items-center gap-1.5 px-3 py-1.5 border border-red-300 rounded-lg text-sm text-red-700 hover:bg-red-50">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
            </svg>
            Xuất PDF
          </a>

          <!-- Sync from employees (draft + not locked) -->
          <button v-if="payroll.status === 'draft' && !payroll.is_locked" @click="syncFromEmployees"
            class="flex items-center gap-1.5 px-3 py-1.5 border border-gray-400 text-gray-700 rounded-lg text-sm hover:bg-gray-50">
            Đồng bộ từ hồ sơ NV
          </button>

          <!-- Confirm (draft + not locked) -->
          <button v-if="payroll.status === 'draft' && !payroll.is_locked" @click="confirmPayroll"
            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-semibold shadow-sm">
            Xác nhận bảng lương
          </button>

          <!-- Unconfirm (admin only, confirmed + not locked) -->
          <button v-if="payroll.status === 'confirmed' && !payroll.is_locked && isAdmin" @click="unconfirmPayroll"
            class="flex items-center gap-1.5 px-3 py-1.5 border border-red-400 text-red-600 rounded-lg text-sm hover:bg-red-50">
            Hủy xác nhận
          </button>

          <!-- Rollback payments (accounting.manage + has paid items) -->
          <button v-if="hasPaidItems && can('accounting.manage')" @click="openRollbackModal"
            class="flex items-center gap-1.5 px-3 py-1.5 border border-red-500 text-red-700 bg-red-50 hover:bg-red-100 rounded-lg text-sm font-medium">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
            Hủy thanh toán lương
          </button>

          <!-- Status badge when not draft -->
          <span v-if="payroll.status !== 'draft'" :class="{
            'bg-blue-100 text-blue-800': payroll.status === 'confirmed',
            'bg-green-100 text-green-800': payroll.status === 'paid',
          }" class="px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider">
            {{ payroll.status_label }}
          </span>

          <!-- Lock button (not yet locked) -->
          <button v-if="!payroll.is_locked && can('accounting.manage')" @click="lockPayroll"
            class="flex items-center gap-1.5 px-3 py-1.5 border border-gray-400 text-gray-600 rounded-lg text-sm hover:bg-gray-50">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
            Khóa bảng lương
          </button>

          <!-- Unlock button (admin only) -->
          <button v-if="payroll.is_locked && isAdmin" @click="unlockPayroll"
            class="flex items-center gap-1.5 px-3 py-1.5 border border-orange-400 text-orange-600 rounded-lg text-sm hover:bg-orange-50">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 018 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/>
            </svg>
            Mở khóa (Admin)
          </button>
        </div>
      </div>

      <!-- Flash -->
      <div v-if="$page.props.flash?.success" class="no-print bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm">{{ $page.props.flash.success }}</div>
      <div v-if="$page.props.flash?.error"   class="no-print bg-red-50   border border-red-200   text-red-800   rounded-xl px-4 py-3 text-sm">{{ $page.props.flash.error }}</div>

      <!-- Summary cards -->
      <div class="no-print grid grid-cols-2 md:grid-cols-5 gap-3">
        <div class="bg-white rounded-xl shadow-sm p-3">
          <p class="text-xs text-gray-500 mb-1">Tổng lương CB</p>
          <p class="text-sm font-bold text-gray-800 font-mono">{{ fv(payroll.total_base_salary) }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-3">
          <p class="text-xs text-gray-500 mb-1">Tổng phụ cấp</p>
          <p class="text-sm font-bold text-blue-700 font-mono">{{ fv(payroll.total_allowance) }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-3">
          <p class="text-xs text-gray-500 mb-1">BHXH CP công ty</p>
          <p class="text-sm font-bold text-amber-700 font-mono">{{ fv(payroll.total_insurance_employer) }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-3">
          <p class="text-xs text-gray-500 mb-1">Thuế TNCN</p>
          <p class="text-sm font-bold text-red-600 font-mono">{{ fv(payroll.total_pit) }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-3 border-l-4 border-primary-500">
          <p class="text-xs text-gray-500 mb-1">Tổng thực lĩnh</p>
          <p class="text-lg font-extrabold text-primary-600 font-mono">{{ fv(payroll.total_net_salary) }}</p>
        </div>
      </div>

      <!-- Union fee confirmation panel -->
      <div v-if="payroll.total_trade_union_fee > 0" class="no-print bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 flex items-center justify-between flex-wrap gap-3">
        <div>
          <p class="text-xs font-semibold text-amber-800 mb-0.5">Kinh phí công đoàn (KPCĐ) doanh nghiệp phải nộp</p>
          <p class="text-xs text-amber-700">
            Hệ thống tính được: <span class="font-mono font-bold">{{ fv(payroll.total_trade_union_fee) }} đ</span>
            <span v-if="payroll.union_fee_include === null"> · <em class="not-italic text-gray-500">Chưa xác nhận — sẽ không hạch toán vào bút toán lương</em></span>
            <span v-else-if="payroll.union_fee_include" class="text-green-700"> · Đã chọn ghi nhận vào chi phí · {{ payroll.union_fee_confirmed_by }} · {{ payroll.union_fee_confirmed_at }}</span>
            <span v-else class="text-gray-600"> · Đã chọn không ghi nhận · {{ payroll.union_fee_confirmed_by }} · {{ payroll.union_fee_confirmed_at }}</span>
          </p>
        </div>
        <div v-if="payroll.status === 'draft' && !payroll.is_locked" class="flex items-center gap-2 shrink-0">
          <button @click="setUnionFee(true)"
            :class="payroll.union_fee_include === true ? 'bg-green-600 text-white border-green-600' : 'bg-white border-green-500 text-green-700 hover:bg-green-50'"
            class="border px-3 py-1.5 rounded-lg text-xs font-medium">
            Ghi nhận vào chi phí
          </button>
          <button @click="setUnionFee(false)"
            :class="payroll.union_fee_include === false ? 'bg-gray-500 text-white border-gray-500' : 'bg-white border-gray-400 text-gray-600 hover:bg-gray-50'"
            class="border px-3 py-1.5 rounded-lg text-xs font-medium">
            Không ghi nhận
          </button>
        </div>
        <span v-else-if="payroll.union_fee_include !== null" class="text-xs px-2 py-1 rounded-full font-medium shrink-0"
          :class="payroll.union_fee_include ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'">
          {{ payroll.union_fee_include ? 'Đã ghi nhận' : 'Không ghi nhận' }}
        </span>
      </div>

      <!-- Full Payroll Table -->
      <div class="bg-white rounded-xl shadow-sm overflow-x-auto" id="payroll-table-container">
        <div class="overflow-x-auto">
          <table class="min-w-full text-xs whitespace-nowrap border-collapse" style="min-width: 2400px">
            <thead>
              <!-- Row 1: column group headers -->
              <tr class="bg-primary-700 text-white text-center">
                <th rowspan="2" class="border border-primary-600 px-2 py-2 w-8">STT</th>
                <th rowspan="2" class="border border-primary-600 px-3 py-2 text-left min-w-[140px]">Họ và tên</th>
                <th rowspan="2" class="border border-primary-600 px-2 py-2 min-w-[90px]">Chức vụ</th>
                <th rowspan="2" class="border border-primary-600 px-2 py-2 min-w-[80px]">Bộ phận</th>
                <th rowspan="2" class="border border-primary-600 px-2 py-2 min-w-[80px]">Loại HĐ</th>
                <th rowspan="2" class="border border-primary-600 px-2 py-2 min-w-[90px]">Lương<br/>Chính</th>
                <th colspan="6" class="border border-primary-600 px-2 py-1">Phụ cấp</th>
                <th rowspan="2" class="border border-primary-600 px-2 py-2 min-w-[90px]">Tổng<br/>Thu Nhập</th>
                <th colspan="5" class="border border-primary-600 px-2 py-1 bg-emerald-700">Chuyên cần</th>
                <th rowspan="2" class="border border-primary-600 px-2 py-2 min-w-[90px]">Tổng Lương<br/>Thực Tế</th>
                <th rowspan="2" class="border border-primary-600 px-2 py-2 min-w-[80px]">Lương<br/>đóng BH</th>
                <th colspan="4" class="border border-primary-600 px-2 py-1">BHXH tính vào CP DN</th>
                <th colspan="4" class="border border-primary-600 px-2 py-1">Trích vào Lương NV</th>
                <th rowspan="2" class="border border-primary-600 px-2 py-2 min-w-[80px]">TN chịu<br/>thuế</th>
                <th rowspan="2" class="border border-primary-600 px-2 py-2 w-10">NPT</th>
                <th rowspan="2" class="border border-primary-600 px-2 py-2 min-w-[80px]">Giảm trừ<br/>gia cảnh</th>
                <th rowspan="2" class="border border-primary-600 px-2 py-2 min-w-[80px]">TN tính<br/>thuế</th>
                <th rowspan="2" class="border border-primary-600 px-2 py-2 min-w-[70px]">Thuế<br/>TNCN</th>
                <th rowspan="2" class="border border-primary-600 px-2 py-2 min-w-[70px]">Tạm<br/>ứng</th>
                <th rowspan="2" class="border border-primary-600 px-2 py-2 min-w-[80px]">Điều<br/>chỉnh</th>
                <th rowspan="2" class="border border-primary-600 px-2 py-2 min-w-[90px] font-bold">Thực lĩnh</th>
                <th rowspan="2" v-if="payroll.status !== 'draft'" class="border border-primary-600 px-2 py-2 w-16">TT</th>
                <th rowspan="2" v-if="payroll.status === 'draft'" class="border border-primary-600 px-2 py-2 w-10"></th>
              </tr>
              <tr class="bg-primary-600 text-white text-center">
                <th class="border border-primary-500 px-1 py-1">Cố<br/>định</th>
                <th class="border border-primary-500 px-1 py-1">Trách<br/>nhiệm</th>
                <th class="border border-primary-500 px-1 py-1">Ăn<br/>trưa</th>
                <th class="border border-primary-500 px-1 py-1">Điện<br/>thoại</th>
                <th class="border border-primary-500 px-1 py-1">Xăng<br/>xe</th>
                <th class="border border-primary-500 px-1 py-1">HQ<br/>CV</th>
                <th class="border border-primary-500 px-1 py-1">BHXH<br/>17.5%</th>
                <th class="border border-primary-500 px-1 py-1">BHYT<br/>3%</th>
                <th class="border border-primary-500 px-1 py-1">BHTN<br/>1%</th>
                <th class="border border-primary-500 px-1 py-1 font-bold">Cộng<br/>21.5%</th>
                <!-- Chuyên cần sub-cols -->
                <th class="border border-emerald-600 px-1 py-1 bg-emerald-700">C.chuẩn</th>
                <th class="border border-emerald-600 px-1 py-1 bg-emerald-700">C.TT</th>
                <th class="border border-emerald-600 px-1 py-1 bg-emerald-700">C.hưởng</th>
                <th class="border border-emerald-600 px-1 py-1 bg-emerald-700">Nghỉ phép</th>
                <th class="border border-emerald-600 px-1 py-1 bg-emerald-700">Nghỉ KL</th>
                <th class="border border-primary-500 px-1 py-1">BHXH<br/>8%</th>
                <th class="border border-primary-500 px-1 py-1">BHYT<br/>1.5%</th>
                <th class="border border-primary-500 px-1 py-1">BHTN<br/>1%</th>
                <th class="border border-primary-500 px-1 py-1 font-bold">Cộng<br/>10.5%</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="(group, deptName) in groupedItems" :key="deptName">
                <!-- Department row -->
                <tr class="bg-yellow-50">
                  <td class="border border-gray-200 px-2 py-1.5 font-bold text-gray-800 text-xs" colspan="5">
                    {{ deptName || 'Chưa phân phòng ban' }}
                  </td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-bold font-mono">{{ fv(sum(group, 'base_salary')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'allowance')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'allowance_responsibility')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'allowance_lunch')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'allowance_phone')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'allowance_transport')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'allowance_performance')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-bold font-mono">{{ fv(sum(group, 'gross_salary')) }}</td>
                  <!-- Chuyên cần -->
                  <td class="border border-gray-200 px-2 py-1.5 text-center font-mono">{{ sumInt(group, 'standard_days') }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-center font-mono">{{ sumInt(group, 'actual_working_days') }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-center font-mono">{{ sumInt(group, 'working_days') }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-center font-mono">{{ sumInt(group, 'paid_leave_days') }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-center font-mono">{{ sumInt(group, 'unpaid_leave_days') }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-bold font-mono">{{ fv(sum(group, 'gross_salary')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'insurance_base')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'bhxh_employer')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'bhyt_employer')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'bhtn_employer')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-bold font-mono">{{ fv(sum(group, 'bhxh_employer') + sum(group, 'bhyt_employer') + sum(group, 'bhtn_employer')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'bhxh_employee')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'bhyt_employee')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'bhtn_employee')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-bold font-mono">{{ fv(sum(group, 'bhxh_employee') + sum(group, 'bhyt_employee') + sum(group, 'bhtn_employee')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'gross_salary')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-center font-mono">{{ sumInt(group, 'dependents_count') }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'personal_deduction')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'taxable_for_pit')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'pit')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'advance')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono"
                      :class="sum(group, 'adjustment_amount') > 0 ? 'text-green-700' : sum(group, 'adjustment_amount') < 0 ? 'text-red-600' : ''">
                    {{ sum(group, 'adjustment_amount') !== 0 ? fv(sum(group, 'adjustment_amount')) : '' }}
                  </td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-bold font-mono text-primary-700">{{ fv(sum(group, 'thuc_linh')) }}</td>
                  <td v-if="payroll.status !== 'draft'" class="border border-gray-200"></td>
                  <td v-if="payroll.status === 'draft'" class="border border-gray-200"></td>
                </tr>

                <!-- Employee rows -->
                <tr v-for="(item, idx) in group" :key="item.id"
                  class="hover:bg-blue-50 transition-colors"
                  :class="{ 'bg-gray-50': !item.insurance_subject }">
                  <td class="border border-gray-200 px-2 py-1.5 text-center text-gray-500">{{ idx + 1 }}</td>
                  <td class="border border-gray-200 px-3 py-1.5">
                    <p class="font-semibold text-gray-900">{{ item.employee_name }}</p>
                    <p class="text-gray-400 text-[10px]">{{ item.employee_code }}</p>
                  </td>
                  <td class="border border-gray-200 px-2 py-1.5 text-center text-gray-600">{{ item.position }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-center text-gray-600 text-[10px]">{{ item.department || '—' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-center text-gray-600 text-[10px]">{{ item.employment_type || '—' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono font-semibold">{{ fv(item.base_salary) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ item.allowance             ? fv(item.allowance)             : '' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ item.allowance_responsibility ? fv(item.allowance_responsibility) : '' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ item.allowance_lunch        ? fv(item.allowance_lunch)        : '' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ item.allowance_phone        ? fv(item.allowance_phone)        : '' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ item.allowance_transport    ? fv(item.allowance_transport)    : '' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ item.allowance_performance  ? fv(item.allowance_performance)  : '' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono font-semibold">{{ fv(item.gross_salary) }}</td>
                  <!-- Chuyên cần -->
                  <td class="border border-gray-200 px-2 py-1.5 text-center font-mono">{{ item.standard_days }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-center font-mono">{{ item.actual_working_days || 0 }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-center font-mono font-semibold">{{ item.working_days }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-center font-mono text-blue-600">{{ item.paid_leave_days || 0 }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-center font-mono text-red-500">{{ item.unpaid_leave_days || 0 }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono font-semibold">{{ fv(item.gross_salary) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ item.insurance_subject ? fv(item.insurance_base) : '—' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ item.insurance_subject ? fv(item.bhxh_employer) : '—' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ item.insurance_subject ? fv(item.bhyt_employer) : '—' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ item.insurance_subject ? fv(item.bhtn_employer) : '—' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono font-semibold">{{ item.insurance_subject ? fv(item.bhxh_employer + item.bhyt_employer + item.bhtn_employer) : '—' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono text-orange-600">{{ item.insurance_subject ? fv(item.bhxh_employee) : '—' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono text-orange-600">{{ item.insurance_subject ? fv(item.bhyt_employee) : '—' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono text-orange-600">{{ item.insurance_subject ? fv(item.bhtn_employee) : '—' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono font-semibold text-orange-700">{{ item.insurance_subject ? fv(item.bhxh_employee + item.bhyt_employee + item.bhtn_employee) : '—' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(item.gross_salary) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-center font-mono">{{ item.dependents_count }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(item.personal_deduction) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(item.taxable_for_pit) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono text-red-600">{{ fv(item.pit) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono text-gray-500">{{ item.advance ? fv(item.advance) : '' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono"
                      :class="(item.adjustment_amount || 0) > 0 ? 'text-green-700 font-semibold' : (item.adjustment_amount || 0) < 0 ? 'text-red-600 font-semibold' : ''">
                    <template v-if="item.adjustment_amount && item.adjustment_amount != 0">
                      {{ fv(item.adjustment_amount) }}
                      <p v-if="item.adjusted_by" class="text-[9px] text-gray-400 font-normal leading-tight">{{ item.adjusted_by }}</p>
                    </template>
                  </td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-bold font-mono text-primary-700">{{ fv(item.thuc_linh) }}</td>
                  <!-- Status + action -->
                  <td v-if="payroll.status !== 'draft'" class="border border-gray-200 px-2 py-1.5 text-center">
                    <span :class="{
                      'bg-yellow-100 text-yellow-800': item.status === 'pending',
                      'bg-green-100 text-green-800': item.status === 'paid',
                    }" class="px-1.5 py-0.5 rounded-full text-[10px] font-semibold">{{ item.status_label }}</span>
                    <div v-if="payroll.status === 'confirmed' && item.status === 'pending'" class="mt-1">
                      <button @click="openPayModal(item)"
                        class="bg-green-600 hover:bg-green-700 text-white px-2 py-0.5 rounded text-[10px] font-bold">
                        Chi lương
                      </button>
                    </div>
                    <Link v-if="item.salary_journal_entry"
                      :href="route('accounting.journal-entries.show', item.salary_journal_entry.id)"
                      class="text-green-600 hover:underline text-[10px] font-mono font-bold block mt-0.5">
                      {{ item.salary_journal_entry.code }}
                    </Link>
                  </td>
                  <td v-if="payroll.status === 'draft'" class="border border-gray-200 px-2 py-1.5 text-center">
                    <button @click="openEditModal(item)"
                      class="text-primary-600 hover:underline text-[10px] font-semibold">Sửa</button>
                  </td>
                </tr>
              </template>
            </tbody>
            <!-- Grand total -->
            <tfoot>
              <tr class="bg-primary-50 font-bold border-t-2 border-primary-300">
                <td colspan="5" class="border border-gray-300 px-3 py-2 text-sm font-bold text-gray-800">Tổng cộng</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono text-sm">{{ fv(payroll.total_base_salary) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono">{{ fv(sumItems('allowance')) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono">{{ fv(sumItems('allowance_responsibility')) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono">{{ fv(sumItems('allowance_lunch')) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono">{{ fv(sumItems('allowance_phone')) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono">{{ fv(sumItems('allowance_transport')) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono">{{ fv(sumItems('allowance_performance')) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono text-sm font-bold">{{ fv(payroll.total_gross) }}</td>
                <!-- Chuyên cần totals -->
                <td class="border border-gray-300 px-2 py-2 text-center font-mono">{{ sumItems('standard_days') }}</td>
                <td class="border border-gray-300 px-2 py-2 text-center font-mono">{{ sumItems('actual_working_days') }}</td>
                <td class="border border-gray-300 px-2 py-2 text-center font-mono">{{ sumItems('working_days') }}</td>
                <td class="border border-gray-300 px-2 py-2 text-center font-mono">{{ sumItems('paid_leave_days') }}</td>
                <td class="border border-gray-300 px-2 py-2 text-center font-mono">{{ sumItems('unpaid_leave_days') }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono text-sm font-bold">{{ fv(payroll.total_gross) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono">{{ fv(sumItems('insurance_base')) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono">{{ fv(sumItems('bhxh_employer')) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono">{{ fv(sumItems('bhyt_employer')) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono">{{ fv(sumItems('bhtn_employer')) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono font-bold">{{ fv(payroll.total_insurance_employer) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono text-orange-600">{{ fv(sumItems('bhxh_employee')) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono text-orange-600">{{ fv(sumItems('bhyt_employee')) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono text-orange-600">{{ fv(sumItems('bhtn_employee')) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono font-bold text-orange-700">{{ fv(payroll.total_insurance_employee) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono">{{ fv(payroll.total_gross) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-center font-mono">{{ sumItems('dependents_count') }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono">{{ fv(sumItems('personal_deduction')) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono">{{ fv(sumItems('taxable_for_pit')) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono text-red-600">{{ fv(payroll.total_pit) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono">{{ fv(sumItems('advance')) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono font-bold"
                    :class="sumItems('adjustment_amount') > 0 ? 'text-green-700' : sumItems('adjustment_amount') < 0 ? 'text-red-600' : ''">
                  {{ sumItems('adjustment_amount') !== 0 ? fv(sumItems('adjustment_amount')) : '' }}
                </td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono text-lg font-bold text-primary-700">{{ fv(sumItems('thuc_linh')) }}</td>
                <td class="border border-gray-300" colspan="1"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- Print-only KPCĐ info -->
      <div v-if="payroll.total_trade_union_fee > 0" class="print-only mt-1 text-xs">
        KPCĐ doanh nghiệp: <strong>{{ fv(payroll.total_trade_union_fee) }} đ</strong>
        <span v-if="payroll.union_fee_include === true"> · Ghi nhận vào chi phí</span>
        <span v-else-if="payroll.union_fee_include === false"> · Không ghi nhận</span>
      </div>

      <!-- Notes -->
      <div v-if="payroll.notes" class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-xs font-semibold text-gray-600 mb-0.5">Ghi chú</p>
        <p class="text-sm text-gray-700">{{ payroll.notes }}</p>
      </div>

      <!-- Signature row -->
      <div class="bg-white rounded-xl shadow-sm p-5">
        <div class="grid grid-cols-3 gap-4 text-center text-sm">
          <div>
            <p class="font-semibold text-gray-700 mb-1">Người lập biểu</p>
            <p class="text-gray-400 text-xs italic">(Ký, họ tên)</p>
            <div class="mt-10 border-t border-gray-300 pt-1 text-xs text-gray-500">{{ $page.props.auth?.user?.name }}</div>
          </div>
          <div>
            <p class="font-semibold text-gray-700 mb-1">Kế toán trưởng</p>
            <p class="text-gray-400 text-xs italic">(Ký, họ tên)</p>
            <div class="mt-10 border-t border-gray-300"></div>
          </div>
          <div>
            <p class="font-semibold text-gray-700 mb-1">Giám đốc</p>
            <p class="text-gray-400 text-xs italic">Ký, họ tên</p>
            <div class="mt-10 border-t border-gray-300"></div>
          </div>
        </div>
      </div>

    </div><!-- end max-w-full -->

    <!-- Edit Modal -->
    <div v-if="showEditModal" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4">
      <div class="bg-white rounded-xl shadow-xl max-w-lg w-full max-h-[90vh] overflow-y-auto">
        <div class="px-5 py-4 border-b bg-gray-50 flex justify-between sticky top-0">
          <h3 class="font-bold text-gray-900">Cập nhật lương — {{ activeItem.employee_name }}</h3>
          <button @click="showEditModal = false" class="text-gray-400 hover:text-gray-600 text-lg">&times;</button>
        </div>
        <form @submit.prevent="submitEdit" class="p-5 space-y-4">

          <!-- BHXH switch -->
          <div class="flex items-center gap-3 bg-gray-50 rounded-lg px-4 py-2.5">
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" v-model="editForm.insurance_subject" class="sr-only peer" />
              <div class="w-9 h-5 bg-gray-300 rounded-full peer peer-checked:bg-green-500 transition-colors"></div>
              <div class="absolute left-0.5 top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-4"></div>
            </label>
            <span class="text-sm font-medium text-gray-700">Đóng BHXH/BHYT/BHTN</span>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="form-label text-xs">Lương cơ bản (đóng BH)</label>
              <input type="number" v-model.number="editForm.base_salary" min="0" step="any"
                class="form-input text-right font-mono text-sm" />
            </div>
            <div>
              <label class="form-label text-xs">Ngày công chuẩn tháng này</label>
              <input type="number" v-model.number="editForm.standard_days" min="1" max="31"
                class="form-input text-right text-sm" />
              <p class="text-xs text-gray-400 mt-0.5">Số ngày làm việc thực tế của tháng</p>
            </div>
            <div>
              <label class="form-label text-xs">Ngày công thực tế (NV)</label>
              <input type="number" v-model.number="editForm.working_days" min="0" max="31"
                class="form-input text-right text-sm" />
              <p class="text-xs text-gray-400 mt-0.5">Tỷ lệ: {{ editForm.standard_days > 0 ? Math.min((editForm.working_days / editForm.standard_days * 100).toFixed(1), 100) : 100 }}%</p>
            </div>
          </div>

          <!-- BHXH override fields -->
          <div v-if="editForm.insurance_subject" class="bg-orange-50 border border-orange-200 rounded-lg p-3 space-y-2">
            <p class="text-xs font-semibold text-orange-800">Số tiền BHXH tháng này (có thể sửa)</p>
            <div class="grid grid-cols-3 gap-2">
              <div>
                <label class="text-[10px] text-gray-500 font-medium">BHXH CP DN (17.5%)</label>
                <input type="number" v-model.number="editForm.bhxh_employer" min="0" step="any"
                  class="form-input text-right font-mono text-xs" />
              </div>
              <div>
                <label class="text-[10px] text-gray-500 font-medium">BHYT CP DN (3%)</label>
                <input type="number" v-model.number="editForm.bhyt_employer" min="0" step="any"
                  class="form-input text-right font-mono text-xs" />
              </div>
              <div>
                <label class="text-[10px] text-gray-500 font-medium">BHTN CP DN (1%)</label>
                <input type="number" v-model.number="editForm.bhtn_employer" min="0" step="any"
                  class="form-input text-right font-mono text-xs" />
              </div>
              <div>
                <label class="text-[10px] text-orange-600 font-medium">BHXH NV (8%)</label>
                <input type="number" v-model.number="editForm.bhxh_employee" min="0" step="any"
                  class="form-input text-right font-mono text-xs border-orange-300" />
              </div>
              <div>
                <label class="text-[10px] text-orange-600 font-medium">BHYT NV (1.5%)</label>
                <input type="number" v-model.number="editForm.bhyt_employee" min="0" step="any"
                  class="form-input text-right font-mono text-xs border-orange-300" />
              </div>
              <div>
                <label class="text-[10px] text-orange-600 font-medium">BHTN NV (1%)</label>
                <input type="number" v-model.number="editForm.bhtn_employee" min="0" step="any"
                  class="form-input text-right font-mono text-xs border-orange-300" />
              </div>
            </div>
            <p class="text-[10px] text-gray-400">Mặc định: theo công thức. Đặt về 0 nếu tháng này NV không đóng.</p>
          </div>

          <!-- PIT override — admin only -->
          <div v-if="isAdmin" class="bg-red-50 border border-red-200 rounded-lg p-3 space-y-2">
            <div class="flex items-center gap-3">
              <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" v-model="editForm.pit_override_enabled" class="sr-only peer" />
                <div class="w-9 h-5 bg-gray-300 rounded-full peer peer-checked:bg-red-500 transition-colors"></div>
                <div class="absolute left-0.5 top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-4"></div>
              </label>
              <span class="text-xs font-semibold text-red-800">Ghi đè Thuế TNCN thủ công (Admin)</span>
            </div>
            <div v-if="editForm.pit_override_enabled" class="space-y-1">
              <label class="text-[10px] text-red-600 font-medium">Thuế TNCN thực tế (đ)</label>
              <input type="number" v-model.number="editForm.pit_override" min="0" step="any"
                class="form-input text-right font-mono text-sm border-red-300" />
              <p class="text-[10px] text-gray-400">Công thức tính: {{ fv(previewPitAuto) }} đ</p>
            </div>
          </div>

          <div class="border-b pb-1 flex gap-3">
            <p class="text-xs font-semibold text-green-700">Phụ cấp lương (tính BHXH): cố định, trách nhiệm</p>
            <p class="text-xs font-semibold text-blue-700 ml-auto">| Hỗ trợ phúc lợi (không BHXH): ăn trưa, xăng xe, ĐT, hiệu quả</p>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="form-label text-xs">PC Cố định (từ hồ sơ NV)</label>
              <input type="number" v-model.number="editForm.allowance" min="0" step="any"
                class="form-input text-right font-mono text-sm" />
            </div>
            <div>
              <label class="form-label text-xs">PC Trách nhiệm</label>
              <input type="number" v-model.number="editForm.allowance_responsibility" min="0" step="any"
                class="form-input text-right font-mono text-sm" />
            </div>
            <div>
              <label class="form-label text-xs">PC Ăn trưa</label>
              <input type="number" v-model.number="editForm.allowance_lunch" min="0" step="any"
                class="form-input text-right font-mono text-sm" />
            </div>
            <div>
              <label class="form-label text-xs">PC Điện thoại</label>
              <input type="number" v-model.number="editForm.allowance_phone" min="0" step="any"
                class="form-input text-right font-mono text-sm" />
            </div>
            <div>
              <label class="form-label text-xs">PC Xăng xe</label>
              <input type="number" v-model.number="editForm.allowance_transport" min="0" step="any"
                class="form-input text-right font-mono text-sm" />
            </div>
            <div>
              <label class="form-label text-xs">PC Hiệu quả CV (tháng này)</label>
              <input type="number" v-model.number="editForm.allowance_performance" min="0" step="any"
                class="form-input text-right font-mono text-sm" />
            </div>
            <div>
              <label class="form-label text-xs">Thưởng</label>
              <input type="number" v-model.number="editForm.bonus" min="0" step="any"
                class="form-input text-right font-mono text-sm" />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="form-label text-xs">Số người phụ thuộc</label>
              <input type="number" v-model.number="editForm.dependents_count" min="0" max="10"
                class="form-input text-right text-sm" />
            </div>
            <div>
              <label class="form-label text-xs">Tạm ứng (đã ứng)</label>
              <input type="number" v-model.number="editForm.advance" min="0" step="any"
                class="form-input text-right font-mono text-sm" />
            </div>
          </div>

          <!-- Live preview -->
          <div class="bg-blue-50 rounded-lg p-3 text-xs space-y-1.5">
            <div class="flex justify-between">
              <span class="text-gray-600">Tổng thu nhập (Gross):</span>
              <span class="font-mono font-semibold">{{ fv(previewGross) }}</span>
            </div>
            <div v-if="editForm.insurance_subject" class="flex justify-between text-green-700">
              <span>Căn cứ đóng BHXH (lương CB + PC trách nhiệm):</span>
              <span class="font-mono">{{ fv(previewInsBase) }}</span>
            </div>
            <div v-if="editForm.insurance_subject" class="flex justify-between text-orange-600">
              <span>BHXH/BHYT/BHTN NV (trích lương):</span>
              <span class="font-mono">-{{ fv(previewInsEmp) }}</span>
            </div>
            <div class="flex justify-between text-red-600">
              <span>Thuế TNCN:</span>
              <span class="font-mono">-{{ fv(previewPit) }}</span>
            </div>
            <div v-if="editForm.advance > 0" class="flex justify-between text-gray-600">
              <span>Tạm ứng:</span>
              <span class="font-mono">-{{ fv(editForm.advance) }}</span>
            </div>
            <div class="flex justify-between border-t border-blue-200 pt-1 font-bold text-primary-700 text-sm">
              <span>Thực lĩnh:</span>
              <span class="font-mono">{{ fv(previewNet - (editForm.advance || 0)) }}</span>
            </div>
          </div>

          <div class="flex gap-3 pt-2">
            <button type="submit" :disabled="editForm.processing" class="btn-primary flex-1">Lưu</button>
            <button type="button" @click="showEditModal = false" class="btn-secondary">Huỷ</button>
          </div>
        </form>

        <!-- Adjustment section — separate submit -->
        <div class="border-t px-5 pb-5 pt-4 space-y-3">
          <p class="text-xs font-bold text-gray-700">Số điều chỉnh (cộng / trừ ngoài công thức)</p>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="form-label text-xs">Số điều chỉnh (đ)</label>
              <input type="number" v-model.number="adjForm.adjustment_amount" step="any"
                class="form-input text-right font-mono text-sm"
                :class="adjForm.adjustment_amount > 0 ? 'border-green-400' : adjForm.adjustment_amount < 0 ? 'border-red-400' : ''" />
              <p class="text-[10px] text-gray-400 mt-0.5">Dương = cộng thêm · Âm = trừ bớt</p>
            </div>
            <div class="flex items-center gap-2 pt-5">
              <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" v-model="adjForm.adjustment_taxable" class="sr-only peer" />
                <div class="w-9 h-5 bg-gray-300 rounded-full peer peer-checked:bg-amber-500 transition-colors"></div>
                <div class="absolute left-0.5 top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-4"></div>
              </label>
              <span class="text-xs text-gray-700">Tính vào thuế TNCN</span>
            </div>
          </div>
          <div>
            <label class="form-label text-xs">Lý do điều chỉnh <span v-if="adjForm.adjustment_amount != 0" class="text-red-500">*</span></label>
            <textarea v-model="adjForm.adjustment_reason" rows="2"
              class="form-input text-sm resize-none"
              placeholder="Ví dụ: Phụ cấp đặc thù tháng 6, hỗ trợ đám cưới..."></textarea>
          </div>
          <div v-if="activeItem.adjusted_by" class="text-[10px] text-gray-400">
            Điều chỉnh lần cuối: <span class="font-semibold">{{ activeItem.adjusted_by }}</span>
            lúc {{ activeItem.adjusted_at }}
          </div>
          <button type="button" :disabled="adjForm.processing"
            @click="submitAdj"
            class="w-full border border-amber-400 bg-amber-50 text-amber-800 hover:bg-amber-100 px-3 py-2 rounded-lg text-xs font-semibold transition-colors">
            Lưu điều chỉnh
          </button>
        </div>
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
          <div>
            <label class="form-label">Số tiền thực chi</label>
            <input type="number" v-model="payForm.actual_amount" min="0" step="1"
              class="form-input text-right font-mono text-lg font-semibold" required />
            <p class="text-xs text-gray-400 mt-1">
              Mặc định: lương net {{ fv(activeItem.net_salary) }}
              <template v-if="activeItem.adjustment_amount !== 0"> + điều chỉnh {{ fv(activeItem.adjustment_amount) }}</template>
              <template v-if="activeItem.advance > 0"> − tạm ứng {{ fv(activeItem.advance) }}</template>
              = {{ fv(activeItem.thuc_linh) }}
            </p>
          </div>
          <div>
            <label class="form-label">Ngày chi lương</label>
            <input type="date" v-model="payForm.payment_date" class="form-input" required />
          </div>
          <div>
            <label class="form-label">Quỹ/Tài khoản chi tiền</label>
            <select v-model="payForm.fund_id" class="form-input" required>
              <option value="" disabled>-- Chọn quỹ hoặc tài khoản ngân hàng --</option>
              <optgroup v-if="cashFunds.length" label="Tiền mặt">
                <option v-for="f in cashFunds" :key="f.id" :value="f.id">
                  {{ f.name }}{{ f.account_code ? ` (${f.account_code})` : '' }}
                </option>
              </optgroup>
              <optgroup v-if="bankFunds.length" label="Ngân hàng">
                <option v-for="f in bankFunds" :key="f.id" :value="f.id">
                  {{ f.name }}{{ f.account_code ? ` (${f.account_code})` : '' }}
                </option>
              </optgroup>
            </select>
          </div>
          <div class="flex gap-3">
            <button type="submit" :disabled="payForm.processing" class="btn-primary flex-1">Xác nhận chi tiền</button>
            <button type="button" @click="showPayModal = false" class="btn-secondary">Huỷ</button>
          </div>
        </form>
      </div>
    </div>

  <!-- Rollback Modal -->
  <div v-if="showRollbackModal" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full max-h-[90vh] overflow-y-auto">
      <div class="px-5 py-4 border-b bg-gray-50 flex justify-between sticky top-0 z-10">
        <h3 class="font-bold text-red-700">Hủy thanh toán lương — {{ payroll.code }}</h3>
        <button @click="closeRollback" class="text-gray-400 hover:text-gray-600 text-lg">&times;</button>
      </div>

      <!-- Step 1: scope + reason -->
      <div v-if="rollbackStep === 'config'" class="p-5 space-y-4">
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-800">
          Thao tác này sẽ hủy các phiếu chi đã tạo, hoàn lại số dư quỹ và đưa bảng lương về trạng thái chưa thanh toán. Không thể hoàn tác tự động.
        </div>

        <div class="space-y-2">
          <p class="text-sm font-semibold text-gray-700">Phạm vi hủy:</p>
          <label class="flex items-start gap-3 p-3 border rounded-lg cursor-pointer transition-colors"
            :class="rollbackScope === 'payment_only' ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:bg-gray-50'">
            <input type="radio" v-model="rollbackScope" value="payment_only" class="mt-0.5 shrink-0" />
            <div>
              <p class="text-sm font-semibold text-gray-800">
                Chỉ hủy thanh toán
                <span class="ml-1 text-xs font-normal text-blue-600 bg-blue-100 px-1.5 py-0.5 rounded-full">An toàn · Mặc định</span>
              </p>
              <p class="text-xs text-gray-500 mt-0.5">Hủy phiếu chi, hoàn lại số dư quỹ. Dữ liệu lương giữ nguyên. Bảng lương trở về "Đã xác nhận".</p>
            </div>
          </label>
          <label class="flex items-start gap-3 p-3 border rounded-lg cursor-pointer transition-colors"
            :class="rollbackScope === 'payment_and_accrual' ? 'border-red-400 bg-red-50' : 'border-gray-200 hover:bg-gray-50'">
            <input type="radio" v-model="rollbackScope" value="payment_and_accrual" class="mt-0.5 shrink-0" />
            <div>
              <p class="text-sm font-semibold text-gray-800">Hủy thanh toán + hủy bút toán ghi nhận lương</p>
              <p class="text-xs text-gray-500 mt-0.5">Đảo cả bút toán Nợ 642/154 / Có 3341. Bảng lương trở về "Nháp" để tính lại từ đầu.</p>
            </div>
          </label>
        </div>

        <div>
          <label class="form-label text-sm">Lý do hủy <span class="text-red-500">*</span></label>
          <textarea v-model="rollbackReason" rows="2" class="form-input text-sm resize-none"
            placeholder="Ví dụ: Chi lương nhầm quỹ, sai số tiền..."></textarea>
        </div>

        <div class="flex gap-3">
          <button @click="fetchPreview" :disabled="!rollbackReason.trim() || previewLoading"
            class="flex-1 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-300 disabled:cursor-not-allowed text-white px-4 py-2 rounded-lg text-sm font-semibold">
            {{ previewLoading ? 'Đang tải...' : 'Xem trước' }}
          </button>
          <button @click="closeRollback" class="btn-secondary">Huỷ</button>
        </div>
      </div>

      <!-- Step 2: Preview + confirm -->
      <div v-if="rollbackStep === 'preview' && rollbackPreview" class="p-5 space-y-4">
        <!-- Period close warning -->
        <div v-if="rollbackPreview.period_close_warning"
          class="bg-amber-50 border border-amber-300 rounded-lg p-3 text-xs text-amber-800">
          ⚠️ {{ rollbackPreview.period_close_warning }}
        </div>

        <!-- Current period locked -->
        <div v-if="!rollbackPreview.current_period_open"
          class="bg-red-50 border border-red-300 rounded-lg p-3 text-xs text-red-800 font-semibold">
          ⛔ Kỳ kế toán hiện tại đã đóng/khóa. Hủy thanh toán sẽ thất bại. Cần mở lại kỳ kế toán trước khi thực hiện.
        </div>

        <!-- Summary -->
        <div class="bg-gray-50 rounded-lg p-4 space-y-2 text-sm">
          <div class="flex justify-between">
            <span class="text-gray-600">Bảng lương:</span>
            <span class="font-semibold">{{ rollbackPreview.payroll_code }} — Tháng {{ rollbackPreview.period }}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-gray-600">Số nhân viên bị ảnh hưởng:</span>
            <span class="font-semibold">{{ rollbackPreview.paid_count }} người</span>
          </div>
          <div class="flex justify-between">
            <span class="text-gray-600">Tổng tiền hoàn lại quỹ:</span>
            <span class="font-semibold font-mono text-red-600">{{ fv(rollbackPreview.total_amount) }} đ</span>
          </div>
          <div v-if="rollbackScope === 'payment_and_accrual' && rollbackPreview.has_accrual_je"
            class="flex justify-between border-t pt-2 mt-1">
            <span class="text-gray-600">Bút toán lương sẽ đảo:</span>
            <span class="font-semibold font-mono">{{ rollbackPreview.accrual_je_code }}</span>
          </div>
          <div class="border-t pt-2 mt-1 flex justify-between">
            <span class="text-gray-600">Lý do:</span>
            <span class="text-gray-700 text-right max-w-xs">{{ rollbackReason }}</span>
          </div>
        </div>

        <!-- Voucher list -->
        <div>
          <p class="text-xs font-semibold text-gray-600 mb-2">Phiếu chi sẽ bị hủy:</p>
          <div class="border rounded-lg overflow-hidden">
            <table class="min-w-full text-xs">
              <thead class="bg-gray-100">
                <tr>
                  <th class="px-3 py-2 text-left text-gray-600">Nhân viên</th>
                  <th class="px-3 py-2 text-right text-gray-600">Số tiền (đ)</th>
                  <th class="px-3 py-2 text-center text-gray-600">Phiếu chi</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="v in rollbackPreview.vouchers" :key="v.voucher_code ?? v.employee_name"
                  class="border-t">
                  <td class="px-3 py-1.5">{{ v.employee_name }}</td>
                  <td class="px-3 py-1.5 text-right font-mono">{{ fv(v.amount) }}</td>
                  <td class="px-3 py-1.5 text-center font-mono text-gray-500">{{ v.voucher_code || '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="flex gap-3">
          <button @click="rollbackStep = 'config'" class="btn-secondary text-sm">← Quay lại</button>
          <button @click="submitRollback"
            :disabled="!rollbackPreview.current_period_open || rollbackForm.processing"
            class="flex-1 bg-red-600 hover:bg-red-700 disabled:bg-gray-300 disabled:cursor-not-allowed text-white px-4 py-2 rounded-lg text-sm font-semibold">
            {{ rollbackForm.processing ? 'Đang xử lý...' : 'Xác nhận hủy thanh toán' }}
          </button>
        </div>
      </div>
    </div>
  </div>

  </AppLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { usePermission } from '@/composables/usePermission';

const props = defineProps({ payroll: Object, items: Array, funds: Array, can_manage: Boolean });

const { hasPermission: can } = usePermission();
const page = usePage();
const isAdmin = computed(() => page.props.auth?.roles?.includes('admin') ?? false);

function fv(val) {
  return new Intl.NumberFormat('vi-VN').format(Math.round(val || 0));
}

function formatPeriod(period) {
  if (!period) return '';
  const [y, m] = period.split('-');
  return `${parseInt(m)}/${y}`;
}

function sumItems(field) {
  return props.items.reduce((s, i) => s + (i[field] || 0), 0);
}

function sum(arr, field) {
  return arr.reduce((s, i) => s + (i[field] || 0), 0);
}

function sumInt(arr, field) {
  return arr.reduce((s, i) => s + (i[field] || 0), 0);
}

// Group items by department
const groupedItems = computed(() => {
  const groups = {};
  for (const item of props.items) {
    const dept = item.department || '';
    if (!groups[dept]) groups[dept] = [];
    groups[dept].push(item);
  }
  return groups;
});

// JS mirror of PitCalculatorService (TT 79/2022 + Nghị định 158/2025)
const INS_CAP       = 46_800_000;
const PERSONAL_DED  = 15_500_000;  // TT 79/2022
const DEPENDENT_DED = 6_200_000;   // TT 79/2022
const BRACKETS = [[5e6,5],[10e6,10],[18e6,15],[32e6,20],[52e6,25],[80e6,30],[null,35]];

function calcInsBase(base, bhxhAllw, insSubject) {
  if (!insSubject) return 0;
  return Math.min(base + bhxhAllw, INS_CAP);
}
function calcInsEmpOnBase(base, bhxhAllw, insSubject) {
  return Math.round(calcInsBase(base, bhxhAllw, insSubject) * 0.105); // 10.5%
}

function calcPit(gross, insEmp, deps) {
  const deduction = PERSONAL_DED + (deps * DEPENDENT_DED);
  let taxable = Math.max(0, gross - insEmp - deduction);
  let tax = 0, prev = 0;
  for (const [cap, rate] of BRACKETS) {
    if (taxable <= prev) break;
    const upper = cap ?? 1e15;
    tax += (Math.min(taxable, upper) - prev) * rate / 100;
    prev = upper;
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

function unconfirmPayroll() {
  if (confirm(`Hủy xác nhận bảng lương ${props.payroll.code}?\nBảng lương sẽ trở về trạng thái nháp và bút toán kế toán sẽ bị đảo.\nChỉ thực hiện khi cần sửa lương.`)) {
    router.post(route('accounting.payrolls.unconfirm', props.payroll.id));
  }
}

function syncFromEmployees() {
  if (confirm('Đồng bộ dữ liệu lương từ hồ sơ nhân viên?\nLương cơ bản và phụ cấp sẽ được cập nhật theo hồ sơ hiện tại.\nBonus, tạm ứng và phụ cấp hiệu quả nhập tay sẽ được giữ nguyên.')) {
    router.post(route('accounting.payrolls.sync-employees', props.payroll.id));
  }
}

function setUnionFee(include) {
  const msg = include
    ? `Ghi nhận phí công đoàn ${fv(props.payroll.total_trade_union_fee)} đ vào chi phí?\nSẽ hạch toán Nợ chi phí lương / Có TK 3382 khi xác nhận bảng lương.`
    : 'Không ghi nhận phí công đoàn vào chi phí?\nKPCĐ sẽ không đưa vào bút toán kế toán kỳ này.';
  if (confirm(msg)) {
    router.post(route('accounting.payrolls.set-union-fee', props.payroll.id), { union_fee_include: include });
  }
}

// Lock / Unlock
function lockPayroll() {
  if (confirm(`Khóa bảng lương ${props.payroll.code}?\nSau khi khóa, bảng lương không thể sửa hay xóa. Chỉ Admin mới có thể mở khóa.`)) {
    router.post(route('accounting.payrolls.lock', props.payroll.id));
  }
}

function unlockPayroll() {
  if (confirm(`Mở khóa bảng lương ${props.payroll.code}?\nHành động này yêu cầu quyền Admin.`)) {
    router.post(route('accounting.payrolls.unlock', props.payroll.id));
  }
}

// Print
function printPayroll() {
  window.print();
}

// Edit modal
const showEditModal = ref(false);
const activeItem    = ref({});
const editForm = useForm({
  base_salary:              0,
  allowance_responsibility: 0,
  allowance_lunch:          0,
  allowance_phone:          0,
  allowance_transport:      0,
  allowance_performance:    0,
  allowance:                0,
  bonus:                    0,
  dependents_count:         0,
  standard_days:            26,
  working_days:             26,
  advance:                  0,
  insurance_subject:        true,
  // BHXH override — kế toán có thể sửa thủ công
  bhxh_employer:            0,
  bhyt_employer:            0,
  bhtn_employer:            0,
  bhxh_employee:            0,
  bhyt_employee:            0,
  bhtn_employee:            0,
  // PIT override — chỉ admin
  pit_override_enabled:     false,
  pit_override:             0,
});

// BHXH-subject allowances (Nghị định 158/2025): trách nhiệm + cố định khác
const previewBhxhAllw = computed(() =>
  (editForm.allowance_responsibility || 0) + (editForm.allowance || 0)
);
// Non-BHXH: ăn trưa + xăng xe + ĐT + hiệu quả + bonus
const previewNonBhxh  = computed(() =>
  (editForm.allowance_lunch        || 0)
  + (editForm.allowance_phone      || 0)
  + (editForm.allowance_transport  || 0)
  + (editForm.allowance_performance || 0)
  + (editForm.bonus                || 0)
);

// Tỷ lệ ngày công — phải nhất quán với PitCalculatorService::breakdown()
const previewRate = computed(() => {
  const std = editForm.standard_days || 26;
  const wd  = editForm.working_days || 0;
  return std > 0 ? Math.min(wd / std, 1.0) : 1.0;
});

const previewGross   = computed(() => {
  const r = previewRate.value;
  return Math.round((editForm.base_salary || 0) * r)
       + Math.round(previewBhxhAllw.value * r)
       + Math.round(previewNonBhxh.value  * r);
});
const previewInsBase = computed(() =>
  calcInsBase(Math.round((editForm.base_salary || 0) * previewRate.value),
              Math.round(previewBhxhAllw.value  * previewRate.value),
              editForm.insurance_subject)
);
const previewInsEmp  = computed(() =>
  (editForm.bhxh_employee || 0) + (editForm.bhyt_employee || 0) + (editForm.bhtn_employee || 0)
);
const previewPitAuto = computed(() => calcPit(previewGross.value, previewInsEmp.value, editForm.dependents_count || 0));
const previewPit     = computed(() =>
  isAdmin.value && editForm.pit_override_enabled
    ? (editForm.pit_override || 0)
    : previewPitAuto.value
);
const previewNet     = computed(() => previewGross.value - previewInsEmp.value - previewPit.value);

function calcBhxhFromBase(insBase) {
  return {
    bhxh_employer: Math.round(insBase * 0.175),
    bhyt_employer: Math.round(insBase * 0.03),
    bhtn_employer: Math.round(insBase * 0.01),
    bhxh_employee: Math.round(insBase * 0.08),
    bhyt_employee: Math.round(insBase * 0.015),
    bhtn_employee: Math.round(insBase * 0.01),
  };
}

// Adjustment form (separate from main edit form)
const adjForm = useForm({
  adjustment_amount:  0,
  adjustment_reason:  '',
  adjustment_taxable: true,
});

function openEditModal(item) {
  activeItem.value = item;
  adjForm.adjustment_amount  = item.adjustment_amount ?? 0;
  adjForm.adjustment_reason  = item.adjustment_reason ?? '';
  adjForm.adjustment_taxable = item.adjustment_taxable ?? true;
  editForm.base_salary              = item.base_salary;
  editForm.allowance_responsibility = item.allowance_responsibility;
  editForm.allowance_lunch          = item.allowance_lunch;
  editForm.allowance_phone          = item.allowance_phone;
  editForm.allowance_transport      = item.allowance_transport;
  editForm.allowance_performance    = item.allowance_performance;
  editForm.allowance                = item.allowance;
  editForm.bonus                    = item.bonus;
  editForm.dependents_count         = item.dependents_count;
  editForm.standard_days            = item.standard_days;
  editForm.working_days             = item.working_days;
  editForm.advance                  = item.advance;
  editForm.insurance_subject        = item.insurance_subject;
  editForm.bhxh_employer            = item.bhxh_employer ?? 0;
  editForm.bhyt_employer            = item.bhyt_employer ?? 0;
  editForm.bhtn_employer            = item.bhtn_employer ?? 0;
  editForm.bhxh_employee            = item.bhxh_employee ?? 0;
  editForm.bhyt_employee            = item.bhyt_employee ?? 0;
  editForm.bhtn_employee            = item.bhtn_employee ?? 0;
  editForm.pit_override_enabled     = false;
  editForm.pit_override             = item.pit ?? 0;
  showEditModal.value = true;
}

watch(() => editForm.insurance_subject, (newVal) => {
  if (!newVal) {
    editForm.bhxh_employer = 0; editForm.bhyt_employer = 0; editForm.bhtn_employer = 0;
    editForm.bhxh_employee = 0; editForm.bhyt_employee = 0; editForm.bhtn_employee = 0;
  } else {
    const insBase = calcInsBase(editForm.base_salary || 0, previewBhxhAllw.value, true);
    Object.assign(editForm, calcBhxhFromBase(insBase));
  }
});

function submitEdit() {
  editForm.put(
    route('accounting.payrolls.items.update', { payroll: props.payroll.id, item: activeItem.value.id }),
    { onSuccess: () => { showEditModal.value = false; } }
  );
}

function submitAdj() {
  adjForm.patch(
    route('accounting.payrolls.items.adjustment', { payroll: props.payroll.id, item: activeItem.value.id }),
    { onSuccess: () => { showEditModal.value = false; } }
  );
}

// Rollback modal
const showRollbackModal = ref(false);
const rollbackStep      = ref('config');
const rollbackScope     = ref('payment_only');
const rollbackReason    = ref('');
const previewLoading    = ref(false);
const rollbackPreview   = ref(null);
const rollbackForm      = useForm({ scope: '', reason: '' });

const hasPaidItems = computed(() => props.items.some(i => i.status === 'paid'));

function openRollbackModal() {
  rollbackStep.value   = 'config';
  rollbackScope.value  = 'payment_only';
  rollbackReason.value = '';
  rollbackPreview.value = null;
  showRollbackModal.value = true;
}

function closeRollback() {
  showRollbackModal.value = false;
}

async function fetchPreview() {
  previewLoading.value = true;
  try {
    const res = await fetch(route('accounting.payrolls.rollback-preview', props.payroll.id), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        'Accept': 'application/json',
      },
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error ?? data.message ?? `Lỗi server (HTTP ${res.status})`);
    rollbackPreview.value = data;
    rollbackStep.value = 'preview';
  } catch (e) {
    alert('Lỗi khi tải preview: ' + e.message);
  } finally {
    previewLoading.value = false;
  }
}

function submitRollback() {
  rollbackForm.scope  = rollbackScope.value;
  rollbackForm.reason = rollbackReason.value;
  rollbackForm.post(
    route('accounting.payrolls.rollback', props.payroll.id),
    { onSuccess: () => { showRollbackModal.value = false; } }
  );
}

// Pay modal
const showPayModal = ref(false);
const payForm = useForm({ fund_id: '', payment_date: '', actual_amount: 0 });
const cashFunds = computed(() => props.funds.filter(f => f.type === 'cash'));
const bankFunds = computed(() => props.funds.filter(f => f.type === 'bank'));

function openPayModal(item) {
  activeItem.value = item;
  payForm.fund_id = '';
  payForm.payment_date = new Date().toISOString().slice(0, 10);
  payForm.actual_amount = item.thuc_linh;
  showPayModal.value = true;
}

function submitPay() {
  payForm.post(
    route('accounting.payrolls.items.pay', { payroll: props.payroll.id, item: activeItem.value.id }),
    { onSuccess: () => { showPayModal.value = false; } }
  );
}
</script>

<style>
.print-only { display: none; }

@page { size: A4 landscape; margin: 8mm; }

@media print {
  nav, aside, .no-print, button { display: none !important; }
  .print-only { display: block !important; }
  #payroll-table-container { overflow: visible !important; }
  #payroll-table-container table {
    font-size: 9px !important;
    min-width: 0 !important;
    width: 100% !important;
  }
  #payroll-table-container td,
  #payroll-table-container th {
    white-space: normal !important;
    word-break: break-word;
  }
  body { padding: 0; margin: 0; }
  tr { page-break-inside: avoid; }
}
</style>
