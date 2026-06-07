<template>
  <AppLayout>
    <div class="max-w-full space-y-4">

      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-3">
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

          <!-- Confirm (draft + not locked) -->
          <button v-if="payroll.status === 'draft' && !payroll.is_locked" @click="confirmPayroll"
            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-semibold shadow-sm">
            Xác nhận bảng lương
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
      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm">{{ $page.props.flash.success }}</div>
      <div v-if="$page.props.flash?.error"   class="bg-red-50   border border-red-200   text-red-800   rounded-xl px-4 py-3 text-sm">{{ $page.props.flash.error }}</div>

      <!-- Summary cards -->
      <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
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

      <!-- Full Payroll Table -->
      <div class="bg-white rounded-xl shadow-sm overflow-hidden" id="payroll-table-container">
        <div class="overflow-x-auto">
          <table class="w-full text-xs whitespace-nowrap border-collapse" style="min-width: 1800px">
            <thead>
              <!-- Row 1: column group headers -->
              <tr class="bg-primary-700 text-white text-center">
                <th rowspan="2" class="border border-primary-600 px-2 py-2 w-8">STT</th>
                <th rowspan="2" class="border border-primary-600 px-3 py-2 text-left min-w-[140px]">Họ và tên</th>
                <th rowspan="2" class="border border-primary-600 px-2 py-2 min-w-[90px]">Chức vụ</th>
                <th rowspan="2" class="border border-primary-600 px-2 py-2 min-w-[90px]">Lương<br/>Chính</th>
                <th colspan="5" class="border border-primary-600 px-2 py-1">Phụ cấp (không tính BHXH)</th>
                <th rowspan="2" class="border border-primary-600 px-2 py-2 min-w-[90px]">Tổng<br/>Thu Nhập</th>
                <th rowspan="2" class="border border-primary-600 px-2 py-2 w-14">Ngày<br/>công</th>
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
                <th rowspan="2" class="border border-primary-600 px-2 py-2 min-w-[90px] font-bold">Thực lĩnh</th>
                <th rowspan="2" v-if="payroll.status !== 'draft'" class="border border-primary-600 px-2 py-2 w-16">TT</th>
                <th rowspan="2" v-if="payroll.status === 'draft'" class="border border-primary-600 px-2 py-2 w-10"></th>
              </tr>
              <tr class="bg-primary-600 text-white text-center">
                <th class="border border-primary-500 px-1 py-1">Trách<br/>nhiệm</th>
                <th class="border border-primary-500 px-1 py-1">Ăn<br/>trưa</th>
                <th class="border border-primary-500 px-1 py-1">Điện<br/>thoại</th>
                <th class="border border-primary-500 px-1 py-1">Xăng<br/>xe</th>
                <th class="border border-primary-500 px-1 py-1">HQ<br/>CV</th>
                <th class="border border-primary-500 px-1 py-1">BHXH<br/>17.5%</th>
                <th class="border border-primary-500 px-1 py-1">BHYT<br/>3%</th>
                <th class="border border-primary-500 px-1 py-1">BHTN<br/>1%</th>
                <th class="border border-primary-500 px-1 py-1 font-bold">Cộng<br/>21.5%</th>
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
                  <td class="border border-gray-200 px-2 py-1.5 font-bold text-gray-800 text-xs" colspan="3">
                    {{ deptName || 'Chưa phân phòng ban' }}
                  </td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-bold font-mono">{{ fv(sum(group, 'base_salary')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'allowance_responsibility')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'allowance_lunch')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'allowance_phone')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'allowance_transport')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ fv(sum(group, 'allowance_performance')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-bold font-mono">{{ fv(sum(group, 'gross_salary')) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-center font-mono">{{ sumInt(group, 'working_days') }}</td>
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
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono font-semibold">{{ fv(item.base_salary) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ item.allowance_responsibility ? fv(item.allowance_responsibility) : '' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ item.allowance_lunch        ? fv(item.allowance_lunch)        : '' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ item.allowance_phone        ? fv(item.allowance_phone)        : '' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ item.allowance_transport    ? fv(item.allowance_transport)    : '' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono">{{ item.allowance_performance  ? fv(item.allowance_performance)  : '' }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-right font-mono font-semibold">{{ fv(item.gross_salary) }}</td>
                  <td class="border border-gray-200 px-2 py-1.5 text-center font-mono">{{ item.working_days }}</td>
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
                <td colspan="3" class="border border-gray-300 px-3 py-2 text-sm font-bold text-gray-800">Tổng cộng</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono text-sm">{{ fv(payroll.total_base_salary) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono">{{ fv(sumItems('allowance_responsibility')) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono">{{ fv(sumItems('allowance_lunch')) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono">{{ fv(sumItems('allowance_phone')) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono">{{ fv(sumItems('allowance_transport')) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono">{{ fv(sumItems('allowance_performance')) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-right font-mono text-sm font-bold">{{ fv(payroll.total_gross) }}</td>
                <td class="border border-gray-300 px-2 py-2 text-center font-mono">{{ items.length * 26 }}</td>
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
                <td class="border border-gray-300 px-2 py-2 text-right font-mono text-lg font-bold text-primary-700">{{ fv(sumItems('thuc_linh')) }}</td>
                <td class="border border-gray-300" colspan="1"></td>
              </tr>
            </tfoot>
          </table>
        </div>
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
              <label class="form-label text-xs">Ngày công thực tế</label>
              <input type="number" v-model.number="editForm.working_days" min="0" max="31"
                class="form-input text-right text-sm" />
              <p class="text-xs text-gray-400 mt-0.5">Chuẩn: {{ activeItem.standard_days }}</p>
            </div>
          </div>

          <div class="border-b pb-1 flex gap-3">
            <p class="text-xs font-semibold text-green-700">Phụ cấp lương (tính BHXH): trách nhiệm, cố định khác</p>
            <p class="text-xs font-semibold text-blue-700 ml-auto">| Hỗ trợ phúc lợi (không BHXH): ăn trưa, xăng xe, ĐT, hiệu quả</p>
          </div>
          <div class="grid grid-cols-2 gap-3">
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
              <span>BHXH/BHYT/BHTN NV (10.5% × căn cứ BH):</span>
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
          <div class="text-center">
            <p class="text-xs text-gray-500 mb-1">Số tiền thực chi</p>
            <p class="text-2xl font-extrabold text-green-600 font-mono">{{ fv(activeItem.thuc_linh) }}</p>
            <p v-if="activeItem.advance > 0" class="text-xs text-gray-400 mt-1">
              Lương net {{ fv(activeItem.net_salary) }} - tạm ứng {{ fv(activeItem.advance) }}
            </p>
          </div>
          <div>
            <label class="form-label">Tài khoản ngân hàng chi lương</label>
            <select v-model="payForm.bank_account_id" required class="form-input">
              <option value="" disabled>-- Chọn tài khoản NH --</option>
              <option v-for="ba in bankAccounts" :key="ba.id" :value="ba.id">
                {{ ba.bank_name }} — {{ ba.account_number }} ({{ ba.name }})
              </option>
            </select>
          </div>
          <div class="flex gap-3">
            <button type="submit" :disabled="payForm.processing" class="btn-primary flex-1">Xác nhận chi tiền</button>
            <button type="button" @click="showPayModal = false" class="btn-secondary">Huỷ</button>
          </div>
        </form>
      </div>
    </div>

  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { usePermission } from '@/composables/usePermission';

const props = defineProps({ payroll: Object, items: Array, bankAccounts: Array });

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

// JS mirror of PitCalculatorService (Nghị định 158/2025)
const INS_CAP       = 46_800_000;
const PERSONAL_DED  = 11_000_000;
const DEPENDENT_DED = 4_400_000;
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
  working_days:             26,
  advance:                  0,
  insurance_subject:        true,
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

const previewGross   = computed(() => (editForm.base_salary || 0) + previewBhxhAllw.value + previewNonBhxh.value);
const previewInsEmp  = computed(() => calcInsEmpOnBase(editForm.base_salary || 0, previewBhxhAllw.value, editForm.insurance_subject));
const previewPit     = computed(() => calcPit(previewGross.value, previewInsEmp.value, editForm.dependents_count || 0));
const previewNet     = computed(() => previewGross.value - previewInsEmp.value - previewPit.value);
const previewInsBase = computed(() => calcInsBase(editForm.base_salary || 0, previewBhxhAllw.value, editForm.insurance_subject));

function openEditModal(item) {
  activeItem.value = item;
  editForm.base_salary              = item.base_salary;
  editForm.allowance_responsibility = item.allowance_responsibility;
  editForm.allowance_lunch          = item.allowance_lunch;
  editForm.allowance_phone          = item.allowance_phone;
  editForm.allowance_transport      = item.allowance_transport;
  editForm.allowance_performance    = item.allowance_performance;
  editForm.allowance                = item.allowance;
  editForm.bonus                    = item.bonus;
  editForm.dependents_count         = item.dependents_count;
  editForm.working_days             = item.working_days;
  editForm.advance                  = item.advance;
  editForm.insurance_subject        = item.insurance_subject;
  showEditModal.value = true;
}

function submitEdit() {
  editForm.put(
    route('accounting.payrolls.items.update', { payroll: props.payroll.id, item: activeItem.value.id }),
    { onSuccess: () => { showEditModal.value = false; } }
  );
}

// Pay modal
const showPayModal = ref(false);
const payForm = useForm({ bank_account_id: '' });

function openPayModal(item) {
  activeItem.value = item;
  payForm.bank_account_id = '';
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
@media print {
  /* Hide nav, sidebar, buttons, modals when printing */
  nav, aside, .no-print, button, a { display: none !important; }
  #payroll-table-container { overflow: visible !important; }
  table { font-size: 8px !important; }
  body { padding: 0; margin: 0; }
}
</style>
