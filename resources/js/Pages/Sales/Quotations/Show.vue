<template>
  <AppLayout>
    <div class="max-w-5xl space-y-5">
      <!-- Breadcrumb & Actions -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <Link :href="route('sales.quotations.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <h1 class="text-2xl font-bold text-gray-900">{{ quotation.code }}</h1>
          <StatusBadge :color="quotation.status_color">{{ quotation.status_label }}</StatusBadge>
        </div>
        <div class="flex gap-2">
          <a :href="route('sales.quotations.pdf', quotation.id)" target="_blank"
            class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            Xuất PDF
          </a>
          <Link v-if="quotation.status === 'draft'" :href="route('sales.quotations.edit', quotation.id)"
            class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
            Sửa
          </Link>
        </div>
      </div>

      <!-- Meta -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Khách hàng</p>
          <p class="font-semibold text-gray-800">{{ quotation.customer.name }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Hiệu lực đến</p>
          <p class="font-semibold text-gray-800">{{ quotation.valid_until ?? '—' }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Ngày tạo</p>
          <p class="font-semibold text-gray-800">{{ quotation.created_at }}</p>
          <p class="text-xs text-gray-500 mt-1">Bởi {{ quotation.creator }}</p>
        </div>
      </div>

      <!-- Items table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200">
          <h2 class="text-base font-semibold text-gray-800">Chi tiết hàng hóa / dịch vụ</h2>
        </div>
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">#</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tên</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">ĐVT</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">SL</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Đơn giá</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">CK%</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Thành tiền</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="(item, i) in quotation.items" :key="item.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 text-gray-500">{{ i + 1 }}</td>
              <td class="px-5 py-3 text-gray-800">
                {{ item.name }}
                <span class="ml-1 text-xs text-gray-400">({{ item.item_type === 'product' ? 'SP' : 'DV' }})</span>
              </td>
              <td class="px-5 py-3 text-gray-600">{{ item.unit ?? '—' }}</td>
              <td class="px-5 py-3 text-right text-gray-700">{{ item.quantity }}</td>
              <td class="px-5 py-3 text-right text-gray-700">{{ formatVnd(item.unit_price) }}</td>
              <td class="px-5 py-3 text-right text-gray-600">
                <template v-if="item.discount_amount > 0">
                  <span class="text-red-600">-{{ formatVnd(item.discount_amount) }}</span>
                  <span class="block text-xs text-gray-400">{{ item.discount_percent > 0 ? item.discount_percent + '%' : '' }}</span>
                </template>
                <template v-else>—</template>
              </td>
              <td class="px-5 py-3 text-right font-medium text-gray-800">{{ formatVnd(item.line_total) }}</td>
            </tr>
          </tbody>
          <tfoot class="bg-gray-50 border-t border-gray-200">
            <tr>
              <td colspan="6" class="px-5 py-2 text-right text-sm text-gray-600">Tổng trước chiết khấu:</td>
              <td class="px-5 py-2 text-right font-medium text-gray-700">{{ formatVnd(quotation.subtotal) }}</td>
            </tr>
            <tr v-if="quotation.discount_amount > 0">
              <td colspan="6" class="px-5 py-2 text-right text-sm text-gray-600">Chiết khấu ({{ quotation.discount_percent }}%):</td>
              <td class="px-5 py-2 text-right font-medium text-red-600">- {{ formatVnd(quotation.discount_amount) }}</td>
            </tr>
            <tr>
              <td colspan="6" class="px-5 py-3 text-right font-bold text-gray-800">TỔNG CỘNG:</td>
              <td class="px-5 py-3 text-right font-bold text-primary-700 text-base">{{ formatVnd(quotation.total) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Notes -->
      <div v-if="quotation.notes" class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 text-sm text-gray-700">
        <p class="font-semibold text-yellow-800 mb-1">Ghi chú</p>
        <p>{{ quotation.notes }}</p>
      </div>

      <!-- Linked orders -->
      <div v-if="quotation.orders?.length" class="bg-white rounded-xl border border-gray-200 p-4">
        <p class="text-sm font-semibold text-gray-700 mb-2">Đơn hàng liên kết</p>
        <div class="flex gap-2 flex-wrap">
          <Link v-for="o in quotation.orders" :key="o.id" :href="route('sales.orders.show', o.id)"
            class="px-3 py-1 bg-blue-50 text-blue-700 rounded-lg text-sm font-mono hover:bg-blue-100">
            {{ o.code }}
          </Link>
        </div>
      </div>

      <!-- Tài liệu đính kèm -->
      <div class="bg-white rounded-xl border border-gray-200 p-5">
        <p class="text-sm font-semibold text-gray-700 mb-3">Tài liệu đính kèm</p>
        <div v-if="quotation.file_name" class="flex items-center gap-3 px-3 py-2 bg-gray-50 rounded-lg">
          <svg class="w-5 h-5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
          </svg>
          <span class="text-sm text-gray-800 flex-1 truncate">{{ quotation.file_name }}</span>
          <a :href="quotation.file_url" target="_blank" download
            class="text-primary-600 hover:text-primary-800 text-xs font-medium whitespace-nowrap">Tải xuống</a>
          <button @click="deleteFile"
            class="text-red-500 hover:text-red-700 text-xs font-medium whitespace-nowrap">Xóa</button>
        </div>
        <div v-else class="space-y-2">
          <label class="block cursor-pointer">
            <input type="file" class="hidden" ref="fileInput" @change="onFileSelected">
            <div class="px-3 py-2 text-sm text-gray-500 bg-gray-50 border border-dashed border-gray-300 rounded-lg hover:bg-gray-100 text-center">
              {{ attachForm.file ? attachForm.file.name : 'Nhấn để chọn file...' }}
            </div>
          </label>
          <div v-if="attachForm.file" class="flex justify-end">
            <button @click="uploadFile" :disabled="attachForm.processing"
              class="px-4 py-1.5 bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white text-sm rounded-lg">
              {{ attachForm.processing ? 'Đang tải...' : 'Đính kèm' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Action buttons -->
      <div class="flex flex-wrap gap-2">
        <form v-if="quotation.status === 'draft'" @submit.prevent="action('mark-sent')" method="post">
          <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
            Đánh dấu đã gửi
          </button>
        </form>
        <form v-if="quotation.status === 'sent'" @submit.prevent="action('approve')" method="post">
          <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium">
            Duyệt báo giá
          </button>
        </form>
        <form v-if="['draft','sent'].includes(quotation.status)" @submit.prevent="action('reject')" method="post">
          <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium">
            Từ chối
          </button>
        </form>
        <form v-if="quotation.status === 'approved'" @submit.prevent="action('convert-to-order')" method="post">
          <button type="submit" class="px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg text-sm font-medium">
            Tạo đơn hàng
          </button>
        </form>
        <form v-if="quotation.status === 'draft'" @submit.prevent="deleteQuotation" method="post">
          <button type="submit" class="px-4 py-2 border border-red-300 text-red-600 hover:bg-red-50 rounded-lg text-sm font-medium">
            Xóa
          </button>
        </form>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ quotation: Object });

const { formatVnd } = useCurrency();

const action = (act) => {
  router.post(route(`sales.quotations.${act}`, props.quotation.id));
};

const deleteQuotation = () => {
  if (confirm('Xác nhận xóa báo giá này?')) {
    router.delete(route('sales.quotations.destroy', props.quotation.id));
  }
};

const fileInput = ref(null);
const attachForm = useForm({ file: null });

const onFileSelected = (e) => {
  attachForm.file = e.target.files[0] ?? null;
};

const uploadFile = () => {
  attachForm.post(route('sales.quotations.attachment.upload', props.quotation.id), {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => { attachForm.reset(); if (fileInput.value) fileInput.value.value = ''; },
  });
};

const deleteFile = () => {
  if (confirm('Xóa file đính kèm?')) {
    router.delete(route('sales.quotations.attachment.delete', props.quotation.id));
  }
};
</script>
