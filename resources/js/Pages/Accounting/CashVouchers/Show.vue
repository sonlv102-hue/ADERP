<template>
  <AppLayout>
    <div class="max-w-2xl space-y-5">
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
        </div>
        <div class="flex gap-2">
          <Link v-if="voucher.status === 'draft'"
            :href="route('accounting.cash-vouchers.edit', voucher.id)"
            class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium">
            Sửa
          </Link>
          <button v-if="voucher.status === 'draft'" @click="handleConfirm"
            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Xác nhận
          </button>
          <button v-if="voucher.status !== 'cancelled'" @click="handleCancel"
            class="bg-red-100 hover:bg-red-200 text-red-700 px-4 py-2 rounded-lg text-sm font-medium">
            Hủy phiếu
          </button>
        </div>
      </div>

      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm">
        {{ $page.props.flash.success }}
      </div>
      <div v-if="$page.props.flash?.error" class="bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm">
        {{ $page.props.flash.error }}
      </div>

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
        <div class="px-5 py-4 flex justify-between text-xs text-gray-400">
          <span>Người tạo: {{ voucher.creator }}</span>
          <span>{{ voucher.created_at }}</span>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ voucher: Object });
const { formatVnd } = useCurrency();

function handleConfirm() {
  if (window.confirm('Xác nhận phiếu? Sau khi xác nhận sẽ ảnh hưởng số dư quỹ.')) {
    router.post(route('accounting.cash-vouchers.confirm', props.voucher.id));
  }
}

function handleCancel() {
  if (window.confirm('Hủy phiếu này?')) {
    router.post(route('accounting.cash-vouchers.cancel', props.voucher.id));
  }
}
</script>
