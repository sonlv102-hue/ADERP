<template>
  <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-xl shadow-xl w-full flex flex-col"
      :class="phase === 'preview' ? 'max-w-4xl max-h-[90vh]' : 'max-w-lg'">

      <div class="flex items-center justify-between px-6 py-4 border-b shrink-0">
        <h3 class="text-lg font-bold text-gray-900">Upload danh sách nhân viên</h3>
        <button @click="$emit('close')" class="text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
      </div>

      <!-- Phase: upload -->
      <div v-if="phase === 'upload'" class="p-6">
        <input ref="fileInputRef" type="file" accept=".xlsx,.xls" class="erp-input" @change="onFilePick" />
        <p class="text-xs text-gray-400 mt-1">Dùng đúng mẫu tại nút "Tải mẫu Excel". Chỉ nhận .xlsx, .xls.</p>

        <label class="flex items-center gap-2 mt-4 text-sm text-gray-700">
          <input type="checkbox" v-model="updateExisting" class="rounded border-gray-300" />
          Cho phép cập nhật nhân viên đã tồn tại
        </label>

        <div v-if="uploadError" class="mt-3 text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ uploadError }}</div>

        <div class="flex gap-3 mt-5">
          <button @click="readFile" :disabled="!file || loading" class="erp-btn-primary flex-1 flex items-center justify-center gap-2">
            <span v-if="loading" class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
            {{ loading ? 'Đang đọc...' : 'Đọc dữ liệu' }}
          </button>
          <button @click="$emit('close')" class="erp-btn-secondary">Hủy</button>
        </div>
      </div>

      <!-- Phase: preview -->
      <div v-else-if="phase === 'preview'" class="flex flex-col overflow-hidden min-h-0">
        <div class="px-6 pt-4 pb-3 grid grid-cols-3 gap-3 border-b shrink-0">
          <div class="rounded-lg p-3 text-center bg-gray-50">
            <p class="text-xs text-gray-500">Tổng dòng</p>
            <p class="text-xl font-bold text-gray-800">{{ preview.summary.total_rows }}</p>
          </div>
          <div class="rounded-lg p-3 text-center bg-green-50">
            <p class="text-xs text-gray-500">Hợp lệ</p>
            <p class="text-xl font-bold text-green-700">{{ preview.summary.valid_rows }}</p>
          </div>
          <div class="rounded-lg p-3 text-center bg-red-50">
            <p class="text-xs text-gray-500">Lỗi</p>
            <p class="text-xl font-bold text-red-700">{{ preview.summary.error_rows }}</p>
          </div>
        </div>

        <div class="flex-1 overflow-auto px-6 py-3 min-h-0">
          <table class="w-full text-xs min-w-[600px]">
            <thead class="text-gray-400 uppercase sticky top-0 bg-white">
              <tr>
                <th class="text-left py-1 pr-2 w-12">Dòng</th>
                <th class="text-left py-1 pr-2">Mã NV</th>
                <th class="text-left py-1 pr-2">Họ tên</th>
                <th class="text-left py-1 pr-2">Phòng ban</th>
                <th class="text-left py-1">Lỗi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="r in preview.rows" :key="r.row" :class="{ 'bg-red-50': r.errors.length }">
                <td class="py-1 pr-2 text-gray-500">{{ r.row }}</td>
                <td class="py-1 pr-2 font-mono text-gray-700">{{ r.data.code }}</td>
                <td class="py-1 pr-2 text-gray-700 truncate max-w-[160px]">{{ r.data.name }}</td>
                <td class="py-1 pr-2 text-gray-600">{{ r.data.department }}</td>
                <td class="py-1 text-red-600">{{ r.errors.join(' | ') }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="confirmError" class="mx-6 mb-2 text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">
          {{ confirmError }}
          <a v-if="errorFileId" :href="route('admin.employees.import.errors', errorFileId)" class="underline font-medium ml-1">Tải file lỗi</a>
        </div>
        <div class="px-6 py-4 border-t flex gap-3 shrink-0">
          <button @click="confirmImport" :disabled="preview.summary.valid_rows === 0 || confirming"
            class="erp-btn-primary flex-1 flex items-center justify-center gap-2">
            <span v-if="confirming" class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
            {{ confirming ? 'Đang import...' : `Xác nhận import ${preview.summary.total_rows} dòng` }}
          </button>
          <button @click="phase = 'upload'" :disabled="confirming" class="erp-btn-secondary">Quay lại</button>
        </div>
      </div>

      <!-- Phase: done -->
      <div v-else-if="phase === 'done'" class="p-8 text-center">
        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
          <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
        </div>
        <p class="text-lg font-bold text-gray-900">Import thành công!</p>
        <p class="text-sm text-gray-500 mt-1">Tạo mới <span class="font-semibold text-gray-800">{{ result.created }}</span>, cập nhật <span class="font-semibold text-gray-800">{{ result.updated }}</span> nhân viên.</p>
        <button @click="done" class="erp-btn-primary mt-6 px-8">Xong</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const emit = defineEmits(['close', 'imported']);

const phase          = ref('upload');
const loading        = ref(false);
const confirming     = ref(false);
const file            = ref(null);
const fileInputRef    = ref(null);
const updateExisting  = ref(false);
const uploadError     = ref('');
const confirmError    = ref('');
const preview         = ref(null);
const previewId       = ref(null);
const errorFileId     = ref(null);
const result          = ref({ created: 0, updated: 0 });

function onFilePick(e) {
  file.value = e.target.files[0] ?? null;
}

function csrf() {
  return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function readFile() {
  if (!file.value) return;
  loading.value = true; uploadError.value = '';
  const fd = new FormData();
  fd.append('file', file.value);
  fd.append('update_existing', updateExisting.value ? '1' : '0');
  try {
    const res = await fetch(route('admin.employees.import.preview'), {
      method: 'POST', body: fd,
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
      credentials: 'same-origin',
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message ?? 'Đọc file thất bại.');
    previewId.value = data.preview_id;
    preview.value = data;
    phase.value = 'preview';
  } catch (e) {
    uploadError.value = e.message;
  } finally {
    loading.value = false;
  }
}

async function confirmImport() {
  confirming.value = true; confirmError.value = ''; errorFileId.value = null;
  const fd = new FormData();
  fd.append('preview_id', previewId.value);
  fd.append('update_existing', updateExisting.value ? '1' : '0');
  try {
    const res = await fetch(route('admin.employees.import.confirm'), {
      method: 'POST', body: fd,
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
      credentials: 'same-origin',
    });
    const data = await res.json();
    if (!data.success) {
      confirmError.value = (data.errors ?? [data.message]).slice(0, 5).join(' | ');
      errorFileId.value = data.error_file_id ?? null;
      return;
    }
    result.value = { created: data.created, updated: data.updated };
    phase.value = 'done';
  } catch (e) {
    confirmError.value = e.message;
  } finally {
    confirming.value = false;
  }
}

function done() {
  router.reload({ only: ['employees'] });
  emit('imported');
}
</script>
