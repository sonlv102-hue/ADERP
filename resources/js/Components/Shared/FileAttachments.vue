<template>
  <div class="bg-white rounded-xl border border-gray-200 p-5">
    <div class="flex items-center justify-between mb-3">
      <p class="text-sm font-semibold text-gray-700">
        Tài liệu đính kèm
        <span v-if="attachments.length" class="ml-1 text-gray-400 font-normal">({{ attachments.length }})</span>
      </p>
    </div>

    <!-- Danh sách file -->
    <div v-if="attachments.length" class="space-y-2 mb-3">
      <div v-for="file in attachments" :key="file.id"
        class="flex items-center gap-3 px-3 py-2 bg-gray-50 rounded-lg group">
        <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
        </svg>
        <span class="text-sm text-gray-800 flex-1 truncate" :title="file.file_name">{{ file.file_name }}</span>
        <span v-if="file.file_size" class="text-xs text-gray-400 whitespace-nowrap">{{ formatSize(file.file_size) }}</span>
        <a :href="file.file_url" target="_blank" download
          class="text-primary-600 hover:text-primary-800 text-xs font-medium whitespace-nowrap">Tải xuống</a>
        <button v-if="canEdit" @click="deleteFile(file.id)"
          class="text-red-400 hover:text-red-600 text-xs font-medium whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity">
          Xóa
        </button>
      </div>
    </div>
    <p v-else class="text-sm text-gray-400 mb-3">Chưa có tài liệu đính kèm.</p>

    <!-- Upload nhiều file -->
    <div v-if="canEdit" class="space-y-2">
      <label
        class="flex items-center gap-2 w-full cursor-pointer border border-dashed border-gray-300 rounded-lg px-4 py-3 hover:border-primary-400 hover:bg-primary-50 transition-colors"
        @dragover.prevent @drop.prevent="onDrop">
        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
        </svg>
        <span class="text-sm text-gray-500">
          <span v-if="selectedFiles.length">{{ selectedFiles.length }} file đã chọn</span>
          <span v-else>Chọn hoặc kéo thả file vào đây</span>
        </span>
        <input ref="fileInput" type="file" multiple class="hidden" @change="onFileChange" />
      </label>

      <div v-if="selectedFiles.length" class="space-y-1">
        <div v-for="(f, i) in selectedFiles" :key="i"
          class="flex items-center gap-2 text-xs text-gray-600 px-2 py-1 bg-gray-50 rounded">
          <span class="truncate flex-1">{{ f.name }}</span>
          <span class="text-gray-400">{{ formatSize(f.size) }}</span>
          <button type="button" @click="removeSelected(i)" class="text-red-400 hover:text-red-600">✕</button>
        </div>
        <div class="flex justify-end pt-1">
          <button @click="upload" :disabled="uploading"
            class="px-4 py-1.5 bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white text-sm rounded-lg">
            {{ uploading ? 'Đang tải...' : `Đính kèm ${selectedFiles.length} file` }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
  attachments: { type: Array, default: () => [] },
  uploadUrl:   { type: String, required: true },
  canEdit:     { type: Boolean, default: true },
});

const fileInput   = ref(null);
const selectedFiles = ref([]);
const uploading   = ref(false);

const formatSize = (bytes) => {
  if (!bytes) return '';
  if (bytes < 1024)       return bytes + ' B';
  if (bytes < 1048576)    return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / 1048576).toFixed(1) + ' MB';
};

const onFileChange = (e) => {
  selectedFiles.value = [...selectedFiles.value, ...Array.from(e.target.files)];
  if (fileInput.value) fileInput.value.value = '';
};

const onDrop = (e) => {
  selectedFiles.value = [...selectedFiles.value, ...Array.from(e.dataTransfer.files)];
};

const removeSelected = (index) => {
  selectedFiles.value.splice(index, 1);
};

const upload = () => {
  if (!selectedFiles.value.length || uploading.value) return;
  const formData = new FormData();
  selectedFiles.value.forEach(f => formData.append('files[]', f));
  uploading.value = true;
  router.post(props.uploadUrl, formData, {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => { selectedFiles.value = []; },
    onFinish: () => { uploading.value = false; },
  });
};

const deleteFile = (id) => {
  if (!confirm('Xóa file đính kèm này?')) return;
  router.delete(route('attachments.destroy', id), { preserveScroll: true });
};
</script>
