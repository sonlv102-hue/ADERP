<template>
  <AppLayout>
    <div class="max-w-3xl space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <div class="flex items-center gap-3">
          <Link :href="route('sales.contracts.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <h1 class="text-2xl font-bold text-gray-900">{{ contract.code }}</h1>
          <StatusBadge :color="contract.status_color">{{ contract.status_label }}</StatusBadge>
        </div>
        <div class="flex gap-2">
          <a :href="route('sales.contracts.pdf', contract.id)" target="_blank"
            class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            Xuất PDF
          </a>
          <Link v-if="contract.status === 'draft' || (can('admin.users') && contract.status === 'active')"
            :href="route('sales.contracts.edit', contract.id)"
            class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
            Sửa
          </Link>
        </div>
      </div>

      <!-- Details card -->
      <div class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100">
        <div class="px-6 py-4">
          <h2 class="text-lg font-semibold text-gray-800 mb-1">{{ contract.title }}</h2>
          <p class="text-xs text-gray-500">Tạo ngày {{ contract.created_at }} bởi {{ contract.creator }}</p>
        </div>
        <div class="grid grid-cols-2 gap-0 divide-x divide-gray-100">
          <div class="px-6 py-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Khách hàng</p>
            <p class="font-semibold text-gray-800">{{ contract.customer.name }}</p>
          </div>
          <div class="px-6 py-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Giá trị hợp đồng</p>
            <p class="text-2xl font-bold text-primary-700">{{ formatVnd(contract.value) }}</p>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-0 divide-x divide-gray-100">
          <div class="px-6 py-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Ngày bắt đầu</p>
            <p class="font-semibold text-gray-800">{{ contract.start_date ?? '—' }}</p>
          </div>
          <div class="px-6 py-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Ngày kết thúc</p>
            <p class="font-semibold text-gray-800">{{ contract.end_date ?? '—' }}</p>
          </div>
        </div>
        <div v-if="contract.order" class="px-6 py-4">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Đơn hàng liên kết</p>
          <Link :href="route('sales.orders.show', contract.order.id)"
            class="font-mono text-primary-600 hover:text-primary-800 font-medium">{{ contract.order.code }}</Link>
        </div>
        <div v-if="contract.notes" class="px-6 py-4">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Ghi chú</p>
          <p class="text-gray-700 text-sm">{{ contract.notes }}</p>
        </div>
      </div>

      <!-- Tài liệu đính kèm -->
      <FileAttachments
        :attachments="contract.attachments ?? []"
        :upload-url="route('attachments.store', { type: 'contract', id: contract.id })"
      />

      <!-- Actions -->
      <div class="flex flex-wrap gap-2">
        <form v-if="contract.status === 'draft'" @submit.prevent="action('activate')">
          <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium">
            Kích hoạt hợp đồng
          </button>
        </form>
        <form v-if="contract.status === 'active'" @submit.prevent="action('complete')">
          <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
            Hoàn thành
          </button>
        </form>
        <form v-if="['draft','active'].includes(contract.status)" @submit.prevent="action('terminate')">
          <button type="submit" class="px-4 py-2 border border-red-300 text-red-600 hover:bg-red-50 rounded-lg text-sm font-medium">
            Chấm dứt hợp đồng
          </button>
        </form>
        <!-- Thu hồi (admin): active/completed/terminated → draft -->
        <button v-if="can('admin.users') && contract.status !== 'draft'"
          @click="recallContract"
          class="px-4 py-2 border border-yellow-400 text-yellow-700 hover:bg-yellow-50 rounded-lg text-sm font-medium">
          Thu hồi (về Nháp)
        </button>
        <!-- Xóa: draft cho tất cả; terminated cho admin -->
        <button v-if="contract.status === 'draft' || (can('admin.users') && contract.status === 'terminated')"
          @click="deleteContract"
          class="px-4 py-2 border border-gray-300 text-gray-600 hover:bg-gray-50 rounded-lg text-sm font-medium">
          Xóa
        </button>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import FileAttachments from '@/Components/Shared/FileAttachments.vue';
import { useCurrency } from '@/composables/useCurrency';
import { usePermission } from '@/composables/usePermission';

const props = defineProps({ contract: Object });

const { formatVnd } = useCurrency();
const { hasPermission: can } = usePermission();

const action = (act) => {
  router.post(route(`sales.contracts.${act}`, props.contract.id));
};

const deleteContract = () => {
  if (confirm('Xác nhận xóa hợp đồng này?')) {
    router.delete(route('sales.contracts.destroy', props.contract.id));
  }
};

const recallContract = () => {
  if (!confirm('Thu hồi hợp đồng về trạng thái Nháp? Lưu ý: hóa đơn liên kết (nếu có) không bị ảnh hưởng.')) return;
  action('recall');
};

</script>
