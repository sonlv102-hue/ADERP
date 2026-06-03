<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">Sản phẩm</h1>
        <div class="flex gap-2">
          <button @click="showImport = true"
            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
            </svg>
            Import Excel
          </button>
          <Link :href="route('catalog.products.create')"
            class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Thêm sản phẩm
          </Link>
        </div>
      </div>

      <!-- Filters -->
      <div class="flex flex-wrap gap-3">
        <div class="relative">
          <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0" />
          </svg>
          <input v-model="search" @input="applyFilters" placeholder="Tìm mã, tên sản phẩm..."
            class="pl-9 pr-3 py-2 border border-gray-300 rounded-lg text-sm w-64 focus:outline-none focus:ring-2 focus:ring-primary-500" />
        </div>
        <select v-model="categoryId" @change="applyFilters"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 bg-white">
          <option value="">Tất cả danh mục</option>
          <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <select v-model="status" @change="applyFilters"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 bg-white">
          <option value="">Tất cả trạng thái</option>
          <option value="active">Hoạt động</option>
          <option value="inactive">Dừng</option>
        </select>
        <button v-if="hasFilters" @click="clearFilters"
          class="px-3 py-2 text-sm text-gray-500 hover:text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50">
          Xóa bộ lọc
        </button>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã SP</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tên</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Đơn vị</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Giá bán</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Danh mục</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Serial</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="product in products.data" :key="product.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ product.code }}</td>
              <td class="px-5 py-3 font-medium text-gray-900">{{ product.name }}</td>
              <td class="px-5 py-3 text-gray-600">{{ product.unit }}</td>
              <td class="px-5 py-3 text-gray-600">{{ formatVnd(product.sell_price) }}</td>
              <td class="px-5 py-3 text-gray-600">{{ product.category?.name ?? '—' }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="product.has_serial ? 'blue' : 'gray'">
                  {{ product.has_serial ? 'Có serial' : 'Không' }}
                </StatusBadge>
              </td>
              <td class="px-5 py-3">
                <StatusBadge :color="product.is_active ? 'green' : 'red'">
                  {{ product.is_active ? 'Hoạt động' : 'Dừng' }}
                </StatusBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <Link v-if="product.has_serial" :href="route('catalog.products.show', product.id)"
                  class="text-blue-600 hover:text-blue-800 font-medium mr-3">Serials</Link>
                <Link :href="route('catalog.products.edit', product.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium mr-3">Sửa</Link>
                <button @click="confirmDelete(product)"
                  class="text-red-500 hover:text-red-700 font-medium">Xóa</button>
              </td>
            </tr>
            <tr v-if="!products.data?.length">
              <td colspan="8" class="px-5 py-10 text-center text-gray-400">Không tìm thấy sản phẩm nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="products.links" :meta="products.meta" />
    </div>

    <Modal :show="deleteTarget !== null" @close="deleteTarget = null">
      <template #title>Xác nhận xóa</template>
      <p class="text-gray-600">Bạn có chắc muốn xóa sản phẩm <strong>{{ deleteTarget?.name }}</strong> không?</p>
      <template #footer>
        <button @click="deleteTarget = null" class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Hủy</button>
        <button @click="doDelete" class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">Xóa</button>
      </template>
    </Modal>

    <Modal :show="showImport" @close="showImport = false">
      <template #title>Import sản phẩm từ Excel</template>
      <div class="space-y-4">
        <a :href="route('catalog.products.import-template')"
          class="inline-flex items-center gap-2 text-sm text-primary-600 hover:text-primary-800">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
          Tải file mẫu (template)
        </a>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Chọn file Excel (.xlsx, .xls, .csv)</label>
          <input ref="importFileInput" type="file" accept=".xlsx,.xls,.csv"
            class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100" />
        </div>
      </div>
      <template #footer>
        <button @click="showImport = false" class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Hủy</button>
        <button @click="doImport" class="px-4 py-2 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700">Import</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import Modal from '@/Components/Shared/Modal.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ products: Object, categories: Array, filters: Object });

const { formatVnd } = useCurrency();

const search     = ref(props.filters?.search ?? '');
const categoryId = ref(props.filters?.category_id ?? '');
const status     = ref(props.filters?.status ?? '');

const hasFilters = computed(() => search.value || categoryId.value || status.value);

let searchTimer = null;
function applyFilters() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    router.get(route('catalog.products.index'),
      { search: search.value, category_id: categoryId.value, status: status.value },
      { preserveState: true, replace: true }
    );
  }, 300);
}

function clearFilters() {
  search.value = '';
  categoryId.value = '';
  status.value = '';
  router.get(route('catalog.products.index'), {}, { preserveState: true, replace: true });
}

const deleteTarget    = ref(null);
const showImport      = ref(false);
const importFileInput = ref(null);

const confirmDelete = (product) => { deleteTarget.value = product; };

const doDelete = () => {
  router.delete(route('catalog.products.destroy', deleteTarget.value.id), {
    onSuccess: () => { deleteTarget.value = null; },
  });
};

const doImport = () => {
  const file = importFileInput.value?.files?.[0];
  if (!file) return;
  const formData = new FormData();
  formData.append('file', file);
  router.post(route('catalog.products.import'), formData, {
    forceFormData: true,
    onSuccess: () => { showImport.value = false; },
  });
};
</script>
