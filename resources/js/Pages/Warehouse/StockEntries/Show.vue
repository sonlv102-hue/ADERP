<template>
  <AppLayout>
    <div class="space-y-6">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <Link :href="route('warehouse.stock-entries.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <h1 class="text-2xl font-bold text-gray-900">{{ entry.code }}</h1>
          <StatusBadge :color="entry.status_color">{{ entry.status_label }}</StatusBadge>
        </div>
        <div class="flex items-center gap-2">
          <a :href="route('warehouse.stock-entries.pdf', entry.id)" target="_blank"
            class="flex items-center gap-1.5 px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
            </svg>
            In / Xuất PDF
          </a>
          <Link v-if="entry.status === 'draft'" :href="route('warehouse.stock-entries.edit', entry.id)"
            class="flex items-center gap-1.5 px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002 2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
            Sửa
          </Link>
          <button v-if="entry.status === 'draft'" @click="confirmEntry"
            class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Xác nhận
          </button>
          <button v-if="entry.status === 'draft'" @click="cancelEntry"
            class="px-4 py-2 border border-red-300 text-red-600 rounded-lg text-sm font-medium hover:bg-red-50">
            Hủy phiếu
          </button>
          <button v-if="['draft','cancelled'].includes(entry.status)" @click="showDeleteModal = true"
            class="px-4 py-2 border border-gray-300 text-gray-600 rounded-lg text-sm font-medium hover:bg-gray-50 flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
            Xóa phiếu
          </button>
          <button v-if="entry.status === 'confirmed'" @click="showReverseModal = true"
            class="px-4 py-2 border border-red-300 text-red-600 rounded-lg text-sm font-medium hover:bg-red-50 flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" />
            </svg>
            Hủy & hoàn kho
          </button>
        </div>
      </div>

      <!-- Modal xác nhận xóa -->
      <Teleport to="body">
        <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div class="bg-white rounded-xl shadow-xl w-full max-w-sm mx-4 p-6">
            <div class="flex items-start gap-3 mb-4">
              <div class="flex-shrink-0 w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </div>
              <div>
                <h3 class="text-base font-semibold text-gray-900">Xóa phiếu nhập kho</h3>
                <p class="mt-1 text-sm text-gray-600">
                  Xóa vĩnh viễn phiếu <strong>{{ entry.code }}</strong> và toàn bộ dữ liệu liên quan.
                  Thao tác này <strong>không thể hoàn tác</strong>.
                </p>
              </div>
            </div>
            <div class="flex justify-end gap-2 mt-5">
              <button @click="showDeleteModal = false"
                class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
                Hủy
              </button>
              <button @click="deleteEntry"
                class="px-4 py-2 bg-gray-800 hover:bg-gray-900 text-white rounded-lg text-sm font-medium">
                Xóa vĩnh viễn
              </button>
            </div>
          </div>
        </div>
      </Teleport>

      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-base font-semibold text-gray-800 mb-4">Thông tin phiếu nhập</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-y-4 gap-x-8 text-sm">
          <div>
            <span class="text-gray-500">Ngày nhập</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ entry.entry_date }}</p>
          </div>
          <div>
            <span class="text-gray-500">Kho</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ entry.warehouse ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Nhà cung cấp</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ entry.supplier ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Người tạo</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ entry.creator ?? '—' }}</p>
          </div>
          <div class="sm:col-span-2">
            <span class="text-gray-500">Ghi chú</span>
            <p class="font-medium text-gray-900 mt-0.5 whitespace-pre-line">{{ entry.notes ?? '—' }}</p>
          </div>
        </div>
      </div>

      <!-- Modal xác nhận hủy phiếu đã xác nhận -->
      <Teleport to="body">
        <div v-if="showReverseModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
            <div class="flex items-start gap-3 mb-4">
              <div class="flex-shrink-0 w-10 h-10 rounded-full bg-red-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
              </div>
              <div>
                <h3 class="text-base font-semibold text-gray-900">Hủy phiếu đã xác nhận</h3>
                <p class="mt-1 text-sm text-gray-600">
                  Thao tác này sẽ <strong>đảo ngược tồn kho</strong> và chuyển tất cả serial về trạng thái
                  <strong>"Đã hủy nhập"</strong>. Không thể hoàn tác.
                </p>
                <p class="mt-2 text-sm text-amber-700 bg-amber-50 rounded-lg px-3 py-2">
                  Chỉ thực hiện được khi <strong>toàn bộ serial còn trong kho</strong>
                  (chưa bán, chưa xuất).
                </p>
              </div>
            </div>
            <div class="flex justify-end gap-2 mt-5">
              <button @click="showReverseModal = false"
                class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
                Không hủy
              </button>
              <button @click="cancelConfirmedEntry"
                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium">
                Xác nhận hủy & hoàn kho
              </button>
            </div>
          </div>
        </div>
      </Teleport>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200">
          <h2 class="text-base font-semibold text-gray-800">Chi tiết hàng hóa</h2>
        </div>
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã SP</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tên sản phẩm</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">ĐVT</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">SL</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Đơn giá (gồm VAT)</th>
              <th class="text-center px-3 py-3 font-semibold text-gray-600">% VAT</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Chưa thuế</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Thuế GTGT</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng cộng</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <template v-for="item in entry.items" :key="item.id">
              <tr class="hover:bg-gray-50">
                <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ item.product_code }}</td>
                <td class="px-5 py-3 font-medium text-gray-900">{{ item.product_name }}</td>
                <td class="px-5 py-3 text-gray-600">{{ item.unit }}</td>
                <td class="px-5 py-3 text-right text-gray-600">{{ item.quantity.toLocaleString('vi-VN') }}</td>
                <td class="px-5 py-3 text-right text-gray-600">{{ formatVnd(item.unit_price) }}</td>
                <td class="px-3 py-3 text-center text-gray-600">{{ item.tax_rate }}%</td>
                <td class="px-5 py-3 text-right text-gray-700">{{ formatVnd(item.subtotal_excl) }}</td>
                <td class="px-5 py-3 text-right text-blue-600">{{ formatVnd(item.tax_amount) }}</td>
                <td class="px-5 py-3 text-right font-medium text-gray-900">{{ formatVnd(item.total) }}</td>
              </tr>
              <tr v-if="item.serials?.length" class="bg-blue-50">
                <td colspan="9" class="px-5 py-2">
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
            <tr v-if="!entry.items?.length">
              <td colspan="9" class="px-5 py-10 text-center text-gray-400">Không có hàng hóa</td>
            </tr>
          </tbody>
          <tfoot v-if="entry.items?.length" class="bg-gray-50 border-t border-gray-200">
            <tr>
              <td colspan="6" class="px-5 py-3 text-right font-semibold text-gray-700">Tổng cộng:</td>
              <td class="px-5 py-3 text-right font-semibold text-gray-700">{{ formatVnd(totalExcl) }}</td>
              <td class="px-5 py-3 text-right font-semibold text-blue-600">{{ formatVnd(totalTax) }}</td>
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

const props = defineProps({ entry: Object });

const { formatVnd } = useCurrency();
const showReverseModal = ref(false);
const showDeleteModal = ref(false);

const grandTotal = computed(() => (props.entry.items ?? []).reduce((sum, item) => sum + item.total, 0));
const totalExcl  = computed(() => (props.entry.items ?? []).reduce((sum, item) => sum + (item.subtotal_excl ?? 0), 0));
const totalTax   = computed(() => (props.entry.items ?? []).reduce((sum, item) => sum + (item.tax_amount ?? 0), 0));

const confirmEntry = () => {
  router.post(route('warehouse.stock-entries.confirm', props.entry.id));
};

const cancelEntry = () => {
  router.post(route('warehouse.stock-entries.cancel', props.entry.id));
};

const cancelConfirmedEntry = () => {
  showReverseModal.value = false;
  router.post(route('warehouse.stock-entries.cancel', props.entry.id));
};

const deleteEntry = () => {
  showDeleteModal.value = false;
  router.delete(route('warehouse.stock-entries.destroy', props.entry.id));
};
</script>
