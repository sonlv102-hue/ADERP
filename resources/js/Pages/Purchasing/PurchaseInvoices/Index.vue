<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">Hóa đơn đầu vào</h1>
        <Link v-if="can('purchasing.create')" :href="route('purchasing.purchase-invoices.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          Thêm hóa đơn
        </Link>
      </div>

      <!-- Filters -->
      <div class="flex gap-3 flex-wrap">
        <input v-model="search" @keyup.enter="applyFilters" type="text" placeholder="Tìm mã, số HĐ, NCC..."
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-primary-500" />
        <select v-model="statusFilter" @change="applyFilters"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả trạng thái</option>
          <option value="pending">Chưa nhận HĐ</option>
          <option value="received">Đã nhận HĐ</option>
          <option value="reviewing">Đang kiểm tra</option>
          <option value="valid">Hợp lệ</option>
          <option value="need_supplement">Cần bổ sung</option>
          <option value="partial_paid">TT một phần</option>
          <option value="paid">Đã thanh toán</option>
          <option value="cancelled">Đã hủy</option>
        </select>
        <button v-if="search || statusFilter" @click="clearFilters"
          class="text-gray-500 hover:text-gray-700 px-3 py-2 text-sm">Xóa lọc</button>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Số HĐ NCC</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Nhà cung cấp</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Đơn mua</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày HĐ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Hạn TT</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng tiền</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Còn lại</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="inv in invoices.data" :key="inv.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono font-medium text-primary-700">{{ inv.code }}</td>
              <td class="px-5 py-3 text-gray-700">{{ inv.invoice_number ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-900">{{ inv.supplier }}</td>
              <td class="px-5 py-3 font-mono text-xs text-gray-600">{{ inv.purchase_order }}</td>
              <td class="px-5 py-3 text-gray-600">{{ inv.invoice_date ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ inv.due_date ?? '—' }}</td>
              <td class="px-5 py-3 text-right font-medium text-gray-900">{{ formatVnd(inv.total) }}</td>
              <td class="px-5 py-3 text-right" :class="inv.remaining > 0 ? 'text-red-600 font-medium' : 'text-gray-500'">
                {{ formatVnd(inv.remaining) }}
              </td>
              <td class="px-5 py-3">
                <StatusBadge :color="inv.status_color">{{ inv.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-right whitespace-nowrap">
                <Link :href="route('purchasing.purchase-invoices.show', inv.id)"
                  class="text-primary-600 hover:text-primary-800 text-xs font-medium mr-3">Xem</Link>
                <button v-if="canRecall(inv) && can('purchasing.approve')"
                  @click="openRecallModal(inv)"
                  class="text-orange-600 hover:text-orange-800 text-xs font-medium mr-3">Thu hồi TT</button>
                <Link v-if="canEdit(inv)" :href="route('purchasing.purchase-invoices.edit', inv.id)"
                  class="text-gray-600 hover:text-gray-800 text-xs font-medium mr-3">Sửa</Link>
                <button v-if="canDelete(inv) && can('purchasing.approve')"
                  @click="openDeleteConfirm(inv)"
                  class="text-red-600 hover:text-red-800 text-xs font-medium">Xóa</button>
              </td>
            </tr>
            <tr v-if="!invoices.data?.length">
              <td colspan="10" class="px-5 py-10 text-center text-gray-400">Chưa có hóa đơn đầu vào nào</td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pagination :links="invoices.links" :meta="invoices.meta" />
    </div>

    <!-- ── Modal: Thu hồi thanh toán ── -->
    <div v-if="recallTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-xl shadow-2xl w-full max-w-xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="font-bold text-gray-900 text-lg">Thu hồi thanh toán — {{ recallTarget.code }}</h3>
        </div>
        <div class="p-6 space-y-4">
          <!-- Thông tin hóa đơn -->
          <div class="grid grid-cols-2 gap-3 text-sm bg-gray-50 p-4 rounded-lg">
            <div>
              <span class="text-gray-500">Mã hóa đơn:</span>
              <span class="font-medium ml-1">{{ recallTarget.code }}</span>
            </div>
            <div>
              <span class="text-gray-500">Số HĐ NCC:</span>
              <span class="font-medium ml-1">{{ recallTarget.invoice_number ?? '—' }}</span>
            </div>
            <div>
              <span class="text-gray-500">Nhà cung cấp:</span>
              <span class="font-medium ml-1">{{ recallTarget.supplier }}</span>
            </div>
            <div>
              <span class="text-gray-500">Tổng tiền:</span>
              <span class="font-semibold ml-1">{{ formatVnd(recallTarget.total) }}</span>
            </div>
            <div>
              <span class="text-gray-500">Đã thanh toán:</span>
              <span class="font-semibold text-green-700 ml-1">{{ formatVnd(recallTarget.paid_amount) }}</span>
            </div>
            <div>
              <span class="text-gray-500">Còn lại:</span>
              <span class="font-semibold text-red-600 ml-1">{{ formatVnd(recallTarget.remaining) }}</span>
            </div>
          </div>

          <!-- Danh sách thanh toán -->
          <div>
            <p class="text-sm font-semibold text-gray-700 mb-2">Các khoản thanh toán sẽ bị thu hồi:</p>
            <div class="border border-gray-200 rounded-lg overflow-hidden text-sm">
              <table class="w-full">
                <thead class="bg-gray-50">
                  <tr>
                    <th class="text-left px-3 py-2 text-gray-600 font-medium">Ngày</th>
                    <th class="text-left px-3 py-2 text-gray-600 font-medium">Phương thức</th>
                    <th class="text-left px-3 py-2 text-gray-600 font-medium">Số tham chiếu</th>
                    <th class="text-right px-3 py-2 text-gray-600 font-medium">Số tiền</th>
                    <th class="text-left px-3 py-2 text-gray-600 font-medium">Người tạo</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <tr v-for="p in activePayments" :key="p.id">
                    <td class="px-3 py-2 text-gray-700">{{ p.payment_date }}</td>
                    <td class="px-3 py-2 text-gray-600">{{ p.method_label }}</td>
                    <td class="px-3 py-2 text-gray-500 font-mono text-xs">{{ p.reference ?? '—' }}</td>
                    <td class="px-3 py-2 text-right font-medium text-gray-900">{{ formatVnd(p.amount) }}</td>
                    <td class="px-3 py-2 text-gray-600 text-xs">{{ p.creator }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Cảnh báo -->
          <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-800">
            <p class="font-semibold mb-1">⚠ Cảnh báo</p>
            <p>Thao tác này sẽ thu hồi thanh toán của hóa đơn đầu vào, cập nhật lại công nợ nhà cung cấp, sổ cái và trạng thái hóa đơn. Vui lòng kiểm tra kỹ trước khi xác nhận.</p>
            <ul class="mt-2 list-disc list-inside space-y-1 text-amber-700">
              <li>Bút toán thanh toán gốc sẽ bị đảo (bút toán mới tại ngày hiện tại)</li>
              <li>Hóa đơn chuyển về trạng thái <strong>Hợp lệ</strong></li>
              <li>Sau thu hồi, kế toán có thể sửa hoặc xóa hóa đơn</li>
            </ul>
          </div>

          <!-- Lý do -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
              Lý do thu hồi <span class="text-red-500">*</span>
            </label>
            <textarea v-model="recallReason" rows="2" placeholder="Nhập lý do thu hồi thanh toán..."
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400"
              :class="{ 'border-red-500': recallReasonError }" />
            <p v-if="recallReasonError" class="mt-1 text-xs text-red-600">{{ recallReasonError }}</p>
          </div>

          <!-- Xác nhận -->
          <label class="flex items-start gap-2 cursor-pointer text-sm text-gray-700">
            <input type="checkbox" v-model="recallConfirmed" class="mt-0.5 shrink-0" />
            <span>Tôi đã kiểm tra và xác nhận thu hồi toàn bộ thanh toán của hóa đơn này</span>
          </label>
        </div>

        <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-3">
          <button @click="closeRecallModal" class="px-4 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
            Hủy
          </button>
          <button @click="submitRecall"
            :disabled="!recallConfirmed || recallProcessing"
            class="px-5 py-2 text-sm font-medium bg-orange-600 text-white rounded-lg hover:bg-orange-700 disabled:opacity-40">
            {{ recallProcessing ? 'Đang xử lý...' : 'Xác nhận thu hồi' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ── Modal: Xác nhận xóa ── -->
    <div v-if="deleteTarget" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-sm">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="font-semibold text-gray-900">Xác nhận xóa hóa đơn</h3>
        </div>
        <div class="p-6 space-y-3 text-sm text-gray-600">
          <p>Xóa hóa đơn <strong>{{ deleteTarget.code }}</strong>?</p>
          <p class="text-red-600 bg-red-50 px-3 py-2 rounded-lg">
            Thao tác không thể hoàn tác. Đảm bảo đã thu hồi thanh toán trước khi xóa.
          </p>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-3">
          <button @click="deleteTarget = null" class="px-4 py-2 text-sm border border-gray-300 rounded-lg text-gray-700">Hủy</button>
          <button @click="submitDelete" class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">Xóa</button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  invoices: Object,
});

const { hasPermission } = usePermission();
const can = hasPermission;
const { formatVnd } = useCurrency();

const search       = ref('');
const statusFilter = ref('');

function applyFilters() {
  router.get(route('purchasing.purchase-invoices.index'), {
    search: search.value || undefined,
    status: statusFilter.value || undefined,
  }, { preserveState: true, replace: true });
}

function clearFilters() {
  search.value = '';
  statusFilter.value = '';
  applyFilters();
}

// ── Row action rules ──────────────────────────────────────────────────────────
function canRecall(inv) {
  return inv.status === 'paid' || inv.status === 'partial_paid';
}

function canEdit(inv) {
  // Cho sửa khi chưa thanh toán (valid, pending, received, reviewing, need_supplement)
  const editableStatuses = ['valid', 'pending', 'received', 'reviewing', 'need_supplement'];
  return editableStatuses.includes(inv.status);
}

function canDelete(inv) {
  return inv.status === 'cancelled' || inv.status === 'valid';
}

// ── Recall modal ──────────────────────────────────────────────────────────────
const recallTarget    = ref(null);
const recallReason    = ref('');
const recallConfirmed = ref(false);
const recallReasonError = ref('');
const recallProcessing  = ref(false);

const activePayments = computed(() =>
  recallTarget.value?.payments?.filter(p => p.status === 'active') ?? []
);

function openRecallModal(inv) {
  recallTarget.value    = inv;
  recallReason.value    = '';
  recallConfirmed.value = false;
  recallReasonError.value = '';
}

function closeRecallModal() {
  recallTarget.value = null;
}

function submitRecall() {
  recallReasonError.value = '';
  if (!recallReason.value.trim() || recallReason.value.trim().length < 5) {
    recallReasonError.value = 'Lý do thu hồi phải ít nhất 5 ký tự.';
    return;
  }
  if (!recallConfirmed.value) return;

  recallProcessing.value = true;
  router.post(
    route('purchasing.purchase-invoices.recall-payments', recallTarget.value.id),
    { reason: recallReason.value.trim() },
    {
      onSuccess: () => closeRecallModal(),
      onError: ()  => { recallProcessing.value = false; },
      onFinish: ()  => { recallProcessing.value = false; },
    }
  );
}

// ── Delete modal ──────────────────────────────────────────────────────────────
const deleteTarget = ref(null);

function openDeleteConfirm(inv) {
  deleteTarget.value = inv;
}

function submitDelete() {
  router.delete(route('purchasing.purchase-invoices.destroy', deleteTarget.value.id), {
    onSuccess: () => { deleteTarget.value = null; },
    onError:   () => { deleteTarget.value = null; },
  });
}
</script>
