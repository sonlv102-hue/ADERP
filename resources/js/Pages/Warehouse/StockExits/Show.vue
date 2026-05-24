<template>
  <AppLayout>
    <div class="space-y-6">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <Link :href="route('warehouse.stock-exits.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <h1 class="text-2xl font-bold text-gray-900">{{ exit.code }}</h1>
          <StatusBadge :color="exit.status_color">{{ exit.status_label }}</StatusBadge>
        </div>
        <div class="flex items-center gap-2">
          <a :href="route('warehouse.stock-exits.pdf', exit.id)" target="_blank"
            class="flex items-center gap-1.5 px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
            </svg>
            In phiếu
          </a>
          <Link v-if="exit.status === 'draft'" :href="route('warehouse.stock-exits.edit', exit.id)"
            class="flex items-center gap-1.5 px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
            Sửa
          </Link>
          <button v-if="exit.status === 'draft'" @click="confirmExit"
            class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Xác nhận
          </button>
          <button v-if="exit.status === 'draft'" @click="cancelExit"
            class="px-4 py-2 border border-red-300 text-red-600 rounded-lg text-sm font-medium hover:bg-red-50">
            Hủy phiếu
          </button>
          <button v-if="['draft','cancelled'].includes(exit.status)" @click="showDeleteModal = true"
            class="px-4 py-2 border border-red-400 text-red-600 rounded-lg text-sm font-medium hover:bg-red-50">
            Xóa
          </button>
        </div>
      </div>

      <Teleport to="body">
        <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
            <h3 class="text-base font-semibold text-gray-900 mb-2">Xóa phiếu xuất kho</h3>
            <p class="text-sm text-gray-600 mb-5">
              Bạn có chắc muốn <strong class="text-red-600">xóa vĩnh viễn</strong> phiếu
              <strong>{{ exit.code }}</strong>? Thao tác này không thể hoàn tác.
            </p>
            <div class="flex justify-end gap-2">
              <button @click="showDeleteModal = false"
                class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</button>
              <button @click="doDelete"
                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium">Xóa phiếu</button>
            </div>
          </div>
        </div>

        <div v-if="showConfirmModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
            <div class="flex items-start gap-3 mb-4">
              <div class="flex-shrink-0 w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                </svg>
              </div>
              <div>
                <h3 class="text-base font-semibold text-gray-900">Đơn hàng chưa có hợp đồng</h3>
                <p class="text-sm text-gray-600 mt-1">
                  Đơn hàng <strong>{{ exit.order?.code }}</strong> chưa có hợp đồng bán hiệu lực.
                  Xuất kho khi chưa có hợp đồng có thể gây rủi ro về chứng từ pháp lý và thu hồi công nợ.
                </p>
              </div>
            </div>
            <p class="text-sm font-medium text-gray-800 mb-4">Bạn có chắc muốn tiếp tục xác nhận xuất kho?</p>
            <div class="flex justify-end gap-2">
              <button @click="showConfirmModal = false"
                class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Quay lại</button>
              <button @click="doConfirmExit"
                class="px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg text-sm font-medium">Vẫn xác nhận</button>
            </div>
          </div>
        </div>
      </Teleport>

      <div v-if="exit.status === 'draft' && exit.order && !hasOrderContract"
        class="flex items-start gap-3 bg-amber-50 border border-amber-300 rounded-xl px-5 py-4">
        <svg class="w-5 h-5 text-amber-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
        </svg>
        <div class="flex-1">
          <p class="text-sm font-semibold text-amber-800">Đơn hàng chưa có hợp đồng bán</p>
          <p class="text-xs text-amber-700 mt-0.5">
            Đơn hàng <strong>{{ exit.order.code }}</strong> chưa có hợp đồng bán hiệu lực.
            Xác nhận xuất kho sẽ yêu cầu xác nhận thêm.
            <Link :href="route('sales.contracts.create')" class="underline font-medium ml-1">Tạo hợp đồng →</Link>
          </p>
        </div>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-base font-semibold text-gray-800 mb-4">Thông tin phiếu xuất</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-y-4 gap-x-8 text-sm">
          <div>
            <span class="text-gray-500">Ngày xuất</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ exit.exit_date }}</p>
          </div>
          <div>
            <span class="text-gray-500">Kho</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ exit.warehouse?.name ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Khách hàng</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ exit.customer?.name ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Đơn hàng</span>
            <div class="mt-0.5">
              <template v-if="exit.order">
                <Link :href="route('sales.orders.show', exit.order.id)"
                  class="font-medium text-primary-600 hover:underline">{{ exit.order.code }}</Link>
                <StatusBadge :color="exit.order.status_color" class="ml-2 text-xs">{{ exit.order.status_label }}</StatusBadge>
              </template>
              <span v-else class="font-medium text-gray-900">—</span>
            </div>
          </div>
          <div>
            <span class="text-gray-500">Lý do</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ exit.reason ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Người tạo</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ exit.creator?.name ?? '—' }}</p>
          </div>
          <div class="sm:col-span-2">
            <span class="text-gray-500">Ghi chú</span>
            <p class="font-medium text-gray-900 mt-0.5 whitespace-pre-line">{{ exit.notes ?? '—' }}</p>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200">
          <h2 class="text-base font-semibold text-gray-800">Chi tiết hàng hóa</h2>
        </div>
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã SP</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tên sản phẩm</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Đơn vị</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">SL</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Đơn giá</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Thành tiền</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <template v-for="item in exit.items" :key="item.id">
              <tr class="hover:bg-gray-50">
                <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ item.product_code }}</td>
                <td class="px-5 py-3 font-medium text-gray-900">{{ item.product_name }}</td>
                <td class="px-5 py-3 text-gray-600">{{ item.unit }}</td>
                <td class="px-5 py-3 text-gray-600">{{ item.quantity.toLocaleString('vi-VN') }}</td>
                <td class="px-5 py-3 text-gray-600">{{ formatVnd(item.unit_price) }}</td>
                <td class="px-5 py-3 font-medium text-gray-900">{{ formatVnd(item.total) }}</td>
              </tr>
              <tr v-if="item.serials?.length" class="bg-blue-50">
                <td colspan="6" class="px-5 py-2">
                  <div class="flex flex-wrap gap-2">
                    <span
                      v-for="s in item.serials" :key="s.id"
                      class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium border"
                      :class="{
                        'bg-green-50 border-green-200 text-green-700': s.status === 'in_stock',
                        'bg-blue-50 border-blue-200 text-blue-700': s.status === 'sold',
                        'bg-yellow-50 border-yellow-200 text-yellow-700': s.status === 'in_service',
                        'bg-gray-50 border-gray-200 text-gray-600': s.status === 'retired',
                      }">
                      {{ s.serial_number }}
                      <span class="opacity-70">· {{ s.status_label }}</span>
                    </span>
                  </div>
                </td>
              </tr>
            </template>
            <tr v-if="!exit.items?.length">
              <td colspan="6" class="px-5 py-10 text-center text-gray-400">Không có hàng hóa</td>
            </tr>
          </tbody>
          <tfoot v-if="exit.items?.length" class="bg-gray-50 border-t border-gray-200">
            <tr>
              <td colspan="5" class="px-5 py-3 text-right font-semibold text-gray-700">Tổng cộng:</td>
              <td class="px-5 py-3 font-bold text-gray-900">{{ formatVnd(grandTotal) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  exit: Object,
  hasOrderContract: { type: Boolean, default: true },
});

const { formatVnd } = useCurrency();
const showDeleteModal  = ref(false);
const showConfirmModal = ref(false);

const grandTotal = computed(() =>
  (props.exit.items ?? []).reduce((sum, item) => sum + item.total, 0)
);

const confirmExit = () => {
  if (props.exit.order && !props.hasOrderContract) {
    showConfirmModal.value = true;
  } else {
    doConfirmExit();
  }
};

const doConfirmExit = () => {
  showConfirmModal.value = false;
  router.post(route('warehouse.stock-exits.confirm', props.exit.id));
};

const cancelExit = () => router.post(route('warehouse.stock-exits.cancel', props.exit.id));
const doDelete   = () => {
  showDeleteModal.value = false;
  router.delete(route('warehouse.stock-exits.destroy', props.exit.id));
};
</script>
