<template>
  <AppLayout title="Tài khoản nội bộ">
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <div>
          <h1 class="text-2xl font-bold text-slate-900">Tài khoản nội bộ</h1>
          <p class="text-sm text-slate-500 mt-0.5">Các tài khoản NH của công ty / cá nhân nội bộ dùng để nhận dạng chuyển khoản nội bộ khi import sao kê.</p>
        </div>
        <button @click="openAdd" class="erp-btn-primary">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Thêm tài khoản
        </button>
      </div>

      <div class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 border-b border-slate-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Tên / Mô tả</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Số tài khoản</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Ngân hàng</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Chủ TK</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Trạng thái</th>
              <th class="px-5 py-3 w-20"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="acc in accounts" :key="acc.id" class="hover:bg-slate-50/70">
              <td class="px-5 py-3">
                <div class="font-medium text-slate-800">{{ acc.name }}</div>
                <div v-if="acc.description" class="text-xs text-slate-400 mt-0.5">{{ acc.description }}</div>
              </td>
              <td class="px-5 py-3 font-mono text-sm font-semibold text-slate-700">{{ acc.account_number }}</td>
              <td class="px-5 py-3 text-slate-600">{{ acc.bank_name || '—' }}</td>
              <td class="px-5 py-3 text-slate-600">{{ acc.owner_name || '—' }}</td>
              <td class="px-5 py-3">
                <span :class="acc.is_active ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-slate-100 text-slate-500'"
                  class="inline-flex px-2 py-0.5 rounded text-xs font-medium">
                  {{ acc.is_active ? 'Đang dùng' : 'Ngưng' }}
                </span>
              </td>
              <td class="px-5 py-3 text-right whitespace-nowrap">
                <button @click="openEdit(acc)" class="text-primary-600 hover:text-primary-800 font-medium text-xs mr-3">Sửa</button>
                <button @click="confirmDelete(acc)" class="text-red-500 hover:text-red-700 font-medium text-xs">Xóa</button>
              </td>
            </tr>
            <tr v-if="!accounts.length">
              <td colspan="6" class="px-5 py-14 text-center text-slate-400">
                <svg class="w-8 h-8 mx-auto mb-2 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                </svg>
                Chưa có tài khoản nội bộ nào
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Add/Edit Modal -->
    <Modal :show="showForm" @close="closeForm">
      <template #title>{{ editing ? 'Sửa tài khoản nội bộ' : 'Thêm tài khoản nội bộ' }}</template>
      <div class="space-y-4">
        <div>
          <label class="erp-label">Tên tài khoản <span class="text-red-500">*</span></label>
          <input v-model="form.name" class="erp-input" placeholder="VD: Tài khoản cá nhân Giám đốc" />
          <p v-if="errors.name" class="erp-error">{{ errors.name }}</p>
        </div>
        <div>
          <label class="erp-label">Số tài khoản <span class="text-red-500">*</span></label>
          <input v-model="form.account_number" class="erp-input font-mono" placeholder="19036130647011" />
          <p v-if="errors.account_number" class="erp-error">{{ errors.account_number }}</p>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="erp-label">Ngân hàng</label>
            <input v-model="form.bank_name" class="erp-input" placeholder="Techcombank" />
          </div>
          <div>
            <label class="erp-label">Tên chủ tài khoản</label>
            <input v-model="form.owner_name" class="erp-input" placeholder="Nguyễn Văn A" />
          </div>
        </div>
        <div>
          <label class="erp-label">Ghi chú</label>
          <input v-model="form.description" class="erp-input" placeholder="Mục đích sử dụng..." />
        </div>
        <div v-if="editing" class="flex items-center gap-2">
          <input type="checkbox" v-model="form.is_active" id="is_active" class="rounded" />
          <label for="is_active" class="text-sm text-slate-700">Đang sử dụng</label>
        </div>
      </div>
      <template #footer>
        <button @click="closeForm" class="erp-btn-secondary">Hủy</button>
        <button @click="submitForm" class="erp-btn-primary">{{ editing ? 'Lưu' : 'Thêm' }}</button>
      </template>
    </Modal>

    <!-- Delete Modal -->
    <Modal :show="deleteTarget !== null" @close="deleteTarget = null">
      <template #title>Xóa tài khoản nội bộ</template>
      <p class="text-sm text-slate-600">Xóa tài khoản <strong>{{ deleteTarget?.account_number }}</strong> — {{ deleteTarget?.name }}?</p>
      <template #footer>
        <button @click="deleteTarget = null" class="erp-btn-secondary">Hủy</button>
        <button @click="doDelete" class="erp-btn-danger">Xóa</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import Modal from '@/Components/Shared/Modal.vue';

const props = defineProps({ accounts: Array });

const showForm    = ref(false);
const editing     = ref(null);
const deleteTarget = ref(null);
const errors      = ref({});

const emptyForm = () => ({ name: '', account_number: '', bank_name: '', owner_name: '', description: '', is_active: true });
const form = ref(emptyForm());

function openAdd() { editing.value = null; form.value = emptyForm(); errors.value = {}; showForm.value = true; }
function openEdit(acc) { editing.value = acc; form.value = { ...acc }; errors.value = {}; showForm.value = true; }
function closeForm() { showForm.value = false; }
function confirmDelete(acc) { deleteTarget.value = acc; }

function submitForm() {
  errors.value = {};
  const url = editing.value
    ? route('accounting.internal-bank-accounts.update', editing.value.id)
    : route('accounting.internal-bank-accounts.store');
  const method = editing.value ? 'put' : 'post';

  router[method](url, form.value, {
    onSuccess: () => closeForm(),
    onError: (e) => { errors.value = e; },
  });
}

function doDelete() {
  router.delete(route('accounting.internal-bank-accounts.destroy', deleteTarget.value.id), {
    onSuccess: () => { deleteTarget.value = null; },
  });
}
</script>
