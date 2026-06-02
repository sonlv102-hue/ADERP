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

        <!-- ── Section 3: Lương & Thuế TNCN ── -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="px-5 py-3.5 bg-gray-50 border-b border-gray-200 flex items-center gap-2">
            <span class="p-1.5 bg-green-100 rounded-md">
              <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </span>
            <h2 class="text-sm font-semibold text-gray-700">Lương & Thuế TNCN</h2>
            <span class="ml-auto text-xs text-gray-400">Dùng cho tính bảng lương hàng tháng</span>
          </div>
          <div class="p-5 space-y-4">
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Lương cơ bản / tháng</label>
                <div class="relative">
                  <input v-model.number="form.base_salary" type="number" min="0" step="100000"
                    class="w-full border border-gray-300 rounded-lg pl-3 pr-10 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent text-right font-mono"
                    placeholder="0" />
                  <span class="absolute inset-y-0 right-3 flex items-center text-xs text-gray-400 font-medium pointer-events-none">₫</span>
                </div>
                <p v-if="form.base_salary > 0" class="mt-1 text-xs text-gray-500">
                  {{ formatVnd(form.base_salary) }}
                </p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Phụ cấp / tháng</label>
                <div class="relative">
                  <input v-model.number="form.allowance" type="number" min="0" step="100000"
                    class="w-full border border-gray-300 rounded-lg pl-3 pr-10 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent text-right font-mono"
                    placeholder="0" />
                  <span class="absolute inset-y-0 right-3 flex items-center text-xs text-gray-400 font-medium pointer-events-none">₫</span>
                </div>
                <p v-if="form.allowance > 0" class="mt-1 text-xs text-gray-500">
                  {{ formatVnd(form.allowance) }}
                </p>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Số người phụ thuộc (NPT)</label>
                <input v-model.number="form.dependents_count" type="number" min="0" max="20"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                  placeholder="0" />
                <p class="mt-1 text-xs text-gray-400">
                  Giảm trừ gia cảnh: 4,400,000 ₫/NPT/tháng
                </p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Mã số thuế TNCN</label>
                <input v-model="form.pit_tax_code" type="text" maxlength="20"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent font-mono tracking-wider"
                  placeholder="8 1 2 3 4 5 6 7 8 9" />
                <p class="mt-1 text-xs text-gray-400">10 chữ số — theo đăng ký thuế cá nhân</p>
              </div>
            </div>

            <!-- Tổng lương preview -->
            <div v-if="form.base_salary > 0 || form.allowance > 0"
              class="bg-green-50 border border-green-200 rounded-lg px-4 py-3 flex items-center justify-between">
              <span class="text-sm text-green-700 font-medium">Tổng thu nhập (Gross)</span>
              <span class="text-base font-bold text-green-800 font-mono">
                {{ formatVnd((form.base_salary || 0) + (form.allowance || 0)) }}
              </span>
            </div>
          </div>
        </div>

        <!-- ── Section 4: Địa chỉ & Ghi chú ── -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="px-5 py-3.5 bg-gray-50 border-b border-gray-200 flex items-center gap-2">
            <span class="p-1.5 bg-purple-100 rounded-md">
              <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
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
              <path class="opacity-75" fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            {{ form.processing ? 'Đang lưu...' : (employee ? 'Cập nhật' : 'Thêm mới') }}
          </button>
        </div>

      </form>
    </div>
  </AppLayout>
</template>

<script setup>
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
  code:              props.employee?.code             ?? props.nextCode ?? '',
  name:              props.employee?.name             ?? '',
  department:        props.employee?.department       ?? '',
  position:          props.employee?.position         ?? '',
  phone:             props.employee?.phone            ?? '',
  email:             props.employee?.email            ?? '',
  birth_date:        props.employee?.birth_date       ?? '',
  gender:            props.employee?.gender           ?? '',
  hire_date:         props.employee?.hire_date        ?? '',
  status:            props.employee?.status           ?? 'active',
  employment_type:   props.employee?.employment_type  ?? 'full_time',
  base_salary:       props.employee?.base_salary      ?? 0,
  allowance:         props.employee?.allowance        ?? 0,
  dependents_count:  props.employee?.dependents_count ?? 0,
  pit_tax_code:      props.employee?.pit_tax_code     ?? '',
  address:           props.employee?.address          ?? '',
  notes:             props.employee?.notes            ?? '',
});

const submit = () => {
  if (props.employee) {
    form.put(route('admin.employees.update', props.employee.id));
  } else {
    form.post(route('admin.employees.store'));
  }
};
</script>
