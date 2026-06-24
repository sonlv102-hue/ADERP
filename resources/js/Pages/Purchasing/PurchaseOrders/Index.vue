<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <h1 class="text-2xl font-bold text-gray-900">Đơn mua hàng</h1>
        <div class="flex gap-2 flex-wrap">
          <ExportExcelButton :endpoint="route('purchasing.purchase-orders.export-excel')" :filters="{ q: search, status: statusFilter }" />
          <button v-if="can('purchasing.create')" @click="openImport"
            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
            </svg>
            Import Excel
          </button>
          <Link v-if="can('purchasing.create')" :href="route('purchasing.purchase-orders.create')"
            class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Tạo đơn mua
          </Link>
        </div>
      </div>

      <!-- Search -->
      <div class="flex gap-3 flex-wrap">
        <input v-model="search" @input="doSearch" type="text"
          placeholder="Tìm đơn mua, nhà cung cấp, mã chứng từ..."
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-full sm:w-72 focus:outline-none focus:ring-2 focus:ring-primary-500" />
        <select v-model="statusFilter" @change="doSearch"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả trạng thái</option>
          <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
        <button v-if="search || statusFilter" @click="clearSearch"
          class="text-gray-500 hover:text-gray-700 px-3 py-2 text-sm">Xóa lọc</button>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã đơn</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Nhà cung cấp</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày đặt</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Kho nhận</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng tiền</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Nhập kho</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Hóa đơn / TT</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="po in orders.data" :key="po.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ po.code }}</td>
              <td class="px-5 py-3 text-gray-800 font-medium">{{ po.supplier }}</td>
              <td class="px-5 py-3 text-gray-600 whitespace-nowrap">
                {{ po.order_date }}
                <span v-if="po.expected_date" class="block text-xs text-gray-400">Dự kiến: {{ po.expected_date }}</span>
              </td>
              <td class="px-5 py-3 text-gray-600">{{ po.warehouse }}</td>
              <td class="px-5 py-3 text-right text-gray-800 font-medium">{{ formatVnd(po.total) }}</td>

              <!-- Trạng thái đơn -->
              <td class="px-5 py-3">
                <StatusBadge :color="po.status_color">{{ po.status_label }}</StatusBadge>
              </td>

              <!-- Nhập kho -->
              <td class="px-5 py-3">
                <span v-if="po.receipt_status === 'done'"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                  <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                  </svg>
                  Đã nhận đủ
                </span>
                <span v-else-if="po.receipt_status === 'partial'"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                  </svg>
                  Nhận một phần
                </span>
                <span v-else-if="po.receipt_status === 'none'"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">
                  Chưa nhận
                </span>
                <span v-else class="text-gray-300 text-xs">—</span>
              </td>

              <!-- Hóa đơn / Thanh toán -->
              <td class="px-5 py-3">
                <span v-if="po.invoice_status === 'paid'"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                  <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                  </svg>
                  Đã thanh toán
                </span>
                <span v-else-if="po.invoice_status === 'partial_paid'"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  Đang thanh toán
                </span>
                <span v-else-if="po.invoice_status"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-50 text-yellow-700 border border-yellow-200">
                  Có HĐ / Chưa TT
                </span>
                <template v-else-if="po.status !== 'cancelled'">
                  <span v-if="po.invoice_type === 'retail'"
                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-700 border border-orange-200">
                    Cần bổ sung HĐ bán lẻ
                  </span>
                  <span v-else-if="po.invoice_type === 'no_invoice'"
                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">
                    Không cần HĐ
                  </span>
                  <span v-else
                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200">
                    Chưa lập HĐ
                  </span>
                </template>
                <span v-else class="text-gray-300 text-xs">—</span>
              </td>

              <td class="px-5 py-3 text-right">
                <Link :href="route('purchasing.purchase-orders.show', po.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium">Xem</Link>
              </td>
            </tr>
            <tr v-if="!orders.data?.length">
              <td colspan="9" class="px-5 py-10 text-center text-gray-400">Chưa có đơn mua hàng nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="orders.links" :meta="orders.meta" />
    </div>

    <!-- ── Import modal ─────────────────────────────────────────────────── -->
    <Modal :show="showImport" maxWidth="2xl" @close="closeImport">
      <template #title>
        {{ importStep === 'upload' ? 'Import đơn mua hàng từ Excel' : 'Xem trước dữ liệu import' }}
      </template>

      <!-- Step 1: Upload -->
      <div v-if="importStep === 'upload'" class="space-y-4">
        <a :href="route('purchasing.purchase-orders.import.template')"
          class="inline-flex items-center gap-2 text-sm text-primary-600 hover:text-primary-800">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
          Tải file mẫu Excel (template)
        </a>
        <div class="bg-blue-50 rounded-lg p-3 text-xs text-blue-700 space-y-1">
          <p><strong>Hướng dẫn:</strong> Điền đúng cột bắt buộc. Cùng <code>order_code</code> trên nhiều dòng = nhiều SP trong 1 đơn.</p>
          <p>Cột <code>supplier_code</code> = Mã nhà cung cấp. Cột <code>warehouse</code> = Tên kho (phải khớp chính xác).</p>
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
        <!-- Summary stats -->
        <div class="grid grid-cols-4 gap-3">
          <div class="bg-gray-50 rounded-lg p-3 text-center">
            <div class="text-2xl font-bold text-gray-800">{{ preview.total_rows }}</div>
            <div class="text-xs text-gray-500 mt-1">Tổng dòng đọc</div>
          </div>
          <div class="bg-blue-50 rounded-lg p-3 text-center">
            <div class="text-2xl font-bold text-blue-700">{{ preview.valid_orders }}</div>
            <div class="text-xs text-blue-600 mt-1">Đơn hợp lệ</div>
          </div>
          <div class="bg-red-50 rounded-lg p-3 text-center">
            <div class="text-2xl font-bold text-red-600">{{ preview.error_count }}</div>
            <div class="text-xs text-red-500 mt-1">Dòng lỗi</div>
          </div>
          <div class="bg-yellow-50 rounded-lg p-3 text-center">
            <div class="text-2xl font-bold text-yellow-700">{{ preview.warning_count }}</div>
            <div class="text-xs text-yellow-600 mt-1">Cảnh báo số tiền</div>
          </div>
        </div>

        <!-- Duplicate action -->
        <div v-if="preview.has_duplicates" class="bg-orange-50 border border-orange-200 rounded-lg p-3">
          <p class="text-sm font-medium text-orange-800 mb-2">Có đơn mua trùng mã với dữ liệu đã có. Chọn cách xử lý:</p>
          <select v-model="duplicateAction"
            class="border border-orange-300 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-orange-400 w-full">
            <option value="skip">Bỏ qua đơn trùng — chỉ tạo đơn mới</option>
            <option value="update">Cập nhật đơn trùng (chỉ nếu đang ở trạng thái Nháp)</option>
            <option value="abort">Hủy toàn bộ import nếu có bất kỳ trùng nào</option>
          </select>
        </div>

        <!-- Valid orders preview -->
        <div v-if="preview.orders.length">
          <h4 class="text-sm font-semibold text-gray-700 mb-2">Đơn hàng sẽ import ({{ preview.orders.length }})</h4>
          <div class="border border-gray-200 rounded-lg overflow-hidden">
            <table class="min-w-full text-xs">
              <thead class="bg-gray-50">
                <tr>
                  <th class="text-left px-3 py-2 font-semibold text-gray-600">Mã đơn</th>
                  <th class="text-left px-3 py-2 font-semibold text-gray-600">Nhà cung cấp</th>
                  <th class="text-left px-3 py-2 font-semibold text-gray-600">Kho</th>
                  <th class="text-left px-3 py-2 font-semibold text-gray-600">Ngày đặt</th>
                  <th class="text-right px-3 py-2 font-semibold text-gray-600">Số dòng SP</th>
                  <th class="text-right px-3 py-2 font-semibold text-gray-600">Tổng sau thuế</th>
                  <th class="text-left px-3 py-2 font-semibold text-gray-600">Trạng thái</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="o in preview.orders" :key="o.code" class="hover:bg-gray-50">
                  <td class="px-3 py-2 font-mono text-gray-700">{{ o.code }}</td>
                  <td class="px-3 py-2 text-gray-700">{{ o.supplier_name }}</td>
                  <td class="px-3 py-2 text-gray-700">{{ o.warehouse_name }}</td>
                  <td class="px-3 py-2 text-gray-600">{{ o.order_date }}</td>
                  <td class="px-3 py-2 text-right text-gray-700">{{ o.items.length }}</td>
                  <td class="px-3 py-2 text-right text-gray-800 font-medium">{{ formatVnd(orderTotal(o)) }}</td>
                  <td class="px-3 py-2">
                    <span v-if="o.exists_in_db"
                      class="inline-block px-1.5 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700">Trùng</span>
                    <span v-else
                      class="inline-block px-1.5 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">Mới</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Warnings -->
        <div v-if="preview.warnings.length">
          <h4 class="text-sm font-semibold text-yellow-700 mb-2">Cảnh báo chênh lệch số tiền ({{ preview.warnings.length }})</h4>
          <div class="border border-yellow-200 rounded-lg overflow-hidden">
            <table class="min-w-full text-xs">
              <thead class="bg-yellow-50">
                <tr>
                  <th class="text-left px-3 py-2">Dòng</th>
                  <th class="text-left px-3 py-2">Mã đơn</th>
                  <th class="text-left px-3 py-2">Mã hàng</th>
                  <th class="text-left px-3 py-2">Cột</th>
                  <th class="text-right px-3 py-2">Trong Excel</th>
                  <th class="text-right px-3 py-2">Hệ thống tính</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-yellow-100">
                <tr v-for="(w, i) in preview.warnings" :key="i" class="hover:bg-yellow-50">
                  <td class="px-3 py-2 text-gray-600">{{ w.row }}</td>
                  <td class="px-3 py-2 font-mono">{{ w.order_code }}</td>
                  <td class="px-3 py-2 font-mono">{{ w.product_code }}</td>
                  <td class="px-3 py-2">{{ w.field }}</td>
                  <td class="px-3 py-2 text-right text-red-600">{{ formatVnd(w.excel) }}</td>
                  <td class="px-3 py-2 text-right text-green-700">{{ formatVnd(w.computed) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="text-xs text-yellow-600 mt-1">Hệ thống sẽ dùng số tiền tự tính, bỏ qua số trong Excel.</p>
        </div>

        <!-- Errors -->
        <div v-if="preview.errors.length">
          <h4 class="text-sm font-semibold text-red-600 mb-2">Dòng lỗi ({{ preview.errors.length }}) — sẽ không được import</h4>
          <div class="border border-red-200 rounded-lg overflow-hidden">
            <table class="min-w-full text-xs">
              <thead class="bg-red-50">
                <tr>
                  <th class="text-left px-3 py-2">Dòng Excel</th>
                  <th class="text-left px-3 py-2">Mã đơn</th>
                  <th class="text-left px-3 py-2">Mã hàng</th>
                  <th class="text-left px-3 py-2">Lỗi</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-red-100">
                <tr v-for="(e, i) in preview.errors" :key="i" class="hover:bg-red-50">
                  <td class="px-3 py-2 text-gray-600">{{ e.row || '—' }}</td>
                  <td class="px-3 py-2 font-mono">{{ e.order_code || '—' }}</td>
                  <td class="px-3 py-2 font-mono">{{ e.product_code || '—' }}</td>
                  <td class="px-3 py-2 text-red-700">{{ e.message }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="preview && !preview.valid_orders && !preview.error_count" class="text-center py-4 text-gray-500 text-sm">
          Không có dữ liệu hợp lệ để import.
        </div>
      </div>

      <template #footer>
        <!-- Upload step footer -->
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
        <!-- Preview step footer -->
        <template v-else-if="importStep === 'preview'">
          <button @click="backToUpload" class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Quay lại</button>
          <button @click="closeImport" class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Hủy</button>
          <button v-if="preview && preview.valid_orders > 0" @click="doConfirm" :disabled="importing"
            class="px-4 py-2 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 flex items-center gap-2">
            <svg v-if="importing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            {{ importing ? 'Đang lưu...' : `Xác nhận import ${preview?.valid_orders} đơn` }}
          </button>
        </template>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import Modal from '@/Components/Shared/Modal.vue';
import ExportExcelButton from '@/Components/Shared/ExportExcelButton.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ orders: Object, preview: Object, filters: Object, statuses: Array });

const { hasPermission } = usePermission();
const can = hasPermission;
const { formatVnd } = useCurrency();

const search       = ref(props.filters?.q ?? '');
const statusFilter = ref(props.filters?.status ?? '');
let searchTimer    = null;

function doSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    router.get(route('purchasing.purchase-orders.index'), {
      q:      search.value || undefined,
      status: statusFilter.value || undefined,
    }, { preserveState: true, replace: true });
  }, 300);
}
function clearSearch() {
  search.value       = '';
  statusFilter.value = '';
  doSearch();
}

// ── Import state ────────────────────────────────────────────────────────────
const showImport      = ref(false);
const importStep      = ref('upload');  // 'upload' | 'preview'
const importFileInput = ref(null);
const importing       = ref(false);
const importError     = ref('');
const duplicateAction = ref('skip');

// When preview prop arrives from backend, switch modal to preview step
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
  showImport.value = false;
  importing.value  = false;
  importError.value = '';
  // If we were in preview, navigate back to index to clear the preview prop
  if (importStep.value === 'preview') {
    router.get(route('purchasing.purchase-orders.index'), {}, { preserveState: false });
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

  router.post(route('purchasing.purchase-orders.import.preview'), formData, {
    forceFormData: true,
    onError: (errors) => {
      importing.value  = false;
      importError.value = errors.file ?? 'Không thể đọc file. Vui lòng kiểm tra định dạng.';
    },
    onFinish: () => { importing.value = false; },
  });
}

function doConfirm() {
  importing.value = true;
  router.post(route('purchasing.purchase-orders.import.confirm'), {
    duplicate_action: duplicateAction.value,
  }, {
    onError: () => { importing.value = false; },
    onFinish: () => { importing.value = false; },
  });
}

function orderTotal(order) {
  return order.items.reduce((sum, item) => sum + (item.total ?? 0), 0);
}
</script>
