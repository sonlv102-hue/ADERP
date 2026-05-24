<template>
  <AppLayout>
    <div class="max-w-2xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('warehouse.warehouses.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ warehouse ? 'Sửa kho' : 'Thêm kho mới' }}</h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Tên kho <span class="text-red-500">*</span></label>
          <input v-model="form.name" type="text"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
            :class="{ 'border-red-500': form.errors.name }" />
          <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Địa chỉ</label>
          <textarea v-model="form.address" rows="2"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
            :class="{ 'border-red-500': form.errors.address }" />
          <p v-if="form.errors.address" class="mt-1 text-xs text-red-600">{{ form.errors.address }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Số điện thoại</label>
          <input v-model="form.phone" type="tel"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
            :class="{ 'border-red-500': form.errors.phone }" />
          <p v-if="form.errors.phone" class="mt-1 text-xs text-red-600">{{ form.errors.phone }}</p>
        </div>

        <div v-if="warehouse" class="flex items-center gap-2">
          <input v-model="form.is_active" id="is_active" type="checkbox"
            class="h-4 w-4 text-primary-600 rounded border-gray-300" />
          <label for="is_active" class="text-sm text-gray-700">Kho đang hoạt động</label>
        </div>

        <div class="flex gap-3 pt-2">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : (warehouse ? 'Cập nhật' : 'Thêm kho') }}
          </button>
          <Link :href="route('warehouse.warehouses.index')"
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
  warehouse: { type: Object, default: null },
});

const form = useForm({
  name: props.warehouse?.name ?? '',
  address: props.warehouse?.address ?? '',
  phone: props.warehouse?.phone ?? '',
  is_active: props.warehouse?.is_active ?? true,
});

const submit = () => {
  if (props.warehouse) {
    form.put(route('warehouse.warehouses.update', props.warehouse.id));
  } else {
    form.post(route('warehouse.warehouses.store'));
  }
};
</script>
