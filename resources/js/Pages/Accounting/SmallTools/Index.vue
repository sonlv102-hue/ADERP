<template>
  <AppLayout>
    <div class="flex items-center justify-between mb-6 flex-wrap gap-y-3">
      <h1 class="text-2xl font-bold text-gray-900">Công cụ dụng cụ (CCDC)</h1>
      <div class="flex gap-2 flex-wrap">
        <ExportExcelButton :endpoint="route('accounting.small-tools.export-excel')" :filters="{ search, status: filterStatus, department: filterDept }" />
        <a :href="pdfExportUrl"
          class="erp-btn-secondary flex items-center gap-1.5" title="Xuất PDF" target="_blank" rel="noopener">
          <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
          </svg>
          Xuất PDF
        </a>
        <button v-if="can('ccdc.manage')" @click="openImport"
          class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
          </svg>
          Nhập từ Excel
        </button>
        <Link v-if="can('ccdc.manage')"
          :href="route('accounting.small-tools.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          + Tạo CCDC
        </Link>
        <Link v-if="can('ccdc.manage')"
          :href="route('accounting.small-tools.receipts.create')"
          class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium">
          Phiếu nhập kho
        </Link>
      </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-xl border border-gray-200 p-4 mb-5 flex flex-wrap gap-3">
      <input v-model="search" @keyup.enter="applyFilters" type="text"
        placeholder="Tìm mã, tên CCDC..."
        class="px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500 w-56" />

      <select v-model="filterStatus" @change="applyFilters"
        class="px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500">
        <option value="">Tất cả trạng thái</option>
        <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
      </select>

      <select v-model="filterCategory" @change="applyFilters"
        class="px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500">
        <option value="">Tất cả nhóm</option>
        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>

      <input v-model="filterDept" @keyup.enter="applyFilters" type="text"
        placeholder="Bộ phận..."
        class="px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500 w-36" />

      <button @click="applyFilters"
        class="bg-primary-600 text-white px-4 py-2 rounded-lg text-sm font-medium">Lọc</button>
      <button @click="clearFilters"
        class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm">Xóa lọc</button>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="px-4 py-3 text-left font-semibold text-gray-700">Mã CCDC</th>
            <th class="px-4 py-3 text-left font-semibold text-gray-700">Tên CCDC</th>
            <th class="px-4 py-3 text-left font-semibold text-gray-700">Nhóm</th>
            <th class="px-4 py-3 text-left font-semibold text-gray-700">Bộ phận</th>
            <th class="px-4 py-3 text-right font-semibold text-gray-700">Nguyên giá</th>
            <th class="px-4 py-3 text-right font-semibold text-gray-700">Đã phân bổ</th>
            <th class="px-4 py-3 text-right font-semibold text-gray-700">Còn lại</th>
            <th class="px-4 py-3 text-center font-semibold text-gray-700">Trạng thái</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="!tools.data.length">
            <td colspan="9" class="px-4 py-8 text-center text-gray-400">Chưa có CCDC nào.</td>
          </tr>
          <tr v-for="t in tools.data" :key="t.id" class="hover:bg-gray-50">
            <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ t.code }}</td>
            <td class="px-4 py-3 font-medium text-gray-900">{{ t.name }}</td>
            <td class="px-4 py-3 text-gray-600">{{ t.category_name || '—' }}</td>
            <td class="px-4 py-3 text-gray-600">{{ t.department || '—' }}</td>
            <td class="px-4 py-3 text-right font-mono text-gray-800">{{ formatVnd(t.original_cost) }}</td>
            <td class="px-4 py-3 text-right font-mono text-gray-600">{{ formatVnd(t.total_allocated) }}</td>
            <td class="px-4 py-3 text-right font-mono"
              :class="t.total_remaining > 0 ? 'text-orange-600 font-semibold' : 'text-gray-400'">
              {{ formatVnd(t.total_remaining) }}
            </td>
            <td class="px-4 py-3 text-center">
              <span class="px-2 py-0.5 rounded-full text-xs font-medium"
                :class="`bg-${t.status_color}-100 text-${t.status_color}-700`">
                {{ t.status_label }}
              </span>
            </td>
            <td class="px-4 py-3 text-right">
              <Link :href="route('accounting.small-tools.show', t.id)"
                class="text-primary-600 hover:text-primary-800 text-xs font-medium">Xem</Link>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="tools.last_page > 1" class="flex justify-end mt-4 gap-1">
      <Link v-for="link in tools.links" :key="link.label"
        :href="link.url || '#'"
        :class="['px-3 py-1 text-sm rounded border', link.active ? 'bg-primary-600 text-white border-primary-600' : 'border-gray-300 text-gray-600 hover:bg-gray-50']"
        v-html="link.label" />
    </div>

    <!-- ── Import modal ─────────────────────────────────────────────────── -->
    <Modal :show="showImport" maxWidth="2xl" @close="closeImport">
      <template #title>
        {{ importStep === 'upload' ? 'Nhập CCDC từ Excel' : 'Xem trước dữ liệu import' }}
      </template>

      <!-- Step 1: Upload -->
      <div v-if="importStep === 'upload'" class="space-y-4">
        <a :href="route('accounting.small-tools.import.template')"
          class="inline-flex items-center gap-2 text-sm text-primary-600 hover:text-primary-800">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
          Tải file mẫu Excel (template)
        </a>
        <div class="bg-blue-50 rounded-lg p-3 text-xs text-blue-700 space-y-1">
          <p><strong>Hướng dẫn:</strong> Bắt buộc <code>name</code>, <code>original_cost</code>. CCDC nhập vào luôn ở trạng thái nháp.</p>
          <p>Các mã <code>category_code</code>/<code>employee_code</code>/<code>project_code</code>/<code>supplier_code</code>/<code>warehouse</code> không tìm thấy sẽ bị bỏ trống (cảnh báo), không chặn import.</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Chọn file Excel (.xlsx, .xls, .csv — tối đa 10MB)</label>
          <input ref="importFileInput" type="file" accept=".xlsx,.xls,.csv"
            class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-green-50 file:text-green-700 hover:file:bg-green-100" />
        </div>
        <p v-if="importError" class="text-sm text-red-600">{{ importError }}</p>
      </div>

      <!-- Step 2: Preview -->
      <div v-else-if="importStep === 'preview'" class="space-y-4 max-h-[65vh] overflow-y-auto pr-1">
        <div class="grid grid-cols-4 gap-3">
          <div class="bg-gray-50 rounded-lg p-3 text-center">
            <div class="text-2xl font-bold text-gray-800">{{ preview.total_rows }}</div>
            <div class="text-xs text-gray-500 mt-1">Tổng dòng đọc</div>
          </div>
          <div class="bg-blue-50 rounded-lg p-3 text-center">
            <div class="text-2xl font-bold text-blue-700">{{ preview.valid_tools }}</div>
            <div class="text-xs text-blue-600 mt-1">CCDC hợp lệ</div>
          </div>
          <div class="bg-red-50 rounded-lg p-3 text-center">
            <div class="text-2xl font-bold text-red-600">{{ preview.error_count }}</div>
            <div class="text-xs text-red-500 mt-1">Dòng lỗi</div>
          </div>
          <div class="bg-yellow-50 rounded-lg p-3 text-center">
            <div class="text-2xl font-bold text-yellow-700">{{ preview.warning_count }}</div>
            <div class="text-xs text-yellow-600 mt-1">Cảnh báo</div>
          </div>
        </div>

        <!-- Valid tools preview -->
        <div v-if="preview.tools.length">
          <h4 class="text-sm font-semibold text-gray-700 mb-2">CCDC sẽ import ({{ preview.tools.length }})</h4>
          <div class="border border-gray-200 rounded-lg overflow-hidden">
            <table class="min-w-full text-xs">
              <thead class="bg-gray-50">
                <tr>
                  <th class="text-left px-3 py-2 font-semibold text-gray-600">Tên CCDC</th>
                  <th class="text-left px-3 py-2 font-semibold text-gray-600">Nhóm</th>
                  <th class="text-left px-3 py-2 font-semibold text-gray-600">Bộ phận</th>
                  <th class="text-right px-3 py-2 font-semibold text-gray-600">Nguyên giá</th>
                  <th class="text-left px-3 py-2 font-semibold text-gray-600">Luồng NV</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="(t, i) in preview.tools" :key="i" class="hover:bg-gray-50">
                  <td class="px-3 py-2 text-gray-800">{{ t.name }}</td>
                  <td class="px-3 py-2 text-gray-600">{{ t.category_name || '—' }}</td>
                  <td class="px-3 py-2 text-gray-600">{{ t.department || '—' }}</td>
                  <td class="px-3 py-2 text-right text-gray-800 font-medium">{{ formatVnd(t.original_cost) }}</td>
                  <td class="px-3 py-2 text-gray-600">{{ t.acquisition_type === 'direct' ? 'Dùng ngay' : 'Nhập kho' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Warnings -->
        <div v-if="preview.warnings.length">
          <h4 class="text-sm font-semibold text-yellow-700 mb-2">Cảnh báo ({{ preview.warnings.length }})</h4>
          <div class="border border-yellow-200 rounded-lg overflow-hidden">
            <table class="min-w-full text-xs">
              <thead class="bg-yellow-50">
                <tr>
                  <th class="text-left px-3 py-2">Dòng</th>
                  <th class="text-left px-3 py-2">Tên CCDC</th>
                  <th class="text-left px-3 py-2">Cột</th>
                  <th class="text-left px-3 py-2">Nội dung</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-yellow-100">
                <tr v-for="(w, i) in preview.warnings" :key="i" class="hover:bg-yellow-50">
                  <td class="px-3 py-2 text-gray-600">{{ w.row }}</td>
                  <td class="px-3 py-2">{{ w.name }}</td>
                  <td class="px-3 py-2 font-mono">{{ w.field }}</td>
                  <td class="px-3 py-2 text-yellow-700">{{ w.message }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Errors -->
        <div v-if="preview.errors.length">
          <h4 class="text-sm font-semibold text-red-600 mb-2">Dòng lỗi ({{ preview.errors.length }}) — sẽ không được import</h4>
          <div class="border border-red-200 rounded-lg overflow-hidden">
            <table class="min-w-full text-xs">
              <thead class="bg-red-50">
                <tr>
                  <th class="text-left px-3 py-2">Dòng Excel</th>
                  <th class="text-left px-3 py-2">Tên CCDC</th>
                  <th class="text-left px-3 py-2">Lỗi</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-red-100">
                <tr v-for="(e, i) in preview.errors" :key="i" class="hover:bg-red-50">
                  <td class="px-3 py-2 text-gray-600">{{ e.row || '—' }}</td>
                  <td class="px-3 py-2">{{ e.name || '—' }}</td>
                  <td class="px-3 py-2 text-red-700">{{ e.message }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="!preview.valid_tools && !preview.error_count" class="text-center py-4 text-gray-500 text-sm">
          Không có dữ liệu hợp lệ để import.
        </div>
      </div>

      <template #footer>
        <template v-if="importStep === 'upload'">
          <button @click="closeImport" class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Hủy</button>
          <button @click="doPreview" :disabled="importing"
            class="px-4 py-2 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 flex items-center gap-2">
            <svg v-if="importing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            {{ importing ? 'Đang phân tích...' : 'Xem trước' }}
          </button>
        </template>
        <template v-else-if="importStep === 'preview'">
          <button @click="backToUpload" class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Quay lại</button>
          <button @click="closeImport" class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Hủy</button>
          <button v-if="preview && preview.valid_tools > 0" @click="doConfirm" :disabled="importing"
            class="px-4 py-2 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 flex items-center gap-2">
            <svg v-if="importing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            {{ importing ? 'Đang lưu...' : `Xác nhận import ${preview?.valid_tools} CCDC` }}
          </button>
        </template>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, watch, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import Modal from '@/Components/Shared/Modal.vue';
import ExportExcelButton from '@/Components/Shared/ExportExcelButton.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const { hasPermission: can } = usePermission();
const { formatVnd } = useCurrency();

const props = defineProps({
  tools:      Object,
  categories: Array,
  statuses:   Array,
  warehouses: Array,
  filters:    Object,
  preview:    { type: Object, default: null },
});

const search         = ref(props.filters.search ?? '');
const filterStatus   = ref(props.filters.status ?? '');
const filterCategory = ref(props.filters.category_id ?? '');
const filterDept     = ref(props.filters.department ?? '');

function applyFilters() {
  router.get(route('accounting.small-tools.index'), {
    search:      search.value,
    status:      filterStatus.value,
    category_id: filterCategory.value,
    department:  filterDept.value,
  }, { preserveState: true });
}

function clearFilters() {
  search.value = filterStatus.value = filterCategory.value = filterDept.value = '';
  applyFilters();
}

const pdfExportUrl = computed(() => {
  const params = new URLSearchParams();
  if (search.value)      params.set('search', search.value);
  if (filterStatus.value) params.set('status', filterStatus.value);
  if (filterDept.value)  params.set('department', filterDept.value);
  const qs = params.toString();
  const base = route('accounting.small-tools.export-pdf');
  return qs ? `${base}?${qs}` : base;
});

// ── Import state ─────────────────────────────────────────────────────────
const showImport      = ref(false);
const importStep      = ref('upload'); // 'upload' | 'preview'
const importFileInput = ref(null);
const importing       = ref(false);
const importError     = ref('');

watch(() => props.preview, (val) => {
  if (val) {
    importStep.value = 'preview';
    showImport.value = true;
    importing.value  = false;
  }
}, { immediate: true });

function openImport() {
  importStep.value  = 'upload';
  importError.value = '';
  showImport.value  = true;
}

function backToUpload() {
  importStep.value  = 'upload';
  importError.value = '';
}

function closeImport() {
  showImport.value  = false;
  importing.value   = false;
  importError.value = '';
  if (importStep.value === 'preview') {
    router.get(route('accounting.small-tools.index'), {}, { preserveState: false });
  }
  importStep.value = 'upload';
}

function doPreview() {
  const file = importFileInput.value?.files?.[0];
  if (!file) { importError.value = 'Vui lòng chọn file Excel.'; return; }

  importing.value  = true;
  importError.value = '';

  const formData = new FormData();
  formData.append('file', file);

  router.post(route('accounting.small-tools.import.preview'), formData, {
    forceFormData: true,
    onError: (errors) => {
      importing.value   = false;
      importError.value = errors.file ?? 'Không thể đọc file. Vui lòng kiểm tra định dạng.';
    },
    onFinish: () => { importing.value = false; },
  });
}

function doConfirm() {
  importing.value = true;
  router.post(route('accounting.small-tools.import.confirm'), {}, {
    onError: () => { importing.value = false; },
    onFinish: () => { importing.value = false; },
  });
}
</script>
