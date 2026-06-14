<template>
  <AppLayout>
    <div class="max-w-3xl space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <Link :href="route('accounting.cash-vouchers.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <h1 class="text-2xl font-bold text-gray-900">{{ voucher.code }}</h1>
          <StatusBadge :color="voucher.type_color">{{ voucher.type_label }}</StatusBadge>
          <StatusBadge :color="voucher.status_color">{{ voucher.status_label }}</StatusBadge>
          <span v-if="voucher.journal_mode === 'manual'"
            class="px-2 py-0.5 text-xs rounded-full bg-amber-50 text-amber-700 font-medium border border-amber-200">
            BT thủ công
          </span>
        </div>
        <div class="flex gap-2">
          <Link v-if="voucher.status === 'draft'"
            :href="route('accounting.cash-vouchers.edit', voucher.id)"
            class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium">
            Sửa
          </Link>
          <button v-if="voucher.status === 'draft'" @click="handleConfirm"
            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Ghi sổ
          </button>
          <button v-if="voucher.status === 'confirmed'" @click="handleUnpost"
            class="bg-amber-500 hover:bg-amber-600 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Thu hồi ghi sổ
          </button>
          <button v-if="voucher.status !== 'cancelled'" @click="handleCancel"
            class="bg-red-100 hover:bg-red-200 text-red-700 px-4 py-2 rounded-lg text-sm font-medium">
            Hủy phiếu
          </button>
          <button v-if="voucher.status === 'cancelled'" @click="handleDelete"
            class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Xóa phiếu
          </button>
        </div>
      </div>

      <!-- Flash messages -->
      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm">
        {{ $page.props.flash.success }}
      </div>
      <div v-if="$page.props.flash?.error" class="bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm">
        {{ $page.props.flash.error }}
      </div>

      <!-- Thông tin phiếu -->
      <div class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100">
        <div class="grid grid-cols-2 gap-0">
          <div class="px-5 py-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Quỹ</p>
            <p class="font-semibold text-gray-800">{{ voucher.fund }}</p>
          </div>
          <div class="px-5 py-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Ngày</p>
            <p class="font-semibold text-gray-800">{{ voucher.voucher_date }}</p>
          </div>
          <div class="px-5 py-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Số tiền</p>
            <p class="text-2xl font-bold" :class="voucher.type === 'receipt' ? 'text-green-600' : 'text-red-600'">
              {{ voucher.type === 'receipt' ? '+' : '-' }}{{ formatVnd(voucher.amount) }}
            </p>
          </div>
          <div class="px-5 py-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Đối tác</p>
            <p class="font-semibold text-gray-800">{{ voucher.counterparty ?? '—' }}</p>
          </div>
        </div>
        <div class="px-5 py-4">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Diễn giải</p>
          <p class="text-gray-800">{{ voucher.description }}</p>
        </div>
        <div v-if="voucher.business_type" class="px-5 py-4">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Nghiệp vụ</p>
          <p class="text-gray-800">{{ businessTypeLabel(voucher.business_type) }}</p>
        </div>
        <div v-if="voucher.edited_by_user && voucher.edit_reason" class="px-5 py-4 bg-amber-50">
          <p class="text-xs font-semibold text-amber-700 uppercase tracking-wider mb-1">Lý do điều chỉnh bút toán</p>
          <p class="text-amber-800 text-sm">{{ voucher.edit_reason }}</p>
        </div>
        <div class="px-5 py-4 flex justify-between text-xs text-gray-400">
          <span>Người tạo: {{ voucher.creator }}</span>
          <span>{{ voucher.created_at }}</span>
        </div>
      </div>

      <!-- Bút toán liên kết -->
      <div class="bg-white rounded-xl border border-gray-200 p-5">
        <div class="flex items-center justify-between mb-3">
          <h2 class="text-base font-semibold text-gray-800">Bút toán liên kết</h2>
          <div class="flex items-center gap-2">
            <span v-if="journal_entry_code" class="text-xs text-gray-500 font-mono">{{ journal_entry_code }}</span>
            <span v-if="voucher.status === 'confirmed'"
              class="px-2 py-0.5 text-xs rounded-full bg-green-50 text-green-700 font-medium border border-green-200">
              Đã ghi sổ
            </span>
            <span v-else-if="voucher.status === 'draft'"
              class="px-2 py-0.5 text-xs rounded-full bg-gray-100 text-gray-500 font-medium">
              Chưa ghi sổ
            </span>
          </div>
        </div>

        <div v-if="lines && lines.length > 0" class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs font-semibold text-gray-500 uppercase tracking-wider border-b border-gray-200">
                <th class="pb-2 pr-4 w-28">TK Nợ</th>
                <th class="pb-2 pr-4 w-28">TK Có</th>
                <th class="pb-2 pr-4">Diễn giải</th>
                <th class="pb-2 pr-4 w-32">Đối tượng</th>
                <th class="pb-2 text-right w-36">Số tiền</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="line in lines" :key="line.id">
                <td class="py-2 pr-4 font-mono text-gray-800">{{ line.debit_account }}</td>
                <td class="py-2 pr-4 font-mono text-gray-800">{{ line.credit_account }}</td>
                <td class="py-2 pr-4 text-gray-600 text-xs">{{ line.description ?? '—' }}</td>
                <td class="py-2 pr-4 text-gray-600 text-xs">
                  <span v-if="line.partner_name">
                    {{ partnerPrefix(line.partner_type) }}: {{ line.partner_name }}
                  </span>
                  <span v-else>—</span>
                </td>
                <td class="py-2 text-right font-medium text-gray-800">{{ formatVnd(line.amount) }}</td>
              </tr>
            </tbody>
            <tfoot v-if="lines.length > 1" class="border-t border-gray-200">
              <tr>
                <td colspan="4" class="pt-2 text-xs font-semibold text-gray-500 text-right pr-4">Tổng cộng</td>
                <td class="pt-2 text-right font-bold text-gray-900">{{ formatVnd(totalAmount) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
        <p v-else class="text-sm text-gray-400 italic">Chưa có bút toán. Thêm bút toán khi sửa phiếu hoặc ghi sổ để tự sinh.</p>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  voucher:            Object,
  lines:              Array,
  journal_entry_code: String,
});

const { formatVnd } = useCurrency();

const totalAmount = computed(() =>
  (props.lines ?? []).reduce((sum, l) => sum + (parseFloat(l.amount) || 0), 0)
);

const BUSINESS_TYPE_LABELS = {
  advance_payment:  'Chi tạm ứng',
  advance_return:   'Thu hoàn ứng',
  collect_offset:   'Thu tiền đối ứng cá nhân',
  pay_offset:       'Chi hoàn trả đối ứng',
  pay_supplier:     'Chi trả nhà cung cấp',
  collect_customer: 'Thu tiền khách hàng',
  expense_payment:  'Chi phí bằng tiền',
};

function businessTypeLabel(value) {
  return BUSINESS_TYPE_LABELS[value] ?? value;
}

function partnerPrefix(type) {
  return type === 'supplier' ? 'NCC' : type === 'customer' ? 'KH' : 'NV';
}

function handleConfirm() {
  if (window.confirm('Ghi sổ phiếu này? Bút toán sẽ được hạch toán ngay.')) {
    router.post(route('accounting.cash-vouchers.confirm', props.voucher.id));
  }
}

function handleUnpost() {
  if (window.confirm('Thu hồi ghi sổ? Bút toán liên kết sẽ bị đảo. Phiếu trở về trạng thái nháp.')) {
    router.post(route('accounting.cash-vouchers.unpost', props.voucher.id));
  }
}

function handleCancel() {
  if (window.confirm('Hủy phiếu này? Bút toán liên kết sẽ bị đảo.')) {
    router.post(route('accounting.cash-vouchers.cancel', props.voucher.id));
  }
}

function handleDelete() {
  if (window.confirm('Xóa vĩnh viễn phiếu này? Không thể hoàn tác.')) {
    router.delete(route('accounting.cash-vouchers.destroy', props.voucher.id));
  }
}
</script>
