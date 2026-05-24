<template>
  <AppLayout>
    <div class="max-w-3xl space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <Link :href="route('sales.commissions.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <h1 class="text-2xl font-bold text-gray-900">{{ commission.code }}</h1>
          <StatusBadge :color="commission.status_color">{{ commission.status_label }}</StatusBadge>
        </div>
        <div class="flex gap-2">
          <!-- Sửa (chỉ khi draft) -->
          <Link v-if="commission.status === 'draft'"
            :href="route('sales.commissions.edit', commission.id)"
            class="border border-gray-300 text-gray-600 hover:bg-gray-50 px-3 py-2 rounded-lg text-sm font-medium">
            Sửa
          </Link>
          <!-- Trình duyệt (creator + draft) -->
          <button v-if="commission.status === 'draft'" @click="doAction('submit')" :disabled="busy"
            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60">
            Trình duyệt
          </button>
          <!-- Duyệt L1 (trưởng phòng) -->
          <button v-if="commission.status === 'pending_l1' && can('commissions.approve_l1')"
            @click="doAction('approve-l1')" :disabled="busy"
            class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60">
            Duyệt (TP)
          </button>
          <!-- Duyệt L2 (giám đốc) -->
          <button v-if="commission.status === 'pending_l2' && can('commissions.approve')"
            @click="doAction('approve-l2')" :disabled="busy"
            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60">
            Duyệt (GĐ)
          </button>
          <!-- Từ chối -->
          <button v-if="['pending_l1','pending_l2'].includes(commission.status) && (can('commissions.approve_l1') || can('commissions.approve'))"
            @click="showRejectModal = true" :disabled="busy"
            class="border border-red-300 text-red-600 hover:bg-red-50 px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60">
            Từ chối
          </button>
          <!-- Thanh toán -->
          <button v-if="commission.status === 'pending_payment' && can('commissions.pay')"
            @click="showPayForm = true" :disabled="busy"
            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60">
            Ghi nhận thanh toán
          </button>
          <!-- Hủy -->
          <button v-if="['draft','pending_l1','pending_l2'].includes(commission.status)"
            @click="doAction('cancel')" :disabled="busy"
            class="border border-red-300 text-red-600 hover:bg-red-50 px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60">
            Hủy
          </button>
          <!-- Xóa -->
          <button v-if="['draft','cancelled'].includes(commission.status)"
            @click="showDeleteModal = true" :disabled="busy"
            class="border border-red-400 text-red-600 hover:bg-red-50 px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60">
            Xóa
          </button>
        </div>
      </div>

      <!-- Reject reason banner -->
      <div v-if="commission.reject_reason" class="bg-red-50 border border-red-200 rounded-lg p-4">
        <p class="text-sm font-medium text-red-700 mb-1">Lý do từ chối:</p>
        <p class="text-sm text-red-600">{{ commission.reject_reason }}</p>
      </div>

      <!-- Thông tin chính -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-4">Thông tin khoản hoa hồng</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-5 text-sm">
          <div>
            <p class="text-gray-500 mb-1">Loại chi phí</p>
            <p class="font-medium text-gray-900">{{ commission.type_label }}</p>
          </div>
          <div>
            <p class="text-gray-500 mb-1">Người nhận</p>
            <p class="font-medium text-gray-900">{{ commission.recipient_name }}</p>
          </div>
          <div v-if="commission.recipient_info">
            <p class="text-gray-500 mb-1">Thông tin người nhận</p>
            <p class="font-medium text-gray-900">{{ commission.recipient_info }}</p>
          </div>
          <div>
            <p class="text-gray-500 mb-1">Số tiền</p>
            <p class="font-bold text-xl text-gray-900">{{ fmt(commission.amount) }}</p>
          </div>
          <div v-if="commission.rate">
            <p class="text-gray-500 mb-1">Tỷ lệ</p>
            <p class="font-medium text-gray-900">{{ commission.rate }}%</p>
          </div>
          <div>
            <p class="text-gray-500 mb-1">Hình thức TT</p>
            <p class="font-medium text-gray-900">{{ commission.payment_method_label }}</p>
          </div>
          <div v-if="commission.planned_date">
            <p class="text-gray-500 mb-1">Ngày dự kiến chi</p>
            <p class="font-medium text-gray-900">{{ commission.planned_date }}</p>
          </div>
          <div v-if="commission.paid_date">
            <p class="text-gray-500 mb-1">Ngày thực chi</p>
            <p class="font-medium text-green-700">{{ commission.paid_date }}</p>
          </div>
          <div v-if="commission.notes" class="col-span-3">
            <p class="text-gray-500 mb-1">Lý do / Ghi chú</p>
            <p class="text-gray-800">{{ commission.notes }}</p>
          </div>
        </div>

        <!-- Liên kết nghiệp vụ -->
        <div v-if="commission.customer || commission.order || commission.project" class="mt-4 pt-4 border-t border-gray-100 flex gap-4 text-sm">
          <div v-if="commission.customer">
            <span class="text-gray-500">KH:</span>
            <span class="ml-1 font-medium text-gray-800">{{ commission.customer }}</span>
          </div>
          <div v-if="commission.order">
            <span class="text-gray-500">Đơn hàng:</span>
            <Link :href="route('sales.orders.show', commission.order_id)"
              class="ml-1 font-mono text-primary-600 hover:underline">{{ commission.order }}</Link>
          </div>
          <div v-if="commission.project">
            <span class="text-gray-500">Dự án:</span>
            <Link :href="route('projects.projects.show', commission.project_id)"
              class="ml-1 font-mono text-primary-600 hover:underline">{{ commission.project }}</Link>
          </div>
        </div>
      </div>

      <!-- Timeline duyệt -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-4">Lịch sử phê duyệt</h2>
        <ol class="space-y-4">
          <li class="flex gap-3">
            <div class="w-7 h-7 rounded-full bg-gray-100 flex items-center justify-center text-xs font-bold text-gray-500 shrink-0">1</div>
            <div>
              <p class="text-sm font-medium text-gray-800">Người đề xuất: {{ commission.creator }}</p>
              <p class="text-xs text-gray-400">Tạo khoản hoa hồng</p>
            </div>
          </li>
          <li class="flex gap-3">
            <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold shrink-0"
              :class="commission.approver1 ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-400'">2</div>
            <div>
              <p class="text-sm font-medium text-gray-800">Duyệt L1 (Trưởng phòng):
                <span v-if="commission.approver1" class="text-yellow-700">{{ commission.approver1 }}</span>
                <span v-else class="text-gray-400">Chờ duyệt</span>
              </p>
              <p v-if="commission.approved1_at" class="text-xs text-gray-400">{{ commission.approved1_at }}</p>
            </div>
          </li>
          <li class="flex gap-3">
            <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold shrink-0"
              :class="commission.approver2 ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-400'">3</div>
            <div>
              <p class="text-sm font-medium text-gray-800">Duyệt L2 (Giám đốc):
                <span v-if="commission.approver2" class="text-green-700">{{ commission.approver2 }}</span>
                <span v-else class="text-gray-400">Chờ duyệt</span>
              </p>
              <p v-if="commission.approved2_at" class="text-xs text-gray-400">{{ commission.approved2_at }}</p>
            </div>
          </li>
          <li class="flex gap-3">
            <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold shrink-0"
              :class="commission.payer ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-400'">4</div>
            <div>
              <p class="text-sm font-medium text-gray-800">Kế toán thanh toán:
                <span v-if="commission.payer" class="text-blue-700">{{ commission.payer }}</span>
                <span v-else class="text-gray-400">Chờ thanh toán</span>
              </p>
              <p v-if="commission.paid_at" class="text-xs text-gray-400">{{ commission.paid_at }}</p>
            </div>
          </li>
        </ol>
      </div>
    </div>

    <!-- Modal từ chối -->
    <div v-if="showRejectModal" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-xl shadow-xl max-w-md w-full p-6 space-y-4">
        <h3 class="text-lg font-semibold text-gray-900">Từ chối khoản hoa hồng</h3>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Lý do từ chối <span class="text-red-500">*</span></label>
          <textarea v-model="rejectReason" rows="3" placeholder="Nhập lý do..."
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-500"></textarea>
        </div>
        <div class="flex justify-end gap-3">
          <button @click="showRejectModal = false; rejectReason = ''"
            class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium">
            Hủy
          </button>
          <button @click="submitReject" :disabled="!rejectReason.trim() || busy"
            class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50">
            Xác nhận từ chối
          </button>
        </div>
      </div>
    </div>

    <!-- Modal xóa -->
    <div v-if="showDeleteModal" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-xl shadow-xl max-w-md w-full p-6">
        <h3 class="text-base font-semibold text-gray-900 mb-2">Xóa khoản hoa hồng</h3>
        <p class="text-sm text-gray-600 mb-5">
          Bạn có chắc muốn <strong class="text-red-600">xóa vĩnh viễn</strong> khoản hoa hồng
          <strong>{{ commission.code }}</strong>? Thao tác này không thể hoàn tác.
        </p>
        <div class="flex justify-end gap-2">
          <button @click="showDeleteModal = false"
            class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium">Hủy</button>
          <button @click="doDelete"
            class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Xóa</button>
        </div>
      </div>
    </div>

    <!-- Modal thanh toán -->
    <div v-if="showPayForm" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-xl shadow-xl max-w-sm w-full p-6 space-y-4">
        <h3 class="text-lg font-semibold text-gray-900">Ghi nhận thanh toán</h3>
        <div>
          <p class="text-sm text-gray-600 mb-3">Số tiền: <span class="font-bold text-gray-900">{{ fmt(commission.amount) }}</span></p>
          <label class="block text-sm font-medium text-gray-700 mb-1">Ngày thực chi <span class="text-red-500">*</span></label>
          <input v-model="paidDate" type="date"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500" />
        </div>
        <div class="flex justify-end gap-3">
          <button @click="showPayForm = false"
            class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium">
            Hủy
          </button>
          <button @click="submitPay" :disabled="!paidDate || busy"
            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50">
            Xác nhận
          </button>
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
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ commission: Object });

const { hasPermission } = usePermission();
const can = hasPermission;
const { formatVnd: fmt } = useCurrency();
const busy = ref(false);
const showRejectModal  = ref(false);
const showPayForm      = ref(false);
const showDeleteModal  = ref(false);
const rejectReason    = ref('');
const paidDate        = ref(new Date().toISOString().split('T')[0]);

function doAction(action) {
  if (busy.value) return;
  busy.value = true;
  router.post(route(`sales.commissions.${action}`, props.commission.id), {}, {
    onFinish: () => { busy.value = false; },
  });
}

function submitReject() {
  if (busy.value || !rejectReason.value.trim()) return;
  busy.value = true;
  router.post(route('sales.commissions.reject', props.commission.id),
    { reject_reason: rejectReason.value },
    {
      onSuccess: () => { showRejectModal.value = false; rejectReason.value = ''; },
      onFinish:  () => { busy.value = false; },
    }
  );
}

function doDelete() {
  showDeleteModal.value = false;
  router.delete(route('sales.commissions.destroy', props.commission.id));
}

function submitPay() {
  if (busy.value || !paidDate.value) return;
  busy.value = true;
  router.post(route('sales.commissions.pay', props.commission.id),
    { paid_date: paidDate.value },
    {
      onSuccess: () => { showPayForm.value = false; },
      onFinish:  () => { busy.value = false; },
    }
  );
}

</script>
