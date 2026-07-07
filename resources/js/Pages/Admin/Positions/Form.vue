<template>
  <AppLayout>
    <div class="max-w-lg">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('admin.positions.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ position ? 'Sửa chức vụ' : 'Thêm chức vụ' }}</h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tên chức vụ <span class="text-red-500">*</span></label>
            <input v-model="form.name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-primary-500"
              :class="{ 'border-red-500': form.errors.name }" />
            <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
          </div>

          <div class="flex items-center gap-3 mt-6">
            <input id="is_active" v-model="form.is_active" type="checkbox" class="w-4 h-4 accent-primary-600" />
            <label for="is_active" class="text-sm text-gray-700">Đang hoạt động</label>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
            <textarea v-model="form.notes" rows="3"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
        </div>

        <div class="flex gap-3 pt-2">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : (position ? 'Cập nhật' : 'Thêm mới') }}
          </button>
          <Link :href="route('admin.positions.index')"
            class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({ position: Object });

const form = useForm({
  name:      props.position?.name      ?? '',
  is_active: props.position?.is_active ?? true,
  notes:     props.position?.notes     ?? '',
});

function submit() {
  if (props.position) {
    form.put(route('admin.positions.update', props.position.id));
  } else {
    form.post(route('admin.positions.store'));
  }
}
</script>
