<template>
  <AppLayout>
    <div class="max-w-lg">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('admin.shareholders.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ shareholder ? 'Sửa thành viên' : 'Thêm thành viên' }}</h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Họ và tên <span class="text-red-500">*</span></label>
            <input v-model="form.name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-primary-500"
              :class="{ 'border-red-500': form.errors.name }" />
            <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">CCCD / CMND</label>
            <input v-model="form.id_number" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mã số thuế cá nhân</label>
            <input v-model="form.tax_number" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-primary-500" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Điện thoại</label>
            <input v-model="form.phone" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <input v-model="form.email" type="email" class="w-full px-3 py-2 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-primary-500"
              :class="{ 'border-red-500': form.errors.email }" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">% Vốn góp</label>
            <input v-model.number="form.share_percentage" type="number" min="0" max="100" step="0.0001"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-primary-500" />
            <p v-if="form.errors.share_percentage" class="mt-1 text-xs text-red-600">{{ form.errors.share_percentage }}</p>
          </div>
          <div class="flex items-center gap-3 mt-6">
            <input id="is_active" v-model="form.is_active" type="checkbox" class="w-4 h-4 accent-primary-600" />
            <label for="is_active" class="text-sm text-gray-700">Đang hoạt động</label>
          </div>

          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Địa chỉ</label>
            <textarea v-model="form.address" rows="2"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
            <textarea v-model="form.notes" rows="2"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
        </div>

        <div class="flex gap-3 pt-2">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : (shareholder ? 'Cập nhật' : 'Thêm mới') }}
          </button>
          <Link :href="route('admin.shareholders.index')"
            class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({ shareholder: Object });

const form = useForm({
  name:             props.shareholder?.name             ?? '',
  id_number:        props.shareholder?.id_number        ?? '',
  tax_number:       props.shareholder?.tax_number       ?? '',
  phone:            props.shareholder?.phone            ?? '',
  email:            props.shareholder?.email            ?? '',
  address:          props.shareholder?.address          ?? '',
  share_percentage: props.shareholder?.share_percentage ?? null,
  is_active:        props.shareholder?.is_active        ?? true,
  notes:            props.shareholder?.notes            ?? '',
});

function submit() {
  if (props.shareholder) {
    form.put(route('admin.shareholders.update', props.shareholder.id));
  } else {
    form.post(route('admin.shareholders.store'));
  }
}
</script>
