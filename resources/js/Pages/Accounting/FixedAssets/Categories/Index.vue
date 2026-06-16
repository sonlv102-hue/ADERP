<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-slate-900">Nhóm tài sản cố định</h1>
          <p class="text-sm text-slate-500 mt-1">Phân loại TSCĐ theo TT133/TT45</p>
        </div>
        <button v-if="can('accounting.manage')" @click="openCreate" class="erp-btn-primary">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Thêm nhóm
        </button>
      </div>

      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 border-b border-slate-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Mã nhóm</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Tên nhóm</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">TK nguyên giá</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">TK hao mòn</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">TK chi phí KH</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Khung thời gian</th>
              <th class="text-right px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Số TSCĐ</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="cat in categories" :key="cat.id" class="hover:bg-slate-50">
              <td class="px-5 py-3 font-mono font-semibold text-slate-700">{{ cat.code }}</td>
              <td class="px-5 py-3 font-medium text-slate-900">{{ cat.name }}</td>
              <td class="px-5 py-3 font-mono text-indigo-700">{{ cat.asset_account_code || '—' }}</td>
              <td class="px-5 py-3 font-mono text-indigo-700">{{ cat.depreciation_account_code || '—' }}</td>
              <td class="px-5 py-3 font-mono text-indigo-700">{{ cat.expense_account_code || '—' }}</td>
              <td class="px-5 py-3 text-slate-600 text-xs">
                <span v-if="cat.min_useful_life_months || cat.max_useful_life_months">
                  {{ cat.min_useful_life_months ? (cat.min_useful_life_months / 12) + ' năm' : '' }}
                  {{ cat.min_useful_life_months && cat.max_useful_life_months ? ' – ' : '' }}
                  {{ cat.max_useful_life_months ? (cat.max_useful_life_months / 12) + ' năm' : '' }}
                  <span class="text-slate-400">({{ cat.legal_basis }})</span>
                </span>
                <span v-else class="text-slate-400">—</span>
              </td>
              <td class="px-5 py-3 text-right text-slate-700">{{ cat.fixed_assets_count }}</td>
              <td class="px-5 py-3 text-right">
                <div v-if="can('accounting.manage')" class="flex items-center justify-end gap-1">
                  <button @click="openEdit(cat)" class="erp-btn-icon" title="Sửa">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                  </button>
                  <button @click="confirmDelete(cat)" class="erp-btn-icon text-red-500" title="Xóa">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="categories.length === 0">
              <td colspan="8" class="px-5 py-10 text-center text-slate-400">Chưa có nhóm tài sản nào.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal thêm/sửa -->
    <Modal :show="showForm" @close="closeForm" :title="editTarget ? 'Sửa nhóm tài sản' : 'Thêm nhóm tài sản'" max-width="2xl">
      <form @submit.prevent="submitForm" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="erp-label">Mã nhóm <span class="text-red-500">*</span></label>
            <input v-model="form.code" class="erp-input w-full" placeholder="VD: PTTB" required />
          </div>
          <div>
            <label class="erp-label">Tên nhóm <span class="text-red-500">*</span></label>
            <input v-model="form.name" class="erp-input w-full" required />
          </div>
          <div>
            <label class="erp-label">TK nguyên giá</label>
            <input v-model="form.asset_account_code" class="erp-input w-full font-mono" placeholder="2111" />
          </div>
          <div>
            <label class="erp-label">TK hao mòn</label>
            <input v-model="form.depreciation_account_code" class="erp-input w-full font-mono" placeholder="2141" />
          </div>
          <div>
            <label class="erp-label">TK chi phí KH</label>
            <input v-model="form.expense_account_code" class="erp-input w-full font-mono" placeholder="6421" />
          </div>
          <div>
            <label class="erp-label">Căn cứ pháp lý</label>
            <input v-model="form.legal_basis" class="erp-input w-full" placeholder="TT45/2013/TT-BTC" />
          </div>
          <div>
            <label class="erp-label">Thời gian KH tối thiểu (tháng)</label>
            <input v-model.number="form.min_useful_life_months" type="number" min="1" class="erp-input w-full" />
          </div>
          <div>
            <label class="erp-label">Thời gian KH tối đa (tháng)</label>
            <input v-model.number="form.max_useful_life_months" type="number" min="1" class="erp-input w-full" />
          </div>
        </div>
        <div>
          <label class="erp-label">Mô tả</label>
          <textarea v-model="form.description" class="erp-input w-full" rows="2" />
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="closeForm" class="erp-btn-secondary">Hủy</button>
          <button type="submit" class="erp-btn-primary">{{ editTarget ? 'Lưu' : 'Thêm nhóm' }}</button>
        </div>
      </form>
    </Modal>

    <!-- Confirm delete -->
    <Modal :show="!!deleteTarget" @close="deleteTarget = null" title="Xóa nhóm tài sản">
      <p class="text-slate-700">Bạn có chắc muốn xóa nhóm <strong>{{ deleteTarget?.name }}</strong>?</p>
      <div class="flex justify-end gap-2 mt-4">
        <button @click="deleteTarget = null" class="erp-btn-secondary">Hủy</button>
        <button @click="doDelete" class="erp-btn-danger">Xóa</button>
      </div>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import Modal from '@/Components/Shared/Modal.vue';
import { usePermission } from '@/composables/usePermission';

const props = defineProps({ categories: Array });
const { can } = usePermission();

const showForm   = ref(false);
const editTarget = ref(null);
const deleteTarget = ref(null);

const emptyForm = () => ({ code: '', name: '', asset_account_code: '2111', depreciation_account_code: '2141', expense_account_code: '6421', min_useful_life_months: null, max_useful_life_months: null, legal_basis: '', description: '' });
const form = ref(emptyForm());

function openCreate() {
  editTarget.value = null;
  form.value = emptyForm();
  showForm.value = true;
}

function openEdit(cat) {
  editTarget.value = cat;
  form.value = { ...cat };
  showForm.value = true;
}

function closeForm() {
  showForm.value = false;
  editTarget.value = null;
}

function submitForm() {
  if (editTarget.value) {
    router.put(route('accounting.fixed-assets.categories.update', editTarget.value.id), form.value, {
      onSuccess: closeForm,
    });
  } else {
    router.post(route('accounting.fixed-assets.categories.store'), form.value, {
      onSuccess: closeForm,
    });
  }
}

function confirmDelete(cat) { deleteTarget.value = cat; }

function doDelete() {
  router.delete(route('accounting.fixed-assets.categories.destroy', deleteTarget.value.id), {
    onSuccess: () => { deleteTarget.value = null; },
  });
}
</script>
