<template>
  <AppLayout>
    <div class="max-w-xl space-y-5">
      <div class="flex items-center space-x-2">
        <Link :href="route('accounting.payrolls.index')" class="text-gray-400 hover:text-gray-600">
          &larr; Quay lại
        </Link>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden shadow-sm">
        <div class="px-5 py-4 border-b border-gray-200 bg-gray-50">
          <h1 class="text-lg font-bold text-gray-900">Lập bảng lương tháng mới</h1>
          <p class="text-xs text-gray-500">Hệ thống sẽ tự động quét tất cả nhân viên đang hoạt động và khởi tạo dòng lương mặc định.</p>
        </div>

        <form @submit.prevent="submit" class="p-5 space-y-4">
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">Chọn tháng tính lương *</label>
            <input type="month" v-model="form.period" required
              class="w-full border-gray-300 rounded-lg shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm" />
            <p v-if="form.errors.period" class="text-red-500 text-xs mt-1">{{ form.errors.period }}</p>
          </div>

          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">Ghi chú (nếu có)</label>
            <textarea v-model="form.notes" rows="3" placeholder="Ghi chú thêm về bảng lương này..."
              class="w-full border-gray-300 rounded-lg shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm"></textarea>
            <p v-if="form.errors.notes" class="text-red-500 text-xs mt-1">{{ form.errors.notes }}</p>
          </div>

          <div class="flex items-center justify-end space-x-3 pt-3 border-t border-gray-100">
            <Link :href="route('accounting.payrolls.index')"
              class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 font-medium">
              Hủy
            </Link>
            <button type="submit" :disabled="form.processing"
              class="px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg text-sm font-medium disabled:opacity-50">
              {{ form.processing ? 'Đang khởi tạo...' : 'Khởi tạo bảng lương' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

// Initialize period to current month in YYYY-MM format
const today = new Date();
const currentMonth = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0');

const form = useForm({
  period: currentMonth,
  notes: '',
});

const submit = () => {
  form.post(route('accounting.payrolls.store'));
};
</script>
