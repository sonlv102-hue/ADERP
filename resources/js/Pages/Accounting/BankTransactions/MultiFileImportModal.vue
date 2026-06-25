<template>
  <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-xl shadow-xl w-full flex flex-col"
      :class="phase === 'preview' ? 'max-w-5xl max-h-[90vh]' : 'max-w-lg'">

      <!-- Header -->
      <div class="flex items-center justify-between px-6 py-4 border-b shrink-0">
        <h3 class="text-lg font-bold text-gray-900">Import sao kê ngân hàng</h3>
        <button @click="handleClose" class="text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
      </div>

      <!-- Phase: upload -->
      <div v-if="phase === 'upload'" class="p-6">
        <div
          class="border-2 border-dashed rounded-xl p-8 text-center cursor-pointer transition-colors"
          :class="dragging ? 'border-primary-400 bg-primary-50' : 'border-gray-300 hover:border-primary-400'"
          @dragover.prevent="dragging = true"
          @dragleave.prevent="dragging = false"
          @drop.prevent="onDrop"
          @click="fileInputRef.click()">
          <svg class="w-10 h-10 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
          </svg>
          <p class="text-sm text-gray-500">Kéo thả file hoặc <span class="text-primary-600 font-medium">bấm chọn</span></p>
          <p class="text-xs text-gray-400 mt-1">Chỉ nhận .xlsx, .xls · Tối đa 20 file · Mỗi file ≤ 10 MB</p>
        </div>
        <input ref="fileInputRef" type="file" accept=".xlsx,.xls" multiple class="hidden" @change="onFilePick" />

        <div v-if="files.length" class="mt-4 space-y-2 max-h-48 overflow-y-auto">
          <div v-for="(f, i) in files" :key="i"
            class="flex items-center gap-2 bg-gray-50 rounded-lg px-3 py-2 text-sm">
            <svg class="w-4 h-4 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <span class="flex-1 truncate text-gray-700 text-xs">{{ f.name }}</span>
            <span class="text-gray-400 text-xs shrink-0">{{ (f.size / 1024).toFixed(0) }} KB</span>
            <button @click.stop="removeFile(i)" class="text-red-400 hover:text-red-600 shrink-0 ml-1 text-xs">✕</button>
          </div>
        </div>

        <div v-if="uploadError" class="mt-3 text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ uploadError }}</div>

        <div class="flex gap-3 mt-5">
          <button @click="readFiles" :disabled="!files.length || loading"
            class="erp-btn-primary flex-1 flex items-center justify-center gap-2">
            <span v-if="loading" class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
            {{ loading ? 'Đang đọc...' : `Đọc dữ liệu (${files.length} file)` }}
          </button>
          <button @click="handleClose" class="erp-btn-secondary">Hủy</button>
        </div>
      </div>

      <!-- Phase: preview -->
      <div v-else-if="phase === 'preview'" class="flex flex-col overflow-hidden min-h-0">

        <!-- Summary cards -->
        <div class="px-6 pt-4 pb-3 grid grid-cols-2 sm:grid-cols-4 gap-3 border-b shrink-0">
          <div v-for="card in summaryCards" :key="card.label"
            class="rounded-lg p-3 text-center" :class="card.bg">
            <p class="text-xs text-gray-500">{{ card.label }}</p>
            <p class="text-xl font-bold" :class="card.color">{{ card.value }}</p>
            <p v-if="card.sub" class="text-xs text-gray-400 mt-0.5 truncate">{{ card.sub }}</p>
          </div>
        </div>

        <!-- Files table -->
        <div class="px-6 py-3 border-b shrink-0">
          <table class="w-full text-xs">
            <thead><tr class="text-gray-400 uppercase text-left">
              <th class="pb-1 pr-2">File</th><th class="pb-1 text-center">Trạng thái</th>
              <th class="pb-1 text-right">Đọc</th><th class="pb-1 text-right text-green-600">Hợp lệ</th>
              <th class="pb-1 text-right text-amber-600">Trùng</th><th class="pb-1 text-right text-red-600">Lỗi</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="imp in preview.imports" :key="imp.id">
                <td class="py-1.5 pr-2 text-gray-700 max-w-[180px] truncate" :title="imp.filename">{{ imp.filename }}</td>
                <td class="py-1.5 text-center">
                  <span class="px-2 py-0.5 rounded text-xs font-medium" :class="fileStatusClass(imp.status)">
                    {{ fileStatusLabel(imp.status) }}
                  </span>
                </td>
                <td class="py-1.5 text-right text-gray-600">{{ imp.total_rows_detected }}</td>
                <td class="py-1.5 text-right text-green-700 font-medium">{{ imp.total_rows_valid }}</td>
                <td class="py-1.5 text-right text-amber-600">{{ imp.total_rows_duplicate }}</td>
                <td class="py-1.5 text-right text-red-600">{{ imp.total_rows_error }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Row preview table -->
        <div class="flex-1 overflow-auto px-6 py-3 min-h-0">
          <p class="text-xs text-gray-400 mb-2">Xem trước {{ Math.min(previewRows.length, 200) }} / {{ preview.rows.length }} dòng</p>
          <table class="w-full text-xs min-w-[700px]">
            <thead class="text-gray-400 uppercase sticky top-0 bg-white">
              <tr>
                <th class="text-left py-1 pr-2 w-20">Ngày</th>
                <th class="text-left py-1 pr-2 w-32 font-mono">Số BT</th>
                <th class="text-left py-1 pr-2">Diễn giải</th>
                <th class="text-right py-1 pr-2 w-24">Tiền vào</th>
                <th class="text-right py-1 pr-2 w-24">Tiền ra</th>
                <th class="text-center py-1 w-20">TT</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="row in previewRows" :key="row.id"
                :class="{ 'opacity-40': row.parse_status === 'duplicate', 'bg-red-50': row.parse_status === 'error' }">
                <td class="py-1 pr-2 text-gray-500 whitespace-nowrap">{{ row.transaction_date }}</td>
                <td class="py-1 pr-2 font-mono text-gray-400 truncate">{{ row.transaction_no ?? '—' }}</td>
                <td class="py-1 pr-2 text-gray-700 max-w-[240px] truncate">{{ row.description }}</td>
                <td class="py-1 pr-2 text-right text-green-600">{{ row.credit_amount > 0 ? fmtNum(row.credit_amount) : '' }}</td>
                <td class="py-1 pr-2 text-right text-red-600">{{ row.debit_amount > 0 ? fmtNum(row.debit_amount) : '' }}</td>
                <td class="py-1 text-center"><span :class="rowStatusClass(row.parse_status)">{{ rowStatusLabel(row.parse_status) }}</span></td>
              </tr>
            </tbody>
          </table>
          <p v-if="preview.rows.length > 200" class="text-xs text-gray-400 mt-2 text-center">
            ... và {{ preview.rows.length - 200 }} dòng nữa không hiển thị
          </p>
        </div>

        <div v-if="confirmError" class="mx-6 mb-2 text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ confirmError }}</div>
        <div class="px-6 py-4 border-t flex gap-3 shrink-0">
          <button @click="confirmImport" :disabled="preview.batch.total_rows_valid === 0 || confirming"
            class="erp-btn-primary flex-1 flex items-center justify-center gap-2">
            <span v-if="confirming" class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
            {{ confirming ? 'Đang import...' : `Xác nhận import ${preview.batch.total_rows_valid} dòng hợp lệ` }}
          </button>
          <button @click="handleClose" :disabled="confirming" class="erp-btn-secondary">Hủy</button>
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
        <p class="text-sm text-gray-500 mt-1">Đã tạo <span class="font-semibold text-gray-800">{{ importedCount }}</span> giao dịch ngân hàng.</p>
        <p class="text-xs text-gray-400 mt-1">Các giao dịch ở trạng thái chưa hạch toán, chưa đối soát.</p>
        <button @click="done" class="erp-btn-primary mt-6 px-8">Xong</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
  uploadUrl: String,
});
const emit = defineEmits(['close', 'imported']);

const phase        = ref('upload');
const dragging     = ref(false);
const loading      = ref(false);
const confirming   = ref(false);
const files        = ref([]);
const fileInputRef = ref(null);
const uploadError  = ref('');
const confirmError = ref('');
const preview      = ref(null);
const batchId      = ref(null);
const importedCount = ref(0);

const previewRows = computed(() => (preview.value?.rows ?? []).slice(0, 200));

const summaryCards = computed(() => {
  if (!preview.value) return [];
  const b = preview.value.batch;
  return [
    { label: 'Tổng dòng', value: b.total_rows_detected, bg: 'bg-gray-50', color: 'text-gray-800', sub: `${b.total_files} file` },
    { label: 'Hợp lệ',    value: b.total_rows_valid,    bg: 'bg-green-50', color: 'text-green-700', sub: fmtNum(b.total_credit) + ' ₫ vào' },
    { label: 'Trùng',     value: b.total_rows_duplicate, bg: 'bg-amber-50', color: 'text-amber-700', sub: 'bỏ qua' },
    { label: 'Lỗi',       value: b.total_rows_error,    bg: 'bg-red-50',   color: 'text-red-700',   sub: 'bỏ qua' },
  ];
});

function onDrop(e) {
  dragging.value = false;
  addFiles(Array.from(e.dataTransfer.files));
}
function onFilePick(e) {
  addFiles(Array.from(e.target.files));
  e.target.value = '';
}
function addFiles(newFiles) {
  uploadError.value = '';
  const valid   = newFiles.filter(f => /\.(xlsx|xls)$/i.test(f.name) && f.size <= 10 * 1024 * 1024);
  const invalid = newFiles.length - valid.length;
  if (files.value.length + valid.length > 20) {
    uploadError.value = 'Tối đa 20 file mỗi lần import.'; return;
  }
  if (invalid > 0) uploadError.value = `${invalid} file không hợp lệ (chỉ nhận .xlsx/.xls, tối đa 10 MB).`;
  files.value.push(...valid);
}
function removeFile(i) { files.value.splice(i, 1); }

async function readFiles() {
  if (!files.value.length) return;
  loading.value = true; uploadError.value = '';
  const fd = new FormData();
  files.value.forEach(f => fd.append('files[]', f));
  fd.append('_token', document.querySelector('meta[name="csrf-token"]')?.content ?? '');
  try {
    const res  = await fetch(props.uploadUrl, { method: 'POST', body: fd });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message ?? 'Upload thất bại.');
    batchId.value = data.batch_id;
    preview.value = data.preview;
    phase.value   = 'preview';
  } catch (e) {
    uploadError.value = e.message;
  } finally {
    loading.value = false;
  }
}

async function confirmImport() {
  confirming.value = true; confirmError.value = '';
  const fd = new FormData();
  fd.append('_token', document.querySelector('meta[name="csrf-token"]')?.content ?? '');
  try {
    const res  = await fetch(route('accounting.bank-statement-import-batches.confirm', batchId.value), { method: 'POST', body: fd });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message ?? 'Import thất bại.');
    importedCount.value = data.imported;
    phase.value = 'done';
  } catch (e) {
    confirmError.value = e.message;
  } finally {
    confirming.value = false;
  }
}

async function handleClose() {
  if (batchId.value && phase.value === 'preview') {
    const fd = new FormData();
    fd.append('_token', document.querySelector('meta[name="csrf-token"]')?.content ?? '');
    fetch(route('accounting.bank-statement-import-batches.cancel', batchId.value), { method: 'POST', body: fd });
  }
  emit('close');
}

function done() { emit('imported'); router.reload(); }

// ─── Helpers ────────────────────────────────────────────────────────────────

function fileStatusLabel(s) {
  return { parsed: 'Đọc xong', error: 'Lỗi', account_mismatch: 'Sai TK', uploaded: 'Chưa xử lý' }[s] ?? s;
}
function fileStatusClass(s) {
  if (s === 'parsed')           return 'bg-green-100 text-green-700';
  if (s === 'account_mismatch') return 'bg-orange-100 text-orange-700';
  if (s === 'error')            return 'bg-red-100 text-red-700';
  return 'bg-gray-100 text-gray-600';
}
function rowStatusLabel(s) {
  return { valid: 'OK', duplicate: 'Trùng', error: 'Lỗi', warning: '!' }[s] ?? s;
}
function rowStatusClass(s) {
  if (s === 'valid')     return 'text-green-700 font-medium text-xs';
  if (s === 'duplicate') return 'text-amber-600 text-xs';
  if (s === 'error')     return 'text-red-600 font-medium text-xs';
  return 'text-gray-500 text-xs';
}
function fmtNum(v) { return new Intl.NumberFormat('vi-VN').format(v || 0); }
</script>
