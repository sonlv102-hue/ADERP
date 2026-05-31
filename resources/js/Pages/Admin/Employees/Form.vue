<template>
  <AppLayout>
    <div class="max-w-2xl mx-auto space-y-6">
      <div class="flex items-center gap-3">
        <Link :href="route('admin.employees.index')" class="text-gray-400 hover:text-gray-600">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">
          {{ employee ? 'Cập nhật cán bộ' : 'Thêm cán bộ mới' }}
        </h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <!-- Mã & Tên -->
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="form-label">Mã nhân viên <span class="text-red-500">*</span></label>
            <input v-model="form.code" type="text" class="form-input"
              :class="{ 'border-red-500': form.errors.code }"
              :disabled="!!employee" />
            <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
          </div>
          <div>
            <label class="form-label">Họ và tên <span class="text-red-500">*</span></label>
            <input v-model="form.name" type="text" class="form-input"
              :class="{ 'border-red-500': form.errors.name }" />
            <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
          </div>
        </div>

        <!-- Phòng ban & Chức vụ -->
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="form-label">Phòng ban</label>
            <input v-model="form.department" type="text" class="form-input" placeholder="VD: Kỹ thuật, Kinh doanh..." />
          </div>
          <div>
            <label class="form-label">Chức vụ</label>
            <input v-model="form.position" type="text" class="form-input" placeholder="VD: Trưởng phòng, Kỹ sư..." />
          </div>
        </div>

        <!-- Điện thoại & Email -->
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="form-label">Điện thoại</label>
            <input v-model="form.phone" type="text" class="form-input" />
          </div>
          <div>
            <label class="form-label">Email</label>
            <input v-model="form.email" type="email" class="form-input"
              :class="{ 'border-red-500': form.errors.email }" />
            <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
          </div>
        </div>

        <!-- Ngày sinh & Giới tính -->
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="form-label">Ngày sinh</label>
            <input v-model="form.birth_date" type="date" class="form-input" />
          </div>
          <div>
            <label class="form-label">Giới tính</label>
            <select v-model="form.gender" class="form-input">
              <option value="">— Chọn —</option>
              <option value="male">Nam</option>
              <option value="female">Nữ</option>
            </select>
          </div>
        </div>

        <!-- Ngày vào làm & Loại HĐ -->
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="form-label">Ngày vào làm</label>
            <input v-model="form.hire_date" type="date" class="form-input" />
          </div>
          <div>
            <label class="form-label">Loại hợp đồng <span class="text-red-500">*</span></label>
            <select v-model="form.employment_type" class="form-input">
              <option v-for="t in employmentTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
          </div>
        </div>

        <!-- Trạng thái -->
        <div>
          <label class="form-label">Trạng thái <span class="text-red-500">*</span></label>
          <select v-model="form.status" class="form-input w-48">
            <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
        </div>

        <!-- Địa chỉ -->
        <div>
          <label class="form-label">Địa chỉ</label>
          <input v-model="form.address" type="text" class="form-input" />
        </div>

        <!-- Ghi chú -->
        <div>
          <label class="form-label">Ghi chú</label>
          <textarea v-model="form.notes" rows="3" class="form-input" />
        </div>

        <div class="flex justify-end gap-3 pt-2">
          <Link :href="route('admin.employees.index')"
            class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-50">
            Hủy
          </Link>
          <button type="submit" :disabled="form.processing"
            class="px-5 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg text-sm font-medium disabled:opacity-50">
            {{ employee ? 'Cập nhật' : 'Thêm mới' }}
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

const form = useForm({
  code:             props.employee?.code            ?? props.nextCode ?? '',
  name:             props.employee?.name            ?? '',
  department:       props.employee?.department      ?? '',
  position:         props.employee?.position        ?? '',
  phone:            props.employee?.phone           ?? '',
  email:            props.employee?.email           ?? '',
  birth_date:       props.employee?.birth_date      ?? '',
  gender:           props.employee?.gender          ?? '',
  hire_date:        props.employee?.hire_date       ?? '',
  status:           props.employee?.status          ?? 'active',
  employment_type:  props.employee?.employment_type ?? 'full_time',
  address:          props.employee?.address         ?? '',
  notes:            props.employee?.notes           ?? '',
});

const submit = () => {
  if (props.employee) {
    form.put(route('admin.employees.update', props.employee.id));
  } else {
    form.post(route('admin.employees.store'));
  }
};
</script>
