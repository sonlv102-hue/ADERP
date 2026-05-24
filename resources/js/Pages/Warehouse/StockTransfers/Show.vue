<template>
  <AppLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <Link :href="route('warehouse.stock-transfers.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <h1 class="text-2xl font-bold text-gray-900">{{ transfer.code }}</h1>
          <StatusBadge :color="transfer.status_color">{{ transfer.status_label }}</StatusBadge>
        </div>

        <div class="flex items-center gap-2">
          <Link
            v-if="transfer.status === 'draft' && can('stock-transfers.edit')"
            :href="route('warehouse.stock-transfers.edit', transfer.id)"
            class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50"
          >Chỉnh sửa</Link>

          <button
            v-if="transfer.status === 'draft' && can('stock-transfers.create')"
            @click="confirmTransfer"
            class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium"
          >Xác nhận</button>

          <button
            v-if="transfer.status === 'draft' && can('stock-transfers.edit')"
            @click="showCancelModal = true"
            class="px-4 py-2 border border-red-300 text-red-600 rounded-lg text-sm font-medium hover:bg-red-50"
          >Hủy phiếu</button>

          <button
            v-if="transfer.status === 'confirmed' && can('stock-transfers.edit')"
            @click="showReversalModal = true"
            class="px-4 py-2 border border-red-300 text-red-600 rounded-lg text-sm font-medium hover:bg-red-50 flex items-center gap-1.5"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" />
            </svg>
            Hủy & hoàn kho
          </button>

          <button
            v-if="transfer.status !== 'confirmed' && can('stock-transfers.delete')"
            @click="showDeleteModal = true"
            class="px-4 py-2 border border-gray-300 text-gray-600 rounded-lg text-sm font-medium hover:bg-gray-50"
          >Xóa phiếu</button>
        </div>
      </div>

      <!-- Cancel draft modal -->
      <Teleport to="body">
        <div v-if="showCancelModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div class="bg-white rounded-xl shadow-xl w-full max-w-sm mx-4 p-6">
            <h3 class="text-base font-semibold text-gray-900 mb-2">Hủy phiếu chuyển kho</h3>
            <p class="text-sm text-gray-600 mb-5">Phiếu <strong>{{ transfer.code }}</strong> sẽ bị hủy. Thao tác này không thể hoàn tác.</p>
            <div class="flex justify-end gap-2">
              <button @click="showCancelModal = false"
                class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Không</button>
              <button @click="cancelTransfer"
                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium">Hủy phiếu</button>
            </div>
          </div>
        </div>
      </Teleport>

      <!-- Cancel confirmed (reversal) modal -->
      <Teleport to="body">
        <div v-if="showReversalModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
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
                  Thao tác này sẽ <strong>đảo ngược tồn kho</strong> trên cả hai kho và chuyển serial về kho nguồn.
                  Không thể hoàn tác.
                </p>
              </div>
            </div>
            <div class="flex justify-end gap-2 mt-5">
              <button @click="showReversalModal = false"
                class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Không hủy</button>
              <button @click="cancelTransfer"
                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium">Xác nhận hủy & hoàn kho</button>
            </div>
          </div>
        </div>
      </Teleport>

      <!-- Delete modal -->
      <Teleport to="body">
        <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div class="bg-white rounded-xl shadow-xl w-full max-w-sm mx-4 p-6">
            <h3 class="text-base font-semibold text-gray-900 mb-2">Xóa phiếu chuyển kho</h3>
            <p class="text-sm text-gray-600 mb-5">
              Xóa vĩnh viễn phiếu <strong>{{ transfer.code }}</strong>. Thao tác này <strong>không thể hoàn tác</strong>.
            </p>
            <div class="flex justify-end gap-2">
              <button @click="showDeleteModal = false"
                class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</button>
              <button @click="deleteTransfer"
                class="px-4 py-2 bg-gray-800 hover:bg-gray-900 text-white rounded-lg text-sm font-medium">Xóa vĩnh viễn</button>
            </div>
          </div>
        </div>
      </Teleport>

      <!-- Transfer info -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-base font-semibold text-gray-800 mb-4">Thông tin phiếu</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-y-4 gap-x-8 text-sm">
          <div>
            <span class="text-gray-500">Ngày chuyển</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ transfer.transfer_date }}</p>
          </div>
          <div>
            <span class="text-gray-500">Từ kho</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ transfer.from_warehouse }}</p>
          </div>
          <div>
            <span class="text-gray-500">Đến kho</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ transfer.to_warehouse }}</p>
          </div>
          <div>
            <span class="text-gray-500">Người tạo</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ transfer.creator }}</p>
          </div>
          <div class="sm:col-span-2">
            <span class="text-gray-500">Ghi chú</span>
            <p class="font-medium text-gray-900 mt-0.5 whitespace-pre-line">{{ transfer.notes || '—' }}</p>
          </div>
        </div>
      </div>

      <!-- Items table -->
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
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <template v-for="item in transfer.items" :key="item.id">
              <tr class="hover:bg-gray-50">
                <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ item.product_code }}</td>
                <td class="px-5 py-3 font-medium text-gray-900">{{ item.product_name }}</td>
                <td class="px-5 py-3 text-gray-600">{{ item.unit }}</td>
                <td class="px-5 py-3 text-right text-gray-700">{{ item.quantity.toLocaleString('vi-VN') }}</td>
              </tr>
              <tr v-if="item.serials?.length" class="bg-blue-50">
                <td colspan="4" class="px-5 py-2">
                  <span class="text-xs font-medium text-blue-700 mr-2">Serials:</span>
                  <span
                    v-for="s in item.serials" :key="s.serial_number"
                    class="inline-block px-2 py-0.5 rounded bg-white border border-blue-200 mr-1 mb-1 font-mono text-xs text-gray-800"
                  >{{ s.serial_number }}</span>
                </td>
              </tr>
            </template>
            <tr v-if="!transfer.items?.length">
              <td colspan="4" class="px-5 py-10 text-center text-gray-400">Không có hàng hóa</td>
            </tr>
          </tbody>
        </table>
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

const props = defineProps({ transfer: Object });

const { hasPermission } = usePermission();
const can = hasPermission;

const showCancelModal = ref(false);
const showReversalModal = ref(false);
const showDeleteModal = ref(false);

const confirmTransfer = () => {
  router.post(route('warehouse.stock-transfers.confirm', props.transfer.id));
};

const cancelTransfer = () => {
  showCancelModal.value = false;
  showReversalModal.value = false;
  router.post(route('warehouse.stock-transfers.cancel', props.transfer.id));
};

const deleteTransfer = () => {
  showDeleteModal.value = false;
  router.delete(route('warehouse.stock-transfers.destroy', props.transfer.id));
};
</script>
