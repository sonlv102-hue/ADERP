<template>
  <AppLayout>
    <div class="max-w-2xl space-y-5">

      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div class="flex items-center gap-3">
          <Link :href="route('accounting.fund-transfers.index')" class="text-gray-400 hover:text-gray-600">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <div>
            <h1 class="text-xl font-bold text-gray-900">{{ transfer.transfer_no }}</h1>
            <p class="text-sm text-gray-500 mt-0.5">Phiếu luân chuyển quỹ · {{ transfer.transfer_date_f }}</p>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <span :class="statusClass(transfer.status_color)"
            class="inline-flex px-3 py-1 rounded-full text-xs font-semibold">
            {{ transfer.status_label }}
          </span>

          <!-- Ghi sổ -->
          <button v-if="transfer.status === 'draft'" @click="postTransfer"
            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-semibold">
            Ghi sổ
          </button>

          <!-- Đảo bút toán -->
          <button v-if="transfer.status === 'posted'" @click="showReverseModal = true"
            class="border border-orange-400 text-orange-600 hover:bg-orange-50 px-4 py-2 rounded-lg text-sm">
            Đảo bút toán
          </button>

          <!-- Hủy nháp -->
          <button v-if="transfer.status === 'draft'" @click="cancelTransfer"
            class="border border-red-300 text-red-600 hover:bg-red-50 px-4 py-2 rounded-lg text-sm">
            Hủy phiếu
          </button>

          <!-- Xóa (đã hủy hoặc đã đảo) -->
          <button v-if="transfer.status === 'cancelled' || transfer.status === 'reversed'"
            @click="deleteTransfer"
            class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm">
            Xóa phiếu
          </button>
        </div>
      </div>

      <!-- Flash -->
      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm">
        {{ $page.props.flash.success }}
      </div>
      <div v-if="$page.props.flash?.error" class="bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm">
        {{ $page.props.flash.error }}
      </div>

      <!-- Detail card -->
      <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div>
            <p class="text-gray-500 text-xs">Ngày</p>
            <p class="font-medium text-gray-900">{{ transfer.transfer_date_f }}</p>
          </div>
          <div>
            <p class="text-gray-500 text-xs">Số tiền</p>
            <p class="font-bold text-gray-900 text-base">{{ formatVnd(transfer.amount) }}</p>
          </div>
          <div>
            <p class="text-gray-500 text-xs">Quỹ nguồn</p>
            <p class="font-medium text-gray-900">{{ transfer.from_fund?.name }}</p>
            <p class="text-xs text-gray-400 font-mono">TK {{ transfer.from_fund?.account_code || '(mặc định)' }}</p>
          </div>
          <div>
            <p class="text-gray-500 text-xs">Quỹ đích</p>
            <p class="font-medium text-gray-900">{{ transfer.to_fund?.name }}</p>
            <p class="text-xs text-gray-400 font-mono">TK {{ transfer.to_fund?.account_code || '(mặc định)' }}</p>
          </div>
          <div class="col-span-2">
            <p class="text-gray-500 text-xs">Diễn giải</p>
            <p class="text-gray-700">{{ transfer.description || '—' }}</p>
          </div>
        </div>

        <!-- Bút toán liên kết -->
        <div v-if="transfer.journal_entry" class="pt-3 border-t border-gray-100">
          <p class="text-xs text-gray-500 mb-1">Bút toán kế toán</p>
          <Link :href="route('accounting.journal-entries.show', transfer.journal_entry.id)"
            class="text-primary-600 hover:underline text-sm font-mono">
            {{ transfer.journal_entry.code }}
          </Link>
        </div>

        <div class="pt-3 border-t border-gray-100 text-xs text-gray-400 grid grid-cols-2 gap-2">
          <div><span class="text-gray-500">Tạo bởi:</span> {{ transfer.creator }}</div>
          <div><span class="text-gray-500">Tạo lúc:</span> {{ transfer.created_at }}</div>
          <div v-if="transfer.poster"><span class="text-gray-500">Ghi sổ bởi:</span> {{ transfer.poster }}</div>
          <div v-if="transfer.posted_at"><span class="text-gray-500">Ghi sổ lúc:</span> {{ transfer.posted_at }}</div>
          <div v-if="transfer.reverser"><span class="text-gray-500">Đảo bởi:</span> {{ transfer.reverser }}</div>
        </div>
      </div>

      <!-- Bút toán preview (chỉ khi draft) -->
      <div v-if="transfer.status === 'draft'" class="bg-blue-50 border border-blue-200 rounded-xl p-4 text-sm">
        <p class="font-semibold text-blue-800 mb-2">Bút toán sẽ được sinh khi ghi sổ</p>
        <div class="space-y-1 font-mono text-xs text-blue-900">
          <p>Dr {{ transfer.to_fund?.account_code || (transfer.to_fund?.type === 'bank' ? '1121' : '1111') }}
             — {{ transfer.to_fund?.name }} — {{ formatVnd(transfer.amount) }}</p>
          <p>Cr {{ transfer.from_fund?.account_code || (transfer.from_fund?.type === 'bank' ? '1121' : '1111') }}
             — {{ transfer.from_fund?.name }} — {{ formatVnd(transfer.amount) }}</p>
        </div>
      </div>
    </div>

    <!-- Modal đảo bút toán -->
    <div v-if="showReverseModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-xl shadow-xl max-w-md w-full p-6 space-y-4">
        <h3 class="text-lg font-bold text-gray-900">Đảo bút toán luân chuyển quỹ</h3>
        <p class="text-sm text-gray-600">Thao tác này sẽ tạo bút toán đảo và đổi trạng thái phiếu về "Đã đảo".</p>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Lý do đảo</label>
          <textarea v-model="reverseReason" rows="2"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-orange-400 outline-none"
            placeholder="Nhập lý do đảo bút toán..." />
        </div>
        <div class="flex gap-3 justify-end">
          <button @click="showReverseModal = false"
            class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</button>
          <button @click="doReverse"
            class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-sm font-medium">
            Xác nhận đảo
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
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ transfer: Object });
const { formatVnd } = useCurrency();

const showReverseModal = ref(false);
const reverseReason    = ref('');

function statusClass(color) {
  const map = {
    gray:   'bg-gray-100 text-gray-700',
    blue:   'bg-blue-100 text-blue-700',
    orange: 'bg-orange-100 text-orange-700',
    red:    'bg-red-100 text-red-700',
  };
  return map[color] ?? 'bg-gray-100 text-gray-700';
}

function postTransfer() {
  if (!confirm('Ghi sổ phiếu luân chuyển quỹ?')) return;
  router.post(route('accounting.fund-transfers.post', props.transfer.id), {}, { preserveScroll: true });
}

function cancelTransfer() {
  if (!confirm('Hủy phiếu nháp này?')) return;
  router.post(route('accounting.fund-transfers.cancel', props.transfer.id), {}, { preserveScroll: true });
}

function doReverse() {
  router.post(route('accounting.fund-transfers.reverse', props.transfer.id),
    { reason: reverseReason.value },
    { preserveScroll: true, onSuccess: () => { showReverseModal.value = false; } }
  );
}

function deleteTransfer() {
  if (!confirm(`Xóa phiếu ${props.transfer.transfer_no}? Thao tác này không thể hoàn tác.`)) return;
  router.delete(route('accounting.fund-transfers.destroy', props.transfer.id));
}
</script>
