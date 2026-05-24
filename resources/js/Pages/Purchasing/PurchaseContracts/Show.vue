<template>
  <AppLayout>
    <div class="max-w-3xl space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <Link :href="route('purchasing.purchase-contracts.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <h1 class="text-2xl font-bold text-gray-900">{{ contract.code }}</h1>
          <StatusBadge :color="contract.status_color">{{ contract.status_label }}</StatusBadge>
        </div>
        <div class="flex items-center gap-2">
          <Link v-if="contract.status === 'draft'"
            :href="route('purchasing.purchase-contracts.edit', contract.id)"
            class="px-4 py-2 border border-gray-300 text-gray-600 rounded-lg text-sm font-medium hover:bg-gray-50">
            Sửa
          </Link>
          <button v-if="contract.status === 'draft'" @click="action('activate')"
            class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Kích hoạt
          </button>
          <button v-if="contract.status === 'active'" @click="action('complete')"
            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Hoàn thành
          </button>
          <button v-if="['draft','active'].includes(contract.status)" @click="action('terminate')"
            class="px-4 py-2 border border-red-300 text-red-600 rounded-lg text-sm font-medium hover:bg-red-50">
            Chấm dứt
          </button>
          <button v-if="contract.status === 'draft'" @click="deleteContract"
            class="px-4 py-2 border border-red-200 text-red-500 rounded-lg text-sm font-medium hover:bg-red-50">
            Xóa
          </button>
        </div>
      </div>

      <!-- Thông tin -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-base font-semibold text-gray-800 mb-4">Thông tin hợp đồng</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-y-4 gap-x-8 text-sm">
          <div>
            <span class="text-gray-500">Nhà cung cấp</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ contract.supplier.name }}</p>
          </div>
          <div>
            <span class="text-gray-500">Đơn mua liên kết</span>
            <p class="mt-0.5">
              <Link v-if="contract.order" :href="route('purchasing.purchase-orders.show', contract.order.id)"
                class="font-mono text-primary-600 hover:underline font-medium">{{ contract.order.code }}</Link>
              <span v-else class="text-gray-400">—</span>
            </p>
          </div>
          <div>
            <span class="text-gray-500">Giá trị hợp đồng</span>
            <p class="font-bold text-primary-700 mt-0.5">{{ formatVnd(contract.value) }}</p>
          </div>
          <div>
            <span class="text-gray-500">Ngày bắt đầu</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ contract.start_date ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Ngày kết thúc</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ contract.end_date ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Người tạo</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ contract.creator }} — {{ contract.created_at }}</p>
          </div>
          <div v-if="contract.notes" class="sm:col-span-2 lg:col-span-3">
            <span class="text-gray-500">Ghi chú</span>
            <p class="font-medium text-gray-900 mt-0.5 whitespace-pre-line">{{ contract.notes }}</p>
          </div>
        </div>
      </div>

      <!-- Tài liệu đính kèm -->
      <div class="bg-white rounded-xl border border-gray-200 p-5">
        <p class="text-sm font-semibold text-gray-700 mb-3">Tài liệu đính kèm (file hợp đồng)</p>
        <div v-if="contract.file_name" class="flex items-center gap-3 px-3 py-2 bg-gray-50 rounded-lg">
          <svg class="w-5 h-5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
          </svg>
          <span class="text-sm text-gray-800 flex-1 truncate">{{ contract.file_name }}</span>
          <a :href="contract.file_url" target="_blank" download
            class="text-primary-600 hover:text-primary-800 text-xs font-medium whitespace-nowrap">Tải xuống</a>
          <button @click="deleteFile"
            class="text-red-500 hover:text-red-700 text-xs font-medium whitespace-nowrap">Xóa</button>
        </div>
        <div v-else class="space-y-2">
          <label class="block cursor-pointer">
            <input type="file" class="hidden" ref="fileInput" @change="onFileSelected">
            <div class="px-3 py-2 text-sm text-gray-500 bg-gray-50 border border-dashed border-gray-300 rounded-lg hover:bg-gray-100 text-center">
              {{ selectedFile ? selectedFile.name : 'Nhấn để chọn file hợp đồng (PDF, Word, ảnh...)' }}
            </div>
          </label>
          <div v-if="selectedFile" class="flex justify-end">
            <button @click="uploadFile" :disabled="uploading"
              class="px-4 py-1.5 bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white text-sm rounded-lg">
              {{ uploading ? 'Đang tải...' : 'Đính kèm' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ contract: Object });

const { formatVnd } = useCurrency();

const action = (act) => {
  router.post(route(`purchasing.purchase-contracts.${act}`, props.contract.id));
};

const deleteContract = () => {
  if (confirm('Xóa hợp đồng này?')) {
    router.delete(route('purchasing.purchase-contracts.destroy', props.contract.id));
  }
};

// File attachment
const fileInput = ref(null);
const selectedFile = ref(null);
const uploading = ref(false);

const onFileSelected = (e) => {
  selectedFile.value = e.target.files[0] ?? null;
};

const uploadFile = () => {
  if (!selectedFile.value) return;
  const formData = new FormData();
  formData.append('file', selectedFile.value);
  uploading.value = true;
  router.post(route('purchasing.purchase-contracts.attachment.upload', props.contract.id), formData, {
    preserveScroll: true,
    onSuccess: () => {
      selectedFile.value = null;
      if (fileInput.value) fileInput.value.value = '';
    },
    onFinish: () => { uploading.value = false; },
  });
};

const deleteFile = () => {
  if (confirm('Xóa file đính kèm?')) {
    router.delete(route('purchasing.purchase-contracts.attachment.delete', props.contract.id), {
      preserveScroll: true,
    });
  }
};
</script>
