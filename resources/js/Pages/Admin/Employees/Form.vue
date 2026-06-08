<template>
  <AppLayout>
    <div class="max-w-3xl mx-auto space-y-5">

      <!-- Header -->
      <div class="flex items-center gap-3">
        <Link :href="route('admin.employees.index')"
          class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <div>
          <h1 class="text-xl font-bold text-gray-900">
            {{ employee ? 'Cập nhật cán bộ' : 'Thêm cán bộ mới' }}
          </h1>
          <p class="text-sm text-gray-500 mt-0.5">
            {{ employee ? employee.code : 'Mã sẽ được tự động tạo: ' + (nextCode ?? '—') }}
          </p>
        </div>
      </div>

      <form @submit.prevent="submit" class="space-y-4">

        <!-- ── Section 1: Thông tin cơ bản ── -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="px-5 py-3.5 bg-gray-50 border-b border-gray-200 flex items-center gap-2">
            <span class="p-1.5 bg-primary-100 rounded-md">
              <svg class="w-4 h-4 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
              </svg>
            </span>
            <h2 class="text-sm font-semibold text-gray-700">Thông tin cơ bản</h2>
          </div>
          <div class="p-5 space-y-4">
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                  Mã nhân viên <span class="text-red-500">*</span>
                </label>
                <input v-model="form.code" type="text"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent bg-gray-50 font-mono"
                  :class="{ 'border-red-400 bg-red-50': form.errors.code }"
                  :disabled="!!employee" :placeholder="nextCode ?? 'NV-XXXX'" />
                <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
                <p v-else-if="!!employee" class="mt-1 text-xs text-gray-400">Mã không thể thay đổi sau khi tạo</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                  Họ và tên <span class="text-red-500">*</span>
                </label>
                <input v-model="form.name" type="text"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                  :class="{ 'border-red-400 bg-red-50': form.errors.name }"
                  placeholder="Nguyễn Văn A" />
                <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Phòng ban</label>
                <input v-model="form.department" type="text"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                  placeholder="VD: Kỹ thuật, Kinh doanh..." />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Chức vụ</label>
                <input v-model="form.position" type="text"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                  placeholder="VD: Kỹ sư, Trưởng phòng..." />
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Điện thoại</label>
                <input v-model="form.phone" type="text"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                  placeholder="0901 234 567" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Email</label>
                <input v-model="form.email" type="email"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                  :class="{ 'border-red-400 bg-red-50': form.errors.email }"
                  placeholder="nhanvien@company.com" />
                <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Ngày sinh</label>
                <input v-model="form.birth_date" type="date"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Giới tính</label>
                <select v-model="form.gender"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                  <option value="">— Chọn —</option>
                  <option value="male">Nam</option>
                  <option value="female">Nữ</option>
                </select>
              </div>
            </div>
          </div>
        </div>

        <!-- ── Section 2: Hợp đồng lao động ── -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="px-5 py-3.5 bg-gray-50 border-b border-gray-200 flex items-center gap-2">
            <span class="p-1.5 bg-amber-100 rounded-md">
              <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
            </span>
            <h2 class="text-sm font-semibold text-gray-700">Hợp đồng lao động</h2>
          </div>
          <div class="p-5">
            <div class="grid grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Ngày vào làm</label>
                <input v-model="form.hire_date" type="date"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                  Loại hợp đồng <span class="text-red-500">*</span>
                </label>
                <select v-model="form.employment_type"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                  <option v-for="t in employmentTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                  Trạng thái <span class="text-red-500">*</span>
                </label>
                <select v-model="form.status"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                  <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
              </div>
            </div>
          </div>
        </div>

        <!-- ── Section 3: Lương cơ bản + BHXH ── -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="px-5 py-3.5 bg-gray-50 border-b border-gray-200 flex items-center gap-2">
            <span class="p-1.5 bg-green-100 rounded-md">
              <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </span>
            <h2 class="text-sm font-semibold text-gray-700">Lương cơ bản & Bảo hiểm</h2>
            <span class="ml-auto text-xs text-green-700 font-medium bg-green-100 px-2 py-0.5 rounded-full">Dùng tính BHXH</span>
          </div>
          <div class="p-5 space-y-4">
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Lương cơ bản / tháng</label>
                <div class="relative">
                  <input v-model.number="form.base_salary" type="number" min="0" step="any"
                    class="w-full border border-gray-300 rounded-lg pl-3 pr-10 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent text-right font-mono"
                    placeholder="0" />
                  <span class="absolute inset-y-0 right-3 flex items-center text-xs text-gray-400 pointer-events-none">₫</span>
                </div>
                <p v-if="form.base_salary > 0" class="mt-1 text-xs text-green-600 font-medium">
                  {{ formatVnd(form.base_salary) }} — Lương đóng BHXH
                </p>
              </div>
              <div class="flex flex-col justify-center">
                <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                  <p class="text-xs text-green-700 font-semibold mb-1">Trích BHXH/BHYT/BHTN (NV)</p>
                  <p class="text-xs text-green-600">BHXH 8%: <span class="font-mono font-bold">{{ formatVnd(Math.round(Math.min(form.base_salary || 0, 46800000) * 0.08)) }}</span></p>
                  <p class="text-xs text-green-600">BHYT 1.5%: <span class="font-mono font-bold">{{ formatVnd(Math.round(Math.min(form.base_salary || 0, 46800000) * 0.015)) }}</span></p>
                  <p class="text-xs text-green-600">BHTN 1%: <span class="font-mono font-bold">{{ formatVnd(Math.round(Math.min(form.base_salary || 0, 46800000) * 0.01)) }}</span></p>
                </div>
              </div>
            </div>

            <!-- BHXH switch -->
            <div class="flex items-center gap-3 bg-gray-50 rounded-lg px-4 py-3">
              <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" v-model="form.insurance_subject" class="sr-only peer" />
                <div class="w-10 h-5 bg-gray-300 peer-focus:outline-none rounded-full peer peer-checked:bg-green-500 transition-colors"></div>
                <div class="absolute left-0.5 top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></div>
              </label>
              <div>
                <p class="text-sm font-medium text-gray-800">Đóng BHXH/BHYT/BHTN</p>
                <p class="text-xs text-gray-500">
                  {{ form.insurance_subject ? 'Đang tính bảo hiểm (HĐLĐ toàn thời gian ≥ 3 tháng)' : 'Không tính bảo hiểm (thời vụ, thử việc &lt; 3 tháng)' }}
                </p>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Số người phụ thuộc (NPT)</label>
                <input v-model.number="form.dependents_count" type="number" min="0" max="20"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                  placeholder="0" />
                <p class="mt-1 text-xs text-gray-400">Giảm trừ: 4,400,000 ₫/NPT/tháng</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Mã số thuế TNCN</label>
                <input v-model="form.pit_tax_code" type="text" maxlength="20"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent font-mono tracking-wider"
                  placeholder="8 1 2 3 4 5 6 7 8 9" />
                <p class="mt-1 text-xs text-gray-400">10 chữ số — theo đăng ký thuế cá nhân</p>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1.5">Ngày công chuẩn / tháng</label>
              <input v-model.number="form.standard_days" type="number" min="20" max="31"
                class="w-40 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent text-center"
                placeholder="26" />
              <p class="mt-1 text-xs text-gray-400">Dùng để tính lương theo ngày công thực tế</p>
            </div>
          </div>
        </div>

        <!-- ── Section 4a: Phụ cấp lương (TÍNH BHXH) ── -->
        <div class="bg-white rounded-xl border border-green-200 overflow-hidden">
          <div class="px-5 py-3.5 bg-green-50 border-b border-green-200 flex items-center gap-2">
            <span class="p-1.5 bg-green-200 rounded-md">
              <svg class="w-4 h-4 text-green-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </span>
            <div>
              <h2 class="text-sm font-semibold text-green-800">Phụ cấp lương (Tính BHXH/BHYT/BHTN)</h2>
              <p class="text-xs text-green-600">PC ổn định, ghi trong HĐLĐ — cộng vào căn cứ đóng bảo hiểm</p>
            </div>
          </div>
          <div class="p-5 space-y-4">
            <div class="bg-green-50 border border-green-200 rounded-lg px-4 py-2.5 text-xs text-green-800">
              <strong>Nghị định 158/2025/NĐ-CP (01/07/2025):</strong> Phụ cấp chức vụ, trách nhiệm, thâm niên, chuyên môn và khoản bổ sung xác định được số tiền cụ thể, trả thường xuyên ổn định → <strong>phải tính vào căn cứ đóng BHXH</strong>.
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                  PC Trách nhiệm / Chức vụ
                  <span class="ml-1 text-xs font-normal text-green-600 bg-green-100 px-1.5 rounded">tính BHXH</span>
                </label>
                <div class="relative">
                  <input v-model.number="form.allowance_responsibility" type="number" min="0" step="any"
                    class="w-full border border-green-300 rounded-lg pl-3 pr-10 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500 text-right font-mono bg-green-50/30"
                    placeholder="0" />
                  <span class="absolute inset-y-0 right-3 flex items-center text-xs text-gray-400 pointer-events-none">₫</span>
                </div>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                  Phụ cấp lương cố định khác
                  <span class="ml-1 text-xs font-normal text-green-600 bg-green-100 px-1.5 rounded">tính BHXH</span>
                </label>
                <div class="relative">
                  <input v-model.number="form.allowance" type="number" min="0" step="any"
                    class="w-full border border-green-300 rounded-lg pl-3 pr-10 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500 text-right font-mono bg-green-50/30"
                    placeholder="0" />
                  <span class="absolute inset-y-0 right-3 flex items-center text-xs text-gray-400 pointer-events-none">₫</span>
                </div>
                <p class="mt-1 text-xs text-gray-400">Thâm niên, chuyên môn, khoản bổ sung cố định HĐLĐ…</p>
              </div>
            </div>

            <!-- BHXH base preview -->
            <div v-if="bhxhBase > 0" class="bg-green-50 border border-green-200 rounded-lg px-4 py-2.5">
              <p class="text-xs text-green-700">
                <span class="font-semibold">Căn cứ đóng BHXH:</span>
                <span class="font-mono ml-1">{{ formatVnd(form.base_salary || 0) }}</span> + <span class="font-mono">{{ formatVnd(bhxhAllowancesTotal) }}</span>
                = <span class="font-mono font-bold">{{ formatVnd(bhxhBase) }}</span>
                <span v-if="bhxhBase >= 46800000" class="ml-1 text-orange-600">(đã capped tại 46,800,000 ₫)</span>
              </p>
            </div>
          </div>
        </div>

        <!-- ── Section 4b: Trợ cấp & phúc lợi (KHÔNG tính BHXH) ── -->
        <div class="bg-white rounded-xl border border-blue-200 overflow-hidden">
          <div class="px-5 py-3.5 bg-blue-50 border-b border-blue-200 flex items-center gap-2">
            <span class="p-1.5 bg-blue-200 rounded-md">
              <svg class="w-4 h-4 text-blue-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
              </svg>
            </span>
            <div>
              <h2 class="text-sm font-semibold text-blue-800">Trợ cấp & Phúc lợi (Không tính BHXH)</h2>
              <p class="text-xs text-blue-600">Hỗ trợ phúc lợi — không vào căn cứ BHXH, nhưng tính thu nhập chịu thuế TNCN</p>
            </div>
          </div>
          <div class="p-5 space-y-4">
            <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-2.5 text-xs text-blue-800">
              Ăn trưa, xăng xe, điện thoại… <strong>không phải phụ cấp lương</strong> mà là hỗ trợ/phúc lợi — ghi thành mục riêng trong HĐLĐ/quy chế lương để được loại trừ khỏi căn cứ BHXH.
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                  Hỗ trợ ăn trưa / ăn giữa ca
                  <span class="ml-1 text-xs font-normal text-blue-600 bg-blue-100 px-1.5 rounded">không BHXH</span>
                </label>
                <div class="relative">
                  <input v-model.number="form.allowance_lunch" type="number" min="0" step="any"
                    class="w-full border border-blue-300 rounded-lg pl-3 pr-10 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 text-right font-mono bg-blue-50/30"
                    placeholder="0" />
                  <span class="absolute inset-y-0 right-3 flex items-center text-xs text-gray-400 pointer-events-none">₫</span>
                </div>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                  Hỗ trợ xăng xe / đi lại
                  <span class="ml-1 text-xs font-normal text-blue-600 bg-blue-100 px-1.5 rounded">không BHXH</span>
                </label>
                <div class="relative">
                  <input v-model.number="form.allowance_transport" type="number" min="0" step="any"
                    class="w-full border border-blue-300 rounded-lg pl-3 pr-10 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 text-right font-mono bg-blue-50/30"
                    placeholder="0" />
                  <span class="absolute inset-y-0 right-3 flex items-center text-xs text-gray-400 pointer-events-none">₫</span>
                </div>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                  Hỗ trợ điện thoại / liên lạc
                  <span class="ml-1 text-xs font-normal text-blue-600 bg-blue-100 px-1.5 rounded">không BHXH</span>
                </label>
                <div class="relative">
                  <input v-model.number="form.allowance_phone" type="number" min="0" step="any"
                    class="w-full border border-blue-300 rounded-lg pl-3 pr-10 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 text-right font-mono bg-blue-50/30"
                    placeholder="0" />
                  <span class="absolute inset-y-0 right-3 flex items-center text-xs text-gray-400 pointer-events-none">₫</span>
                </div>
              </div>
              <div class="flex items-center">
                <div class="bg-blue-50 rounded-lg p-3 w-full">
                  <p class="text-xs text-blue-700 font-medium mb-1">PC Hiệu quả CV (KPI)</p>
                  <p class="text-xs text-blue-600">Biến động theo kết quả công việc → nhập từng tháng trong bảng lương</p>
                </div>
              </div>
            </div>

            <!-- Tổng thu nhập preview -->
            <div class="bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 space-y-1.5">
              <div class="flex items-center justify-between text-sm">
                <span class="text-gray-600">Lương cơ bản + PC lương (tính BHXH)</span>
                <span class="font-mono text-green-700">{{ formatVnd((form.base_salary || 0) + bhxhAllowancesTotal) }}</span>
              </div>
              <div class="flex items-center justify-between text-sm">
                <span class="text-gray-600">Trợ cấp phúc lợi (không BHXH)</span>
                <span class="font-mono text-blue-700">+ {{ formatVnd(nonBhxhTotal) }}</span>
              </div>
              <div class="border-t border-gray-300 pt-1.5 flex items-center justify-between">
                <span class="text-sm font-semibold text-gray-700">Tổng thu nhập (Gross)</span>
                <span class="text-base font-bold text-green-800 font-mono">{{ formatVnd((form.base_salary || 0) + totalAllowances) }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- ── Section 5: Địa chỉ & Ghi chú ── -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="px-5 py-3.5 bg-gray-50 border-b border-gray-200 flex items-center gap-2">
            <span class="p-1.5 bg-purple-100 rounded-md">
              <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
            </span>
            <h2 class="text-sm font-semibold text-gray-700">Địa chỉ & Ghi chú</h2>
          </div>
          <div class="p-5 space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1.5">Địa chỉ thường trú</label>
              <input v-model="form.address" type="text"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                placeholder="Số nhà, đường, phường/xã, quận/huyện, tỉnh/thành phố" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1.5">Ghi chú</label>
              <textarea v-model="form.notes" rows="3"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent resize-none"
                placeholder="Ghi chú nội bộ về nhân viên..." />
            </div>
          </div>
        </div>

        <!-- Actions -->
        <div class="flex justify-end gap-3 pb-2">
          <Link :href="route('admin.employees.index')"
            class="px-5 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors">
            Hủy
          </Link>
          <button type="submit" :disabled="form.processing"
            class="px-6 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg text-sm font-semibold disabled:opacity-50 transition-colors flex items-center gap-2">
            <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            {{ form.processing ? 'Đang lưu...' : (employee ? 'Cập nhật' : 'Thêm mới') }}
          </button>
        </div>

      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  employee: Object,
  nextCode: String,
  statuses: Array,
  employmentTypes: Array,
});

function formatVnd(value) {
  return new Intl.NumberFormat('vi-VN').format(value || 0) + ' ₫';
}

const form = useForm({
  code:                     props.employee?.code                     ?? props.nextCode ?? '',
  name:                     props.employee?.name                     ?? '',
  department:               props.employee?.department               ?? '',
  position:                 props.employee?.position                 ?? '',
  phone:                    props.employee?.phone                    ?? '',
  email:                    props.employee?.email                    ?? '',
  birth_date:               props.employee?.birth_date               ?? '',
  gender:                   props.employee?.gender                   ?? '',
  hire_date:                props.employee?.hire_date                ?? '',
  status:                   props.employee?.status                   ?? 'active',
  employment_type:          props.employee?.employment_type          ?? 'full_time',
  base_salary:              props.employee?.base_salary              ?? 0,
  allowance:                props.employee?.allowance                ?? 0,
  allowance_responsibility: props.employee?.allowance_responsibility ?? 0,
  allowance_lunch:          props.employee?.allowance_lunch          ?? 0,
  allowance_phone:          props.employee?.allowance_phone          ?? 0,
  allowance_transport:      props.employee?.allowance_transport      ?? 0,
  insurance_subject:        props.employee?.insurance_subject        ?? true,
  standard_days:            props.employee?.standard_days            ?? 26,
  dependents_count:         props.employee?.dependents_count         ?? 0,
  pit_tax_code:             props.employee?.pit_tax_code             ?? '',
  address:                  props.employee?.address                  ?? '',
  notes:                    props.employee?.notes                    ?? '',
});

// PC lương (tính BHXH): trách nhiệm + cố định khác
const bhxhAllowancesTotal = computed(() =>
  (form.allowance_responsibility || 0) + (form.allowance || 0)
);
// Căn cứ BHXH = base + bhxh_allowances (cap 46.8M)
const bhxhBase = computed(() =>
  Math.min((form.base_salary || 0) + bhxhAllowancesTotal.value, 46_800_000)
);
// Hỗ trợ phúc lợi (không BHXH): ăn trưa + xăng xe + ĐT
const nonBhxhTotal = computed(() =>
  (form.allowance_lunch      || 0)
  + (form.allowance_transport || 0)
  + (form.allowance_phone     || 0)
);
const totalAllowances = computed(() => bhxhAllowancesTotal.value + nonBhxhTotal.value);

const submit = () => {
  if (props.employee) {
    form.put(route('admin.employees.update', props.employee.id));
  } else {
    form.post(route('admin.employees.store'));
  }
};
</script>
