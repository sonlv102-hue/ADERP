<template>
  <AppLayout>
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold text-gray-900">Công cụ dụng cụ (CCDC)</h1>
      <div class="flex gap-2">
        <Link v-if="can('ccdc.manage')"
          :href="route('accounting.small-tools.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          + Tạo CCDC
        </Link>
        <Link v-if="can('ccdc.manage')"
          :href="route('accounting.small-tools.receipts.create')"
          class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium">
          Phiếu nhập kho
        </Link>
      </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-xl border border-gray-200 p-4 mb-5 flex flex-wrap gap-3">
      <input v-model="search" @keyup.enter="applyFilters" type="text"
        placeholder="Tìm mã, tên CCDC..."
        class="px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500 w-56" />

      <select v-model="filterStatus" @change="applyFilters"
        class="px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500">
        <option value="">Tất cả trạng thái</option>
        <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
      </select>

      <select v-model="filterCategory" @change="applyFilters"
        class="px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500">
        <option value="">Tất cả nhóm</option>
        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>

      <input v-model="filterDept" @keyup.enter="applyFilters" type="text"
        placeholder="Bộ phận..."
        class="px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500 w-36" />

      <button @click="applyFilters"
        class="bg-primary-600 text-white px-4 py-2 rounded-lg text-sm font-medium">Lọc</button>
      <button @click="clearFilters"
        class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm">Xóa lọc</button>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="px-4 py-3 text-left font-semibold text-gray-700">Mã CCDC</th>
            <th class="px-4 py-3 text-left font-semibold text-gray-700">Tên CCDC</th>
            <th class="px-4 py-3 text-left font-semibold text-gray-700">Nhóm</th>
            <th class="px-4 py-3 text-left font-semibold text-gray-700">Bộ phận</th>
            <th class="px-4 py-3 text-right font-semibold text-gray-700">Nguyên giá</th>
            <th class="px-4 py-3 text-right font-semibold text-gray-700">Đã phân bổ</th>
            <th class="px-4 py-3 text-right font-semibold text-gray-700">Còn lại</th>
            <th class="px-4 py-3 text-center font-semibold text-gray-700">Trạng thái</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="!tools.data.length">
            <td colspan="9" class="px-4 py-8 text-center text-gray-400">Chưa có CCDC nào.</td>
          </tr>
          <tr v-for="t in tools.data" :key="t.id" class="hover:bg-gray-50">
            <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ t.code }}</td>
            <td class="px-4 py-3 font-medium text-gray-900">{{ t.name }}</td>
            <td class="px-4 py-3 text-gray-600">{{ t.category_name || '—' }}</td>
            <td class="px-4 py-3 text-gray-600">{{ t.department || '—' }}</td>
            <td class="px-4 py-3 text-right font-mono text-gray-800">{{ formatVnd(t.original_cost) }}</td>
            <td class="px-4 py-3 text-right font-mono text-gray-600">{{ formatVnd(t.total_allocated) }}</td>
            <td class="px-4 py-3 text-right font-mono"
              :class="t.total_remaining > 0 ? 'text-orange-600 font-semibold' : 'text-gray-400'">
              {{ formatVnd(t.total_remaining) }}
            </td>
            <td class="px-4 py-3 text-center">
              <span class="px-2 py-0.5 rounded-full text-xs font-medium"
                :class="`bg-${t.status_color}-100 text-${t.status_color}-700`">
                {{ t.status_label }}
              </span>
            </td>
            <td class="px-4 py-3 text-right">
              <Link :href="route('accounting.small-tools.show', t.id)"
                class="text-primary-600 hover:text-primary-800 text-xs font-medium">Xem</Link>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="tools.last_page > 1" class="flex justify-end mt-4 gap-1">
      <Link v-for="link in tools.links" :key="link.label"
        :href="link.url || '#'"
        :class="['px-3 py-1 text-sm rounded border', link.active ? 'bg-primary-600 text-white border-primary-600' : 'border-gray-300 text-gray-600 hover:bg-gray-50']"
        v-html="link.label" />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const { hasPermission: can } = usePermission();
const { formatVnd } = useCurrency();

const props = defineProps({
  tools:      Object,
  categories: Array,
  statuses:   Array,
  warehouses: Array,
  filters:    Object,
});

const search         = ref(props.filters.search ?? '');
const filterStatus   = ref(props.filters.status ?? '');
const filterCategory = ref(props.filters.category_id ?? '');
const filterDept     = ref(props.filters.department ?? '');

function applyFilters() {
  router.get(route('accounting.small-tools.index'), {
    search:      search.value,
    status:      filterStatus.value,
    category_id: filterCategory.value,
    department:  filterDept.value,
  }, { preserveState: true });
}

function clearFilters() {
  search.value = filterStatus.value = filterCategory.value = filterDept.value = '';
  applyFilters();
}
</script>
