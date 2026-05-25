<template>
  <AppLayout>
    <div class="space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">{{ count.code }}</h1>
          <p class="text-sm text-gray-500 mt-0.5">{{ count.warehouse }} · {{ count.count_date }}</p>
        </div>
        <div class="flex gap-2">
          <Link :href="route('warehouse.inventory-counts.index')"
            class="inline-flex items-center gap-2 border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-50">
            ← Danh sách
          </Link>
        </div>
      </div>

      <!-- Info card -->
      <div class="bg-white rounded-xl border border-gray-200 p-5 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
        <div>
          <p class="text-gray-500 text-xs mb-0.5">Trạng thái</p>
          <StatusBadge :color="count.status_color">{{ count.status_label }}</StatusBadge>
        </div>
        <div>
          <p class="text-gray-500 text-xs mb-0.5">Người kiểm</p>
          <p class="font-medium text-gray-800">{{ count.counted_by }}</p>
        </div>
        <div>
          <p class="text-gray-500 text-xs mb-0.5">Tổng SP</p>
          <p class="font-medium text-gray-800">{{ items.length }}</p>
        </div>
        <div>
          <p class="text-gray-500 text-xs mb-0.5">Chênh lệch</p>
          <p class="font-medium" :class="totalDiff !== 0 ? 'text-red-600' : 'text-green-700'">
            {{ totalDiff > 0 ? '+' : '' }}{{ totalDiff }}
          </p>
        </div>
        <div v-if="count.notes" class="col-span-2 md:col-span-4">
          <p class="text-gray-500 text-xs mb-0.5">Ghi chú</p>
          <p class="text-gray-700">{{ count.notes }}</p>
        </div>
      </div>

      <!-- Action buttons (draft only) -->
      <div v-if="count.status === 'draft'" class="flex gap-2 flex-wrap">
        <button @click="saveAll" :disabled="saving"
          class="px-4 py-2 text-sm rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-medium disabled:opacity-60">
          {{ saving ? 'Đang lưu...' : 'Lưu số lượng đếm' }}
        </button>
        <button @click="confirmCount"
          class="px-4 py-2 text-sm rounded-lg bg-green-600 hover:bg-green-700 text-white font-medium">
          Xác nhận & Điều chỉnh tồn kho
        </button>
        <button @click="cancelCount"
          class="px-4 py-2 text-sm rounded-lg border border-red-300 text-red-600 hover:bg-red-50 font-medium">
          Hủy phiếu
        </button>
      </div>

      <!-- Items table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
          <h2 class="font-semibold text-gray-800 text-sm">Danh sách sản phẩm kiểm kê</h2>
          <span v-if="count.status === 'draft'" class="text-xs text-gray-400">
            Nhập số lượng thực đếm cho từng sản phẩm
          </span>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Mã SP</th>
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Tên sản phẩm</th>
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">ĐVT</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-600 text-xs">Tồn hệ thống</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-600 text-xs">Thực đếm</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-600 text-xs">Chênh lệch</th>
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Ghi chú</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="(item, idx) in localItems" :key="item.id"
                :class="{
                  'bg-red-50': item.difference !== null && item.difference < 0,
                  'bg-blue-50': item.difference !== null && item.difference > 0,
                }">
                <td class="px-4 py-2 font-mono text-xs text-gray-600">{{ item.product_code }}</td>
                <td class="px-4 py-2 font-medium text-gray-800 text-xs">{{ item.product_name }}</td>
                <td class="px-4 py-2 text-gray-500 text-xs">{{ item.unit || '—' }}</td>
                <td class="px-4 py-2 text-right text-gray-700 text-xs font-medium">{{ item.system_quantity }}</td>
                <td class="px-4 py-2 text-right">
                  <input v-if="count.status === 'draft'"
                    v-model.number="localItems[idx].counted_quantity"
                    type="number" min="0" step="1"
                    @input="recalcDiff(idx)"
                    class="w-20 text-right border border-gray-300 rounded px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-primary-500" />
                  <span v-else class="text-xs">{{ item.counted_quantity ?? '—' }}</span>
                </td>
                <td class="px-4 py-2 text-right text-xs font-semibold"
                  :class="{
                    'text-red-600': item.difference !== null && item.difference < 0,
                    'text-blue-600': item.difference !== null && item.difference > 0,
                    'text-gray-400': item.difference === null || item.difference === 0,
                  }">
                  <span v-if="item.difference !== null">
                    {{ item.difference > 0 ? '+' : '' }}{{ item.difference }}
                  </span>
                  <span v-else>—</span>
                </td>
                <td class="px-4 py-2">
                  <input v-if="count.status === 'draft'"
                    v-model="localItems[idx].notes"
                    type="text"
                    class="w-full border border-gray-300 rounded px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-primary-500"
                    placeholder="Ghi chú..." />
                  <span v-else class="text-xs text-gray-500">{{ item.notes || '—' }}</span>
                </td>
              </tr>
              <tr v-if="!localItems.length">
                <td colspan="7" class="px-4 py-10 text-center text-gray-400">
                  Không có sản phẩm nào đang có tồn kho tại kho này
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';

const props = defineProps({
  count: Object,
  items: Array,
});

const saving = ref(false);

// Deep clone items for local editing
const localItems = ref(props.items.map(i => ({ ...i })));

function recalcDiff(idx) {
  const item = localItems.value[idx];
  item.difference = item.counted_quantity !== null && item.counted_quantity !== ''
    ? item.counted_quantity - item.system_quantity
    : null;
}

const totalDiff = computed(() =>
  localItems.value.reduce((sum, i) => sum + (i.difference ?? 0), 0)
);

function saveAll() {
  saving.value = true;
  router.post(
    route('warehouse.inventory-counts.save-items', props.count.id),
    { items: localItems.value.map(i => ({ id: i.id, counted_quantity: i.counted_quantity, notes: i.notes })) },
    { onFinish: () => { saving.value = false; } }
  );
}

function confirmCount() {
  if (!confirm('Xác nhận kiểm kê? Hệ thống sẽ lưu và tạo bút toán điều chỉnh tồn kho cho các sản phẩm có chênh lệch.')) return;
  router.post(route('warehouse.inventory-counts.confirm', props.count.id), {
    items: localItems.value.map(i => ({ id: i.id, counted_quantity: i.counted_quantity, notes: i.notes })),
  });
}

function cancelCount() {
  if (!confirm('Hủy phiếu kiểm kê này?')) return;
  router.post(route('warehouse.inventory-counts.cancel', props.count.id));
}
</script>
