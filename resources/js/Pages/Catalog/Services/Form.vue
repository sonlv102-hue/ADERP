<template>
  <AppLayout>
    <div class="max-w-2xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('catalog.services.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ service ? 'Sửa dịch vụ' : 'Thêm dịch vụ mới' }}</h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mã dịch vụ</label>
            <input v-model="form.code" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.code }" />
            <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tên dịch vụ <span class="text-red-500">*</span></label>
            <input v-model="form.name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.name }" />
            <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Đơn vị tính</label>
            <input v-model="form.unit" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.unit }" />
            <p v-if="form.errors.unit" class="mt-1 text-xs text-red-600">{{ form.errors.unit }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Giá dịch vụ</label>
            <input v-model="form.price" type="number" min="0" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.price }" />
            <p v-if="form.errors.price" class="mt-1 text-xs text-red-600">{{ form.errors.price }}</p>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Mô tả</label>
          <textarea v-model="form.description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
            :class="{ 'border-red-500': form.errors.description }" />
          <p v-if="form.errors.description" class="mt-1 text-xs text-red-600">{{ form.errors.description }}</p>
        </div>

        <div v-if="service" class="flex items-center gap-2">
          <input v-model="form.is_active" id="is_active" type="checkbox" class="h-4 w-4 text-primary-600 rounded border-gray-300" />
          <label for="is_active" class="text-sm text-gray-700">Dịch vụ đang hoạt động</label>
        </div>

        <div class="flex gap-3 pt-2">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : (service ? 'Cập nhật' : 'Thêm dịch vụ') }}
          </button>
          <Link :href="route('catalog.services.index')"
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
  service: { type: Object, default: null },
  nextCode: String,
});

const form = useForm({
  code: props.service?.code ?? props.nextCode ?? '',
  name: props.service?.name ?? '',
  unit: props.service?.unit ?? 'lần',
  price: props.service?.price ?? 0,
  description: props.service?.description ?? '',
  is_active: props.service?.is_active ?? true,
});

const submit = () => {
  if (props.service) {
    form.put(route('catalog.services.update', props.service.id));
  } else {
    form.post(route('catalog.services.store'));
  }
};
</script>
