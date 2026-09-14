<template>
  <div class="space-y-4">
    <div class="flex flex-wrap gap-2 justify-between items-center">
      <p class="text-sm text-gray-500">{{ items.length }} mặt hàng cần mua</p>
      <div class="flex gap-2 flex-wrap">
        <a
          :href="route('purchasing.quote-comparisons.quote-template', comparison.id)"
          class="erp-btn-secondary"
        >Xuất mẫu báo giá</a>
        <button v-if="editable" class="erp-btn-secondary" @click="showImport = true">Import danh sách</button>
      </div>
    </div>

    <!-- Add row -->
    <div v-if="editable" class="grid grid-cols-1 sm:grid-cols-12 gap-2 bg-gray-50 border border-gray-200 rounded-lg p-3">
      <div class="sm:col-span-5">
        <RemoteSearchSelect
          v-model="addForm.product_id"
          :search-url="route('search.products')"
          placeholder="Tìm sản phẩm (SP-...)..."
          @change="opt => addForm.unit = opt?.meta ?? ''"
        />
      </div>
      <input v-model="addForm.requested_qty" type="number" step="any" min="0" placeholder="SL yêu cầu" class="erp-input sm:col-span-2" />
      <input v-model="addForm.specification" placeholder="Quy cách" class="erp-input sm:col-span-2" />
      <input v-model="addForm.note" placeholder="Ghi chú" class="erp-input sm:col-span-2" />
      <button class="erp-btn-primary sm:col-span-1" :disabled="!addForm.product_id || addSubmitting" @click="addItem">Thêm</button>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="text-left px-4 py-3 font-semibold text-gray-600">Mã hàng</th>
            <th class="text-left px-4 py-3 font-semibold text-gray-600">Tên hàng</th>
            <th class="text-left px-4 py-3 font-semibold text-gray-600">Quy cách</th>
            <th class="text-left px-4 py-3 font-semibold text-gray-600">ĐVT</th>
            <th class="text-right px-4 py-3 font-semibold text-gray-600">SL yêu cầu</th>
            <th class="text-left px-4 py-3 font-semibold text-gray-600">Ghi chú</th>
            <th class="px-4 py-3" />
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="it in items" :key="it.id" class="hover:bg-gray-50">
            <td class="px-4 py-3 font-mono text-primary-700">{{ it.product_code }}</td>
            <td class="px-4 py-3 text-gray-900">{{ it.product_name }}</td>
            <td class="px-4 py-3 text-gray-600">{{ it.specification || '—' }}</td>
            <td class="px-4 py-3 text-gray-600">{{ it.unit || '—' }}</td>
            <td class="px-4 py-3 text-right text-gray-900">{{ fmt(it.requested_qty) }}</td>
            <td class="px-4 py-3 text-gray-600">{{ it.note || '—' }}</td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <button
                v-if="editable && !it.has_quote_lines"
                class="text-red-600 hover:text-red-800 text-xs font-medium"
                @click="removeItem(it)"
              >Xóa</button>
            </td>
          </tr>
          <tr v-if="!items.length">
            <td colspan="7" class="px-4 py-10 text-center text-gray-400">Chưa có mặt hàng nào</td>
          </tr>
        </tbody>
      </table>
    </div>

    <Modal :show="showImport" max-width="md" @close="showImport = false">
      <template #title>Import danh sách hàng từ Excel</template>
      <div class="space-y-3">
        <a :href="route('purchasing.quote-comparisons.items-template')" class="text-primary-600 text-sm hover:underline">
          Tải file mẫu (.xlsx)
        </a>
        <input type="file" accept=".xlsx,.xls" class="erp-input" @change="e => importFile = e.target.files[0]" />
        <p class="text-xs text-gray-400">Cột: Mã hàng · SL yêu cầu · Quy cách · Ghi chú. Mã hàng phải là SP- có sẵn.</p>
      </div>
      <template #footer>
        <button class="erp-btn-secondary" @click="showImport = false">Hủy</button>
        <button class="erp-btn-primary" :disabled="!importFile || importing" @click="doImport">Import</button>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import Modal from '@/Components/Shared/Modal.vue';
import RemoteSearchSelect from '@/Components/Shared/RemoteSearchSelect.vue';

const props = defineProps({ comparison: Object, items: Array });

const editable = computed(() => props.comparison.is_draft);

const addForm = ref({ product_id: null, requested_qty: '', specification: '', note: '', unit: '' });
const addSubmitting = ref(false);
const showImport = ref(false);
const importFile = ref(null);
const importing = ref(false);

function fmt(v) {
  return new Intl.NumberFormat('vi-VN').format(v || 0);
}

function addItem() {
  addSubmitting.value = true;
  router.post(route('purchasing.quote-comparisons.items.add', props.comparison.id), {
    product_id: addForm.value.product_id,
    requested_qty: addForm.value.requested_qty,
    specification: addForm.value.specification,
    note: addForm.value.note,
  }, {
    preserveScroll: true,
    onSuccess: () => { addForm.value = { product_id: null, requested_qty: '', specification: '', note: '', unit: '' }; },
    onFinish: () => { addSubmitting.value = false; },
  });
}

function removeItem(it) {
  if (!confirm(`Xóa mặt hàng ${it.product_code}?`)) return;
  router.delete(route('purchasing.quote-comparisons.items.remove', { quoteComparison: props.comparison.id, item: it.id }), {
    preserveScroll: true,
  });
}

function doImport() {
  const fd = new FormData();
  fd.append('file', importFile.value);
  importing.value = true;
  router.post(route('purchasing.quote-comparisons.items.import', props.comparison.id), fd, {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => { showImport.value = false; importFile.value = null; },
    onFinish: () => { importing.value = false; },
  });
}
</script>
