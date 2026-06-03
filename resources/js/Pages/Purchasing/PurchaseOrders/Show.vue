<template>
  <AppLayout>
    <div class="max-w-5xl space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <Link :href="route('purchasing.purchase-orders.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <h1 class="text-2xl font-bold text-gray-900">{{ order.code }}</h1>
          <StatusBadge :color="order.status_color">{{ order.status_label }}</StatusBadge>
        </div>

        <!-- Actions -->
        <div class="flex gap-2">
          <template v-if="order.status === 'draft'">
            <Link :href="route('purchasing.purchase-orders.edit', order.id)"
              class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium">
              Sửa
            </Link>
            <button @click="doAction('send')" :disabled="busy"
              class="bg-blue-600 hover:bg-blue-700 disabled:opacity-60 text-white px-4 py-2 rounded-lg text-sm font-medium">
              Gửi NCC
            </button>
            <button @click="doAction('cancel')" :disabled="busy"
              class="border border-red-300 text-red-600 hover:bg-red-50 disabled:opacity-60 px-4 py-2 rounded-lg text-sm font-medium">
              Hủy đơn
            </button>
          </template>
          <template v-else-if="order.status === 'sent' || order.status === 'partial_received'">
            <button @click="doAction('receive')" :disabled="busy"
              class="bg-green-600 hover:bg-green-700 disabled:opacity-60 text-white px-4 py-2 rounded-lg text-sm font-medium">
              {{ order.status === 'partial_received' ? 'Nhận tiếp hàng' : 'Nhận hàng' }}
            </button>
            <button v-if="order.status === 'sent'" @click="doAction('cancel')" :disabled="busy"
              class="border border-red-300 text-red-600 hover:bg-red-50 disabled:opacity-60 px-4 py-2 rounded-lg text-sm font-medium">
              Hủy đơn
            </button>
          </template>
          <!-- Nút xóa: chỉ Admin + đơn đã hủy -->
          <button
            v-if="hasRole('admin') && order.status === 'cancelled'"
            @click="showDeleteModal = true"
            :disabled="busy"
            class="border border-red-400 text-red-600 hover:bg-red-50 disabled:opacity-60 px-4 py-2 rounded-lg text-sm font-medium">
            Xóa đơn
          </button>
        </div>
      </div>

      <!-- Thông tin chung -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-5 text-sm">
          <div>
            <p class="text-gray-500 mb-1">Nhà cung cấp</p>
            <p class="font-medium text-gray-900">{{ order.supplier }}</p>
          </div>
          <div>
            <p class="text-gray-500 mb-1">Kho nhận</p>
            <p class="font-medium text-gray-900">{{ order.warehouse }}</p>
          </div>
          <div>
            <p class="text-gray-500 mb-1">Ngày đặt</p>
            <p class="font-medium text-gray-900">{{ order.order_date }}</p>
          </div>
          <div>
            <p class="text-gray-500 mb-1">Dự kiến nhận</p>
            <p class="font-medium text-gray-900">{{ order.expected_date ?? '—' }}</p>
          </div>
          <div>
            <p class="text-gray-500 mb-1">Người tạo</p>
            <p class="font-medium text-gray-900">{{ order.creator }}</p>
          </div>
          <div>
            <p class="text-gray-500 mb-1">Loại hóa đơn đầu vào</p>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
              :class="{
                'bg-green-100 text-green-700': order.invoice_type === 'vat',
                'bg-amber-100 text-amber-700': order.invoice_type === 'retail',
                'bg-gray-100 text-gray-600':   order.invoice_type === 'no_invoice',
              }">
              {{ order.invoice_type_label }}
            </span>
          </div>
          <div v-if="order.linked_order">
            <p class="text-gray-500 mb-1">Đơn hàng bán liên kết</p>
            <Link :href="route('sales.orders.show', order.linked_order.id)"
              class="inline-flex items-center gap-1.5 text-blue-700 font-medium hover:underline">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
              </svg>
              {{ order.linked_order.code }} — {{ order.linked_order.customer_name }}
            </Link>
          </div>
          <div v-if="order.project">
            <p class="text-gray-500 mb-1">Dự án liên kết</p>
            <Link :href="route('projects.projects.show', order.project.id)"
              class="inline-flex items-center gap-1.5 text-purple-700 font-medium hover:underline">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
              </svg>
              {{ order.project.code }} — {{ order.project.name }}
            </Link>
          </div>
          <div v-if="order.notes" class="col-span-3">
            <p class="text-gray-500 mb-1">Ghi chú</p>
            <p class="text-gray-800">{{ order.notes }}</p>
          </div>
        </div>
      </div>

      <!-- Tabs -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="border-b border-gray-200">
          <nav class="flex gap-0">
            <button v-for="tab in tabs" :key="tab.key" @click="activeTab = tab.key"
              :class="activeTab === tab.key ? 'border-b-2 border-primary-600 text-primary-600' : 'text-gray-500 hover:text-gray-700'"
              class="px-5 py-3 text-sm font-medium transition-colors">
              {{ tab.label }}
              <span v-if="tab.count !== undefined"
                class="ml-1.5 px-1.5 py-0.5 rounded-full text-xs"
                :class="activeTab === tab.key ? 'bg-primary-100 text-primary-700' : 'bg-gray-100 text-gray-600'">
                {{ tab.count }}
              </span>
            </button>
          </nav>
        </div>

        <!-- Tab: Sản phẩm -->
        <div v-if="activeTab === 'items'">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã SP</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Tên sản phẩm</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Đơn vị</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">SL</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Đơn giá</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Thành tiền</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="item in order.items" :key="item.id" class="hover:bg-gray-50">
                <td class="px-5 py-3 font-mono text-xs text-gray-600">{{ item.product_code }}</td>
                <td class="px-5 py-3 text-gray-800">{{ item.product_name }}</td>
                <td class="px-5 py-3 text-gray-600">{{ item.unit }}</td>
                <td class="px-5 py-3 text-right text-gray-700">{{ item.quantity.toLocaleString('vi-VN') }}</td>
                <td class="px-5 py-3 text-right text-gray-700">{{ formatVnd(item.unit_price) }}</td>
                <td class="px-5 py-3 text-right font-medium text-gray-900">{{ formatVnd(item.total) }}</td>
              </tr>
            </tbody>
            <tfoot class="bg-gray-50 border-t border-gray-200">
              <tr>
                <td colspan="5" class="px-5 py-3 text-right font-semibold text-gray-700">Tổng cộng:</td>
                <td class="px-5 py-3 text-right font-bold text-gray-900 text-base">
                  {{ formatVnd(grandTotal) }}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>

        <!-- Tab: Phiếu nhập kho -->
        <div v-if="activeTab === 'stock'" class="p-5">
          <div v-if="order.stock_entries?.length" class="flex flex-wrap gap-2">
            <Link
              v-for="entry in order.stock_entries"
              :key="entry.id"
              :href="route('warehouse.stock-entries.show', entry.id)"
              class="inline-flex items-center gap-1 px-3 py-1 bg-green-50 text-green-700 border border-green-200 rounded-full text-sm hover:bg-green-100"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4" />
              </svg>
              {{ entry.code }}
            </Link>
          </div>
          <p v-else class="text-gray-400 text-sm text-center py-6">Chưa có phiếu nhập kho</p>
        </div>

        <!-- Tab: Hóa đơn đầu vào -->
        <div v-if="activeTab === 'invoices'">
          <div class="px-5 py-3 border-b border-gray-100 flex justify-end">
            <Link :href="route('purchasing.purchase-invoices.create', { purchase_order_id: order.id })"
              class="bg-primary-600 hover:bg-primary-700 text-white px-3 py-1.5 rounded-lg text-sm font-medium">
              + Thêm hóa đơn
            </Link>
          </div>
          <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Số HĐ NCC</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày HĐ</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Hạn TT</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng tiền</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Còn lại</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
                <th class="px-5 py-3"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="inv in order.purchase_invoices" :key="inv.id" class="hover:bg-gray-50">
                <td class="px-5 py-3 font-mono font-medium text-primary-700">{{ inv.code }}</td>
                <td class="px-5 py-3 text-gray-700">{{ inv.invoice_number ?? '—' }}</td>
                <td class="px-5 py-3 text-gray-600">{{ inv.invoice_date ?? '—' }}</td>
                <td class="px-5 py-3 text-gray-600">{{ inv.due_date ?? '—' }}</td>
                <td class="px-5 py-3 text-right font-medium text-gray-900">{{ formatVnd(inv.total) }}</td>
                <td class="px-5 py-3 text-right" :class="inv.remaining > 0 ? 'text-red-600 font-medium' : 'text-gray-500'">
                  {{ formatVnd(inv.remaining) }}
                </td>
                <td class="px-5 py-3">
                  <StatusBadge :color="inv.status_color">{{ inv.status_label }}</StatusBadge>
                </td>
                <td class="px-5 py-3 text-right">
                  <Link :href="route('purchasing.purchase-invoices.show', inv.id)"
                    class="text-primary-600 hover:text-primary-800 text-xs font-medium">Xem</Link>
                </td>
              </tr>
              <tr v-if="!order.purchase_invoices?.length">
                <td colspan="8" class="px-5 py-8 text-center text-gray-400">Chưa có hóa đơn đầu vào</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Modal xác nhận xóa -->
    <Modal :show="showDeleteModal" @close="showDeleteModal = false">
      <template #title>Xác nhận xóa đơn mua hàng</template>
      <p class="text-gray-600">
        Bạn có chắc muốn <strong class="text-red-600">xóa vĩnh viễn</strong> đơn
        <strong>{{ order.code }}</strong> không? Thao tác này không thể hoàn tác.
      </p>
      <template #footer>
        <button @click="showDeleteModal = false"
          class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Hủy</button>
        <button @click="doDelete" :disabled="busy"
          class="px-4 py-2 text-sm bg-red-600 hover:bg-red-700 disabled:opacity-60 text-white rounded-lg">
          Xóa đơn hàng
        </button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Modal from '@/Components/Shared/Modal.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ order: Object });

const { hasRole } = usePermission();
const { formatVnd } = useCurrency();

const busy = ref(false);
const activeTab = ref('items');
const showDeleteModal = ref(false);

const tabs = computed(() => [
  { key: 'items',    label: 'Sản phẩm',          count: props.order.items?.length },
  { key: 'stock',    label: 'Phiếu nhập kho',     count: props.order.stock_entries?.length },
  { key: 'invoices', label: 'Hóa đơn đầu vào',   count: props.order.purchase_invoices?.length },
]);

const grandTotal = computed(() =>
  props.order.items.reduce((sum, item) => sum + Number(item.total), 0)
);

const actionRoutes = {
  send:    () => route('purchasing.purchase-orders.send',    props.order.id),
  receive: () => route('purchasing.purchase-orders.receive', props.order.id),
  cancel:  () => route('purchasing.purchase-orders.cancel',  props.order.id),
};

const doAction = (action) => {
  if (busy.value) return;
  busy.value = true;
  router.post(actionRoutes[action](), {}, {
    onFinish: () => { busy.value = false; },
  });
};

const doDelete = () => {
  if (busy.value) return;
  busy.value = true;
  router.delete(route('purchasing.purchase-orders.destroy', props.order.id), {
    onFinish: () => { busy.value = false; showDeleteModal.value = false; },
  });
};

</script>
