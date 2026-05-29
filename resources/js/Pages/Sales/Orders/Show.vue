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
              <th class="text-right px-5 py-3 font-semibold text-gray-600">CK (%)</th>
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
              <td class="px-5 py-3 text-right">
                <span v-if="item.discount_percent > 0" class="text-green-600 font-medium">{{ item.discount_percent }}%</span>
                <span v-else class="text-gray-400">—</span>
              </td>
              <td class="px-5 py-3 text-right font-medium text-gray-800">{{ formatVnd(item.line_total) }}</td>
            </tr>
          </tbody>
          <tfoot class="bg-gray-50 border-t border-gray-200">
            <tr>
              <td :colspan="hasDeliveryTracking ? 8 : 6" class="px-5 py-3 text-right font-bold text-gray-800">TỔNG CỘNG:</td>
              <td class="px-5 py-3 text-right font-bold text-primary-700 text-base">{{ formatVnd(order.total) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Gợi ý giao hàng -->
      <div v-if="undeliveredItems.length && order.status !== 'cancelled'"
        class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200 flex items-center gap-2">
          <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <h2 class="text-base font-semibold text-gray-800">Gợi ý giao hàng</h2>
          <span class="text-xs text-gray-500">— {{ undeliveredItems.length }} mặt hàng chưa giao đủ</span>
        </div>
        <div class="divide-y divide-gray-100">
          <div v-for="item in undeliveredItems" :key="item.id"
            class="flex items-center justify-between px-5 py-3 gap-4">
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-800 truncate">{{ item.name }}</p>
              <p class="text-xs text-gray-500 mt-0.5">
                Còn lại: <strong class="text-orange-600">{{ item.remaining }}</strong> {{ item.unit ?? '' }}
                &nbsp;·&nbsp;
                Tồn kho:
                <strong :class="item.current_stock >= item.remaining ? 'text-green-600' : item.current_stock > 0 ? 'text-yellow-600' : 'text-red-600'">
                  {{ item.current_stock }}
                </strong>
              </p>
            </div>
            <div class="flex gap-2 shrink-0">
              <template v-if="item.current_stock >= item.remaining">
                <Link :href="route('warehouse.stock-exits.create') + '?order_id=' + order.id"
                  class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg flex items-center gap-1">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                  </svg>
                  Xuất kho
                </Link>
              </template>
              <template v-else>
                <span v-if="item.current_stock > 0"
                  class="px-2 py-1.5 bg-yellow-50 border border-yellow-200 text-yellow-700 text-xs rounded-lg">
                  Kho chỉ còn {{ item.current_stock }}
                </span>
                <Link :href="route('purchasing.purchase-orders.create')"
                  class="px-3 py-1.5 bg-orange-500 hover:bg-orange-600 text-white text-xs font-medium rounded-lg flex items-center gap-1">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-9H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                  </svg>
                  Mua hàng
                </Link>
              </template>
            </div>
          </div>
        </div>
        <div v-if="canCreateExit" class="px-5 py-3 bg-gray-50 border-t border-gray-100 flex justify-end">
          <Link :href="route('warehouse.stock-exits.create') + '?order_id=' + order.id"
            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 4m0 0l-3-4m3 4V4" />
            </svg>
            Tạo phiếu xuất kho cho đơn này
          </Link>
        </div>
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

      <!-- Khai báo hải quan (chỉ hiện khi khách hàng FDI) -->
      <div v-if="order.customer.is_fdi" class="rounded-xl border p-5 space-y-3"
        :class="order.customs_status === 'declared'
          ? 'bg-green-50 border-green-200'
          : 'bg-amber-50 border-amber-300'">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-2">
            <svg class="w-5 h-5 flex-shrink-0" :class="order.customs_status === 'declared' ? 'text-green-500' : 'text-amber-500'"
              fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
            <h2 class="text-base font-semibold" :class="order.customs_status === 'declared' ? 'text-green-800' : 'text-amber-800'">
              Khai báo hải quan
              <span class="ml-2 text-xs font-medium px-2 py-0.5 rounded-full"
                :class="order.customs_status === 'declared' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'">
                {{ order.customs_status_label }}
              </span>
            </h2>
          </div>
        </div>

        <!-- Đã khai báo -->
        <div v-if="order.customs_status === 'declared'" class="space-y-2 text-sm">
          <p class="text-green-700">
            <strong>Ngày khai báo:</strong> {{ order.customs_declared_at }}
          </p>
          <div v-if="order.customs_document_name" class="flex items-center gap-3 px-3 py-2 bg-white rounded-lg border border-green-200">
            <svg class="w-4 h-4 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
            </svg>
            <span class="text-sm text-gray-800 flex-1 truncate">{{ order.customs_document_name }}</span>
            <a :href="order.customs_document_url" target="_blank" download
              class="text-green-600 hover:text-green-800 text-xs font-medium whitespace-nowrap">Tải tờ khai</a>
          </div>
          <p v-if="order.customs_notes" class="text-green-700 italic text-xs">Ghi chú: {{ order.customs_notes }}</p>
        </div>

        <!-- Chờ khai báo — hiện form upload -->
        <div v-else class="space-y-3">
          <p class="text-sm text-amber-700">
            Khách hàng này là doanh nghiệp FDI. Vui lòng đính kèm <strong>tờ khai hải quan</strong> trước khi giao hàng.
          </p>
          <div class="space-y-2">
            <label class="block cursor-pointer">
              <input type="file" class="hidden" ref="customsFileInput" @change="onCustomsFileSelected">
              <div class="px-3 py-2 text-sm bg-white border border-dashed border-amber-300 rounded-lg hover:bg-amber-50 text-center text-amber-700">
                {{ customsFile ? customsFile.name : 'Nhấn để chọn tờ khai hải quan (PDF/ảnh)...' }}
              </div>
            </label>
            <textarea v-model="customsNotes" placeholder="Ghi chú (số tờ khai, ngày nộp...)" rows="2"
              class="w-full px-3 py-2 text-sm border border-amber-200 rounded-lg focus:ring-2 focus:ring-amber-400 outline-none bg-white" />
            <div v-if="customsFile" class="flex justify-end">
              <button @click="submitCustoms" :disabled="declaringCustoms"
                class="px-4 py-1.5 bg-amber-600 hover:bg-amber-700 disabled:opacity-60 text-white text-sm font-medium rounded-lg flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                {{ declaringCustoms ? 'Đang lưu...' : 'Xác nhận đã khai báo hải quan' }}
              </button>
            </div>
          </div>
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

// Các mặt hàng có sản phẩm (product_id) chưa giao đủ
const undeliveredItems = computed(() =>
  (props.order.items ?? []).filter(i => i.product_id && i.remaining > 0)
);

// Có thể tạo phiếu xuất khi ít nhất 1 sản phẩm đủ tồn kho
const canCreateExit = computed(() =>
  undeliveredItems.value.some(i => i.current_stock >= i.remaining)
);

const action = (act) => {
  router.post(route(`sales.orders.${act}`, props.order.id));
};

// Customs declaration
const customsFileInput = ref(null);
const customsFile = ref(null);
const customsNotes = ref('');
const declaringCustoms = ref(false);

const onCustomsFileSelected = (e) => {
  customsFile.value = e.target.files[0] ?? null;
};

const submitCustoms = () => {
  if (!customsFile.value) return;
  const formData = new FormData();
  formData.append('file', customsFile.value);
  if (customsNotes.value) formData.append('customs_notes', customsNotes.value);
  declaringCustoms.value = true;
  router.post(route('sales.orders.customs.declare', props.order.id), formData, {
    preserveScroll: true,
    onFinish: () => { declaringCustoms.value = false; },
  });
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
