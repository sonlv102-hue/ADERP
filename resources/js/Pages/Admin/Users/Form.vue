<template>
  <AppLayout>
    <div class="max-w-2xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('admin.users.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ user ? 'Sửa tài khoản' : 'Tạo tài khoản mới' }}</h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Họ tên <span class="text-red-500">*</span></label>
            <input v-model="form.name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.name }" />
            <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
            <input v-model="form.email" type="email" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.email }" />
            <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mật khẩu {{ user ? '(để trống = không đổi)' : '*' }}</label>
            <input v-model="form.password" type="password" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.password }" />
            <p v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Xác nhận mật khẩu</label>
            <input v-model="form.password_confirmation" type="password" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Số điện thoại</label>
            <input v-model="form.phone" type="tel" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Vai trò <span class="text-red-500">*</span></label>
            <select v-model="form.role" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.role }">
              <option value="">-- Chọn vai trò --</option>
              <option v-for="r in roles" :key="r" :value="r">{{ r }}</option>
            </select>
            <p v-if="form.errors.role" class="mt-1 text-xs text-red-600">{{ form.errors.role }}</p>
          </div>
        </div>

        <div v-if="user" class="flex items-center gap-2">
          <input v-model="form.is_active" id="is_active" type="checkbox" class="h-4 w-4 text-primary-600 rounded border-gray-300" />
          <label for="is_active" class="text-sm text-gray-700">Tài khoản đang hoạt động</label>
        </div>

        <!-- Salary & Tax fields (visible only when editing) -->
        <div v-if="user" class="border-t border-gray-100 pt-4">
          <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">Lương & Thuế</h3>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Lương cơ bản (₫)</label>
              <input v-model.number="form.base_salary" type="number" min="0" step="any"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none font-mono text-right" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Phụ cấp (₫)</label>
              <input v-model.number="form.allowance" type="number" min="0" step="any"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none font-mono text-right" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Số người phụ thuộc</label>
              <input v-model.number="form.dependents_count" type="number" min="0" max="10"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
              <p class="mt-1 text-xs text-gray-400">Giảm trừ: 4,400,000 ₫/người/tháng</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Mã số thuế cá nhân</label>
              <input v-model="form.pit_tax_code" type="text" maxlength="20" placeholder="VD: 8123456789"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none font-mono" />
            </div>
          </div>
        </div>

        <div class="flex gap-3 pt-2">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : (user ? 'Cập nhật' : 'Tạo tài khoản') }}
          </button>
          <Link :href="route('admin.users.index')"
            class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  user: { type: Object, default: null },
  roles: Array,
});

const form = useForm({
  name:                 props.user?.name                 ?? '',
  email:                props.user?.email                ?? '',
  password:             '',
  password_confirmation:'',
  phone:                props.user?.phone                ?? '',
  role:                 props.user?.role                 ?? '',
  is_active:            props.user?.is_active            ?? true,
  base_salary:          props.user?.base_salary          ?? 0,
  allowance:            props.user?.allowance            ?? 0,
  dependents_count:     props.user?.dependents_count     ?? 0,
  pit_tax_code:         props.user?.pit_tax_code         ?? '',
});

const submit = () => {
  if (props.user) {
    form.put(route('admin.users.update', props.user.id));
  } else {
    form.post(route('admin.users.store'));
  }
};
</script>
