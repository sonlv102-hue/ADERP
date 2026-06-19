<template>
  <AppLayout>
    <div class="space-y-6">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <div class="flex items-center gap-3">
          <Link :href="route('purchasing.purchase-returns.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <h1 class="text-2xl font-bold text-gray-900">{{ r.code }}</h1>
          <StatusBadge :color="r.status_color">{{ r.status_label }}</StatusBadge>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
          <Link v-if="r.status === 'draft'"
            :href="route('purchasing.purchase-returns.edit', r.id)"
            class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium">
            Sửa
          </Link>
          <button v-if="r.status === 'draft'" @click="confirmReturn"
            class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Xác nhận
          </button>
          <button v-if="r.status === 'draft'" @click="showCancelModal = true"
            class="px-4 py-2 border border-red-300 text-red-600 rounded-lg text-sm font-medium hover:bg-red-50">
            Hủy phiếu
          </button>
          <button v-if="r.status === 'confirmed'" @click="showCancelModal = true"
            class="px-4 py-2 border border-red-300 text-red-600 rounded-lg text-sm font-medium hover:bg-red-50 flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" />
            </svg>
            Hủy & hoàn kho
          </button>
          <button v-if="r.status === 'draft' || r.status === 'cancelled'" @click="showDeleteModal = true"
            class="px-4 py-2 border border-red-400 text-red-600 rounded-lg text-sm font-medium hover:bg-red-50">
            Xóa
          </button>
        </div>
      </div>

      <!-- Delete confirmation modal -->
      <Teleport to="body">
        <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
            <h3 class="text-base font-semibold text-gray-900 mb-2">Xóa phiếu trả hàng mua</h3>
            <p class="text-sm text-gray-600 mb-5">
              Bạn có chắc muốn <strong class="text-red-600">xóa vĩnh viễn</strong> phiếu
              <strong>{{ r.code }}</strong>? Thao tác này không thể hoàn tác.
            </p>
            <div class="flex justify-end gap-2">
              <button @click="showDeleteModal = false"
                class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</button>
              <button @click="doDelete"
                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium">Xóa phiếu</button>
            </div>
          </div>
        </div>
      </Teleport>

      <!-- Cancel confirmation modal -->
      <Teleport to="body">
        <div v-if="showCancelModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
            <div class="flex items-start gap-3 mb-4">
              <div class="flex-shrink-0 w-10 h-10 rounded-full bg-red-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
              </div>
              <div>
                <h3 class="text-base font-semibold text-gray-900">Hủy phiếu trả hàng</h3>
                <p class="mt-1 text-sm text-gray-600">
                  <template v-if="r.status === 'confirmed'">
                    Thao tác này sẽ <strong>hoàn trả tồn kho</strong> về kho và khôi phục serial về trạng thái trong kho.
                    Không thể hoàn tác.
                  </template>
                  <template v-else>
                    Hủy phiếu nháp <strong>{{ r.code }}</strong>. Không thể hoàn tác.
                  </template>
                </p>
              </div>
            </div>
            <div class="flex justify-end gap-2 mt-5">
              <button @click="showCancelModal = false"
                class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
                Không hủy
              </button>
              <button @click="doCancel"
                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium">
                Xác nhận hủy
              </button>
            </div>
          </div>
        </div>
      </Teleport>

      <!-- Header info -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-base font-semibold text-gray-800 mb-4">Thông tin phiếu trả</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-y-4 gap-x-8 text-sm">
          <div>
            <span class="text-gray-500">Đơn mua hàng</span>
            <p class="font-medium text-gray-900 mt-0.5">
              <Link :href="route('purchasing.purchase-orders.show', r.po_id)"
                class="text-primary-600 hover:underline font-mono">{{ r.po_code }}</Link>
            </p>
          </div>
          <div>
            <span class="text-gray-500">Nhà cung cấp</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ r.supplier }}</p>
          </div>
          <div>
            <span class="text-gray-500">Kho xuất</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ r.warehouse }}</p>
          </div>
          <div>
            <span class="text-gray-500">Ngày trả</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ r.return_date }}</p>
          </div>
          <div>
            <span class="text-gray-500">Người tạo</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ r.creator }}</p>
          </div>
          <div v-if="r.reason">
            <span class="text-gray-500">Lý do trả</span>
            <p class="font-medium text-gray-900 mt-0.5 whitespace-pre-line">{{ r.reason }}</p>
          </div>
          <div v-if="r.notes" class="sm:col-span-2">
            <span class="text-gray-500">Ghi chú</span>
            <p class="font-medium text-gray-900 mt-0.5 whitespace-pre-line">{{ r.notes }}</p>
          </div>
        </div>
      </div>

      <!-- Items table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <div class="px-5 py-4 border-b border-gray-200">
          <h2 class="text-base font-semibold text-gray-800">Chi tiết hàng hóa trả</h2>
        </div>
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã SP</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tên sản phẩm</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">ĐVT</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">SL</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Đơn giá</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Thành tiền</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <template v-for="item in r.items" :key="item.id">
              <tr class="hover:bg-gray-50">
                <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ item.product_code }}</td>
                <td class="px-5 py-3 font-medium text-gray-900">{{ item.product_name }}</td>
                <td class="px-5 py-3 text-gray-600">{{ item.unit }}</td>
                <td class="px-5 py-3 text-right text-gray-800">{{ item.quantity.toLocaleString('vi-VN') }}</td>
                <td class="px-5 py-3 text-right text-gray-600">{{ item.unit_price ? formatVnd(item.unit_price) : '—' }}</td>
                <td class="px-5 py-3 text-right font-medium text-gray-900">{{ item.total ? formatVnd(item.total) : '—' }}</td>
              </tr>
              <tr v-if="item.serials?.length" class="bg-blue-50">
                <td colspan="6" class="px-5 py-2">
                  <span class="text-xs font-medium text-blue-700 mr-2">Serials:</span>
                  <span v-for="s in item.serials" :key="s.serial_number"
                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-white border border-blue-200 mr-1 mb-1 font-mono text-xs text-gray-800">
                    {{ s.serial_number }}
                    <span :class="`inline-block px-1 rounded text-xs font-medium bg-${s.status_color}-100 text-${s.status_color}-700`">
                      {{ s.status_label }}
                    </span>
                  </span>
                </td>
              </tr>
            </template>
            <tr v-if="!r.items?.length">
              <td colspan="6" class="px-5 py-10 text-center text-gray-400">Không có hàng hóa</td>
            </tr>
          </tbody>
          <tfoot v-if="grandTotal !== null" class="bg-gray-50 border-t border-gray-200">
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

const props = defineProps({ return: Object });
const r = computed(() => props.return);

const { formatVnd } = useCurrency();
const showCancelModal = ref(false);
const showDeleteModal = ref(false);

const grandTotal = computed(() => {
  const items = r.value.items ?? [];
  if (items.every(i => i.total === null)) return null;
  return items.reduce((sum, i) => sum + (i.total ?? 0), 0);
});

const confirmReturn = () => {
  router.post(route('purchasing.purchase-returns.confirm', r.value.id));
};

const doCancel = () => {
  showCancelModal.value = false;
  router.post(route('purchasing.purchase-returns.cancel', r.value.id));
};

const doDelete = () => {
  showDeleteModal.value = false;
  router.delete(route('purchasing.purchase-returns.destroy', r.value.id));
};
</script>
