<template>
  <AppLayout>
    <div class="max-w-5xl space-y-5">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <Link :href="route('sales.orders.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <h1 class="text-2xl font-bold text-gray-900">{{ order.code }}</h1>
          <StatusBadge :color="order.status_color">{{ order.status_label }}</StatusBadge>
        </div>
      </div>

      <!-- Meta -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Khách hàng</p>
          <p class="font-semibold text-gray-800">{{ order.customer.name }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Ngày đặt / Giao hàng</p>
          <p class="font-semibold text-gray-800">{{ order.order_date }}</p>
          <p class="text-xs text-gray-500 mt-1">{{ order.expected_delivery ? 'Giao: ' + order.expected_delivery : 'Chưa có ngày giao' }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Ngày tạo</p>
          <p class="font-semibold text-gray-800">{{ order.created_at }}</p>
          <p class="text-xs text-gray-500 mt-1">Bởi {{ order.creator }}</p>
        </div>
      </div>

      <!-- Quotation link -->
      <div v-if="order.quotation" class="bg-blue-50 border border-blue-200 rounded-xl p-4 text-sm">
        <span class="font-semibold text-blue-800">Từ báo giá: </span>
        <Link :href="route('sales.quotations.show', order.quotation.id)"
          class="text-blue-600 hover:text-blue-800 font-mono font-medium">{{ order.quotation.code }}</Link>
      </div>

      <!-- Delivery warning banner -->
      <div v-if="order.status === 'partial_delivered'" class="bg-orange-50 border border-orange-200 rounded-xl p-4">
        <div class="flex items-start gap-3">
          <svg class="w-5 h-5 text-orange-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
          </svg>
          <div class="text-sm">
            <p class="font-semibold text-orange-800 mb-1">Đơn hàng đang giao một phần</p>
            <div class="space-y-0.5 text-orange-700">
              <div v-for="item in productItemsWithRemaining" :key="item.id" class="flex gap-4">
                <span class="font-medium">{{ item.name }}</span>
                <span>Đã giao: <strong>{{ item.delivered_quantity }}</strong>/{{ item.quantity }} {{ item.unit ?? '' }}</span>
                <span class="text-orange-600 font-semibold">— Còn lại: {{ item.remaining }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Items table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200">
          <h2 class="text-base font-semibold text-gray-800">Chi tiết đơn hàng</h2>
        </div>
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">#</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tên</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">ĐVT</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">SL đặt</th>
              <th v-if="hasDeliveryTracking" class="text-right px-5 py-3 font-semibold text-gray-600">Đã giao</th>
              <th v-if="hasDeliveryTracking" class="text-right px-5 py-3 font-semibold text-orange-600">Còn lại</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Đơn giá</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Thành tiền</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="(item, i) in order.items" :key="item.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 text-gray-500">{{ i + 1 }}</td>
              <td class="px-5 py-3 text-gray-800">{{ item.name }}</td>
              <td class="px-5 py-3 text-gray-600">{{ item.unit ?? '—' }}</td>
              <td class="px-5 py-3 text-right text-gray-700">{{ item.quantity }}</td>
              <td v-if="hasDeliveryTracking" class="px-5 py-3 text-right"
                :class="item.delivered_quantity > 0 ? 'text-green-600 font-semibold' : 'text-gray-400'">
                {{ item.delivered_quantity }}
              </td>
              <td v-if="hasDeliveryTracking" class="px-5 py-3 text-right"
                :class="item.remaining > 0 ? 'text-orange-600 font-semibold' : 'text-green-600 font-semibold'">
                {{ item.remaining > 0 ? item.remaining : '✓' }}
              </td>
              <td class="px-5 py-3 text-right text-gray-700">{{ formatVnd(item.unit_price) }}</td>
              <td class="px-5 py-3 text-right font-medium text-gray-800">{{ formatVnd(item.line_total) }}</td>
            </tr>
          </tbody>
          <tfoot class="bg-gray-50 border-t border-gray-200">
            <tr>
              <td :colspan="hasDeliveryTracking ? 7 : 5" class="px-5 py-3 text-right font-bold text-gray-800">TỔNG CỘNG:</td>
              <td class="px-5 py-3 text-right font-bold text-primary-700 text-base">{{ formatVnd(order.total) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Notes -->
      <div v-if="order.notes" class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 text-sm text-gray-700">
        <p class="font-semibold text-yellow-800 mb-1">Ghi chú</p>
        <p>{{ order.notes }}</p>
      </div>

      <!-- Contracts link -->
      <div v-if="order.contracts?.length" class="bg-white rounded-xl border border-gray-200 p-4">
        <p class="text-sm font-semibold text-gray-700 mb-2">Hợp đồng liên kết</p>
        <div class="flex gap-2 flex-wrap">
          <Link v-for="c in order.contracts" :key="c.id" :href="route('sales.contracts.show', c.id)"
            class="px-3 py-1 bg-green-50 text-green-700 rounded-lg text-sm font-mono hover:bg-green-100">
            {{ c.code }}
          </Link>
        </div>
      </div>

      <!-- Tài liệu đính kèm -->
      <div class="bg-white rounded-xl border border-gray-200 p-5">
        <p class="text-sm font-semibold text-gray-700 mb-3">Tài liệu đính kèm</p>
        <div v-if="order.file_name" class="flex items-center gap-3 px-3 py-2 bg-gray-50 rounded-lg">
          <svg class="w-5 h-5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
          </svg>
          <span class="text-sm text-gray-800 flex-1 truncate">{{ order.file_name }}</span>
          <a :href="order.file_url" target="_blank" download
            class="text-primary-600 hover:text-primary-800 text-xs font-medium whitespace-nowrap">Tải xuống</a>
          <button @click="deleteFile"
            class="text-red-500 hover:text-red-700 text-xs font-medium whitespace-nowrap">Xóa</button>
        </div>
        <div v-else class="space-y-2">
          <label class="block cursor-pointer">
            <input type="file" class="hidden" ref="fileInput" @change="onFileSelected">
            <div class="px-3 py-2 text-sm text-gray-500 bg-gray-50 border border-dashed border-gray-300 rounded-lg hover:bg-gray-100 text-center">
              {{ selectedFile ? selectedFile.name : 'Nhấn để chọn file...' }}
            </div>
          </label>
          <div v-if="selectedFile" class="flex justify-end">
            <button @click="uploadFile" :disabled="uploading"
              class="px-4 py-1.5 bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white text-sm rounded-lg">
              {{ uploading ? 'Đang tải...' : 'Đính kèm' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Actions -->
      <div class="flex flex-wrap gap-2">
        <Link v-if="['pending','processing'].includes(order.status)"
          :href="route('sales.orders.edit', order.id)"
          class="px-4 py-2 bg-gray-700 hover:bg-gray-800 text-white rounded-lg text-sm font-medium">
          Sửa đơn hàng
        </Link>
        <form v-if="order.status === 'pending'" @submit.prevent="action('process')">
          <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
            Bắt đầu xử lý
          </button>
        </form>
        <form v-if="order.status === 'processing'" @submit.prevent="action('complete')">
          <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium">
            Hoàn thành
          </button>
        </form>
        <form v-if="['pending','processing'].includes(order.status)" @submit.prevent="action('cancel')">
          <button type="submit" class="px-4 py-2 border border-red-300 text-red-600 hover:bg-red-50 rounded-lg text-sm font-medium">
            Hủy đơn hàng
          </button>
        </form>
        <button v-if="order.status === 'cancelled'" @click="showDeleteModal = true"
          class="px-4 py-2 border border-red-400 text-red-600 hover:bg-red-50 rounded-lg text-sm font-medium">
          Xóa
        </button>
        <Link v-if="order.status !== 'cancelled'"
          :href="route('sales.contracts.create') + '?order_id=' + order.id"
          class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium">
          Tạo hợp đồng
        </Link>
      </div>
    </div>

    <Teleport to="body">
      <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
          <h3 class="text-base font-semibold text-gray-900 mb-2">Xóa đơn hàng</h3>
          <p class="text-sm text-gray-600 mb-5">
            Bạn có chắc muốn <strong class="text-red-600">xóa vĩnh viễn</strong> đơn hàng
            <strong>{{ order.code }}</strong>? Thao tác này không thể hoàn tác.
          </p>
          <div class="flex justify-end gap-2">
            <button @click="showDeleteModal = false"
              class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</button>
            <button @click="doDelete"
              class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium">Xóa đơn hàng</button>
          </div>
        </div>
      </div>
    </Teleport>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ order: Object });

const { formatVnd } = useCurrency();
const showDeleteModal = ref(false);
const doDelete = () => {
  showDeleteModal.value = false;
  router.delete(route('sales.orders.destroy', props.order.id));
};

// Chỉ hiển thị cột giao hàng khi đơn có sản phẩm (product_id) với delivery tracking
const hasDeliveryTracking = computed(() =>
  ['partial_delivered', 'completed'].includes(props.order.status) &&
  props.order.items?.some(i => i.delivered_quantity != null)
);

const productItemsWithRemaining = computed(() =>
  (props.order.items ?? []).filter(i => i.remaining > 0)
);

const action = (act) => {
  router.post(route(`sales.orders.${act}`, props.order.id));
};

const fileInput = ref(null);
const selectedFile = ref(null);
const uploading = ref(false);

const onFileSelected = (e) => {
  selectedFile.value = e.target.files[0] ?? null;
};

const uploadFile = () => {
  if (!selectedFile.value) return;
  const formData = new FormData();
  formData.append('file', selectedFile.value);
  uploading.value = true;
  router.post(route('sales.orders.attachment.upload', props.order.id), formData, {
    preserveScroll: true,
    onSuccess: () => {
      selectedFile.value = null;
      if (fileInput.value) fileInput.value.value = '';
    },
    onFinish: () => { uploading.value = false; },
  });
};

const deleteFile = () => {
  if (confirm('Xóa file đính kèm?')) {
    router.delete(route('sales.orders.attachment.delete', props.order.id));
  }
};
</script>
