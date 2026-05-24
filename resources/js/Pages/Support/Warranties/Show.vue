<template>
  <AppLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex items-start justify-between">
        <div class="flex items-center gap-3">
          <Link :href="route('support.warranties.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
          </Link>
          <div>
            <div class="flex items-center gap-3">
              <h1 class="text-2xl font-bold text-gray-900">{{ warranty.product_name }}</h1>
              <StatusBadge :color="warranty.status_color">{{ warranty.status_label }}</StatusBadge>
            </div>
            <p class="text-sm text-gray-500 mt-0.5">{{ warranty.code }} · {{ warranty.customer.name }}</p>
          </div>
        </div>
        <div class="flex gap-2">
          <Link v-if="can('tickets.create')" :href="route('support.warranties.edit', warranty.id)"
            class="border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium">
            Sửa
          </Link>
          <select v-if="can('tickets.close') && warranty.status !== 'void'" v-model="newStatus"
            @change="updateStatus"
            class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500">
            <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
        </div>
      </div>

      <!-- Detail card -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="grid grid-cols-2 gap-6 text-sm">
          <div>
            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Thiết bị</p>
            <p class="font-medium text-gray-800">{{ warranty.product_name }}</p>
            <p class="text-gray-500 text-xs mt-0.5">{{ warranty.product.name }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Số serial</p>
            <p class="font-mono text-gray-800">{{ warranty.serial_number || '—' }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Khách hàng</p>
            <p class="text-gray-800">{{ warranty.customer.name }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Đơn hàng</p>
            <Link v-if="warranty.order" :href="route('sales.orders.show', warranty.order.id)"
              class="text-primary-600 hover:underline">{{ warranty.order.code }}</Link>
            <p v-else class="text-gray-500">—</p>
          </div>
          <div>
            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Ngày bắt đầu</p>
            <p class="text-gray-800">{{ warranty.start_date }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Ngày hết hạn</p>
            <p class="font-medium" :class="warranty.status === 'active' ? 'text-green-700' : 'text-gray-500'">
              {{ warranty.end_date }}
            </p>
            <p class="text-xs text-gray-400 mt-0.5">{{ warranty.duration_months }} tháng</p>
          </div>
          <div class="col-span-2">
            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Điều khoản bảo hành</p>
            <p class="text-gray-700 whitespace-pre-wrap">{{ warranty.terms || '—' }}</p>
          </div>
          <div class="col-span-2">
            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Ghi chú</p>
            <p class="text-gray-700">{{ warranty.notes || '—' }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Tạo bởi</p>
            <p class="text-gray-600">{{ warranty.creator }} · {{ warranty.created_at }}</p>
          </div>
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

const props = defineProps({
  warranty: Object,
  statuses: Array,
});

const { hasPermission } = usePermission();
const can = hasPermission;

const newStatus = ref(props.warranty.status);

function updateStatus() {
  router.patch(route('support.warranties.status', props.warranty.id), { status: newStatus.value });
}
</script>
