<template>
  <div class="min-h-screen bg-gradient-to-br from-primary-700 to-primary-900 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-8 text-center">
      <div class="w-16 h-16 bg-primary-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
        <svg class="w-8 h-8 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
        </svg>
      </div>
      <h1 class="text-xl font-bold text-gray-900 mb-2">{{ title }}</h1>
      <p class="text-gray-500 text-sm mb-6">{{ message }}</p>
      <a href="/" class="inline-block bg-primary-600 hover:bg-primary-700 text-white font-semibold py-2.5 px-5 rounded-lg transition">
        Về trang chủ
      </a>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  status: { type: Number, required: true },
});

const texts = {
  403: {
    title: 'Không có quyền truy cập',
    message: 'Tài khoản của bạn chưa được cấp quyền cho chức năng này. Vui lòng liên hệ quản trị viên để được cấp quyền.',
  },
  404: {
    title: 'Không tìm thấy trang',
    message: 'Trang hoặc dữ liệu bạn tìm không tồn tại, hoặc đã bị xóa.',
  },
  419: {
    title: 'Phiên làm việc đã hết hạn',
    message: 'Vui lòng tải lại trang và thử lại.',
  },
  500: {
    title: 'Lỗi hệ thống',
    message: 'Đã có lỗi xảy ra phía máy chủ. Vui lòng thử lại sau hoặc liên hệ quản trị viên.',
  },
  503: {
    title: 'Hệ thống đang bảo trì',
    message: 'Vui lòng quay lại sau ít phút.',
  },
};

const title = computed(() => texts[props.status]?.title ?? 'Đã có lỗi xảy ra');
const message = computed(() => texts[props.status]?.message ?? 'Vui lòng thử lại hoặc liên hệ quản trị viên.');
</script>
