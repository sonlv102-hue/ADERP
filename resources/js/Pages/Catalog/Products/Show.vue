<template>
  <AppLayout>
    <div class="space-y-6 max-w-4xl">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <Link :href="route('catalog.products.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <h1 class="text-2xl font-bold text-gray-900">{{ product.code }} — {{ product.name }}</h1>
          <StatusBadge :color="product.is_active ? 'green' : 'gray'">
            {{ product.is_active ? 'Đang bán' : 'Ngừng bán' }}
          </StatusBadge>
        </div>
        <Link v-if="can('products.edit')" :href="route('catalog.products.edit', product.id)"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          Chỉnh sửa
        </Link>
      </div>

      <!-- Thông tin chung -->
      <div class="bg-white rounded-xl border border-gray-200 p-5">
        <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-4">Thông tin chung</h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-5 text-sm">
          <div>
            <span class="text-gray-500">Danh mục</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ product.category ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Đơn vị tính</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ product.unit }}</p>
          </div>
          <div>
            <span class="text-gray-500">Bảo hành</span>
            <p class="font-medium text-gray-900 mt-0.5">
              {{ product.warranty_months > 0 ? product.warranty_months + ' tháng' : '—' }}
            </p>
          </div>
          <div>
            <span class="text-gray-500">Quản lý serial</span>
            <p class="font-medium mt-0.5" :class="product.has_serial ? 'text-green-700' : 'text-gray-400'">
              {{ product.has_serial ? 'Có' : 'Không' }}
            </p>
          </div>
          <div v-if="product.description" class="col-span-2 sm:col-span-4">
            <span class="text-gray-500">Mô tả</span>
            <p class="text-gray-700 mt-0.5">{{ product.description }}</p>
          </div>
        </div>
      </div>

      <!-- Tồn kho -->
      <div class="bg-white rounded-xl border border-gray-200 p-5">
        <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-4">Tồn kho</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-5 text-sm">
          <div>
            <span class="text-gray-500">Tồn kho hiện tại</span>
            <p class="text-2xl font-bold mt-1" :class="stockWarning ? 'text-red-600' : 'text-gray-900'">
              {{ product.stock }} <span class="text-sm font-normal text-gray-500">{{ product.unit }}</span>
            </p>
          </div>
          <div>
            <span class="text-gray-500">Tồn kho tối thiểu</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ product.min_stock }} {{ product.unit }}</p>
          </div>
          <div v-if="stockWarning" class="flex items-center gap-2 text-red-600">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
            </svg>
            <span class="text-sm font-medium">Dưới mức tối thiểu</span>
          </div>
        </div>
      </div>

      <!-- Giá -->
      <div class="bg-white rounded-xl border border-gray-200 p-5">
        <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-4">Giá</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-5 text-sm">
          <div>
            <span class="text-gray-500">Giá nhập (đã VAT)</span>
            <p class="font-semibold text-gray-900 mt-0.5">{{ formatVnd(product.cost_price) }}</p>
          </div>
          <div>
            <span class="text-gray-500">Chi phí kinh doanh</span>
            <p class="font-semibold text-gray-900 mt-0.5">{{ formatVnd(product.business_cost) }}</p>
          </div>
          <div>
            <span class="text-gray-500">VAT đầu vào</span>
            <p class="font-semibold text-gray-900 mt-0.5">{{ product.vat_percent }}%</p>
          </div>
          <div>
            <span class="text-gray-500">Giá vốn</span>
            <p class="font-semibold text-gray-900 mt-0.5">{{ formatVnd(product.total_cost) }}</p>
          </div>
          <div>
            <span class="text-gray-500">Giá bán</span>
            <p class="font-semibold text-primary-700 mt-0.5 text-base">{{ formatVnd(product.sell_price) }}</p>
          </div>
          <div>
            <span class="text-gray-500">Biên lợi nhuận</span>
            <p class="font-semibold text-green-700 mt-0.5">{{ marginLabel }}</p>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { useCurrency } from '@/composables/useCurrency';
import { usePermission } from '@/composables/usePermission';

const props = defineProps({ product: Object });

const { formatVnd } = useCurrency();
const { hasPermission } = usePermission();
const can = hasPermission;

const stockWarning = computed(() =>
  props.product.min_stock > 0 && props.product.stock <= props.product.min_stock
);

const marginLabel = computed(() => {
  const tc = Number(props.product.total_cost ?? 0);
  const sp = Number(props.product.sell_price ?? 0);
  if (tc <= 0) return '—';
  const pct = Math.round(((sp / tc) - 1) * 100 * 100) / 100;
  return pct + '%';
});
</script>
