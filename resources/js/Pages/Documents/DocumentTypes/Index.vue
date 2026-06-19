<template>
  <AppLayout>
    <div class="max-w-3xl space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <h1 class="text-2xl font-bold text-gray-900">Loại chứng từ</h1>
        <button @click="showAddForm = true"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          + Thêm loại
        </button>
      </div>

      <!-- Add form -->
      <div v-if="showAddForm" class="bg-white rounded-xl border border-primary-200 p-5">
        <h2 class="text-base font-semibold text-gray-800 mb-4">Thêm loại chứng từ mới</h2>
        <form @submit.prevent="submitAdd" class="space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">Mã loại <span class="text-red-500">*</span></label>
              <input v-model="addForm.code" type="text" placeholder="Vd: HDMB"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
              <p v-if="addForm.errors.code" class="mt-1 text-xs text-red-600">{{ addForm.errors.code }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">Tên loại <span class="text-red-500">*</span></label>
              <input v-model="addForm.name" type="text" placeholder="Vd: Hợp đồng mua bán"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
              <p v-if="addForm.errors.name" class="mt-1 text-xs text-red-600">{{ addForm.errors.name }}</p>
            </div>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Mô tả</label>
            <input v-model="addForm.description" type="text" placeholder="Mô tả ngắn..."
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
          <div class="flex gap-2 justify-end">
            <button type="button" @click="showAddForm = false"
              class="border border-gray-300 text-gray-600 px-4 py-2 rounded-lg text-sm">Huỷ</button>
            <button type="submit" :disabled="addForm.processing"
              class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50">
              Thêm
            </button>
          </div>
        </form>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tên loại</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mô tả</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Số CT</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <template v-for="t in types" :key="t.id">
              <tr class="hover:bg-gray-50">
                <td class="px-5 py-3 font-mono text-xs text-gray-600">{{ t.code }}</td>
                <td class="px-5 py-3 font-medium text-gray-900">
                  <span v-if="editId !== t.id">{{ t.name }}</span>
                  <input v-else v-model="editForm.name" class="border border-gray-300 rounded px-2 py-1 text-sm w-full focus:outline-none focus:ring-1 focus:ring-primary-500" />
                </td>
                <td class="px-5 py-3 text-gray-500 text-xs">
                  <span v-if="editId !== t.id">{{ t.description ?? '—' }}</span>
                  <input v-else v-model="editForm.description" class="border border-gray-300 rounded px-2 py-1 text-sm w-full focus:outline-none focus:ring-1 focus:ring-primary-500" />
                </td>
                <td class="px-5 py-3 text-right text-gray-600">{{ t.documents_count }}</td>
                <td class="px-5 py-3">
                  <StatusBadge :color="t.is_active ? 'green' : 'gray'">{{ t.is_active ? 'Hoạt động' : 'Tắt' }}</StatusBadge>
                </td>
                <td class="px-5 py-3 text-right">
                  <template v-if="editId === t.id">
                    <button @click="submitEdit(t.id)" class="text-primary-600 hover:text-primary-800 text-xs font-medium mr-2">Lưu</button>
                    <button @click="editId = null" class="text-gray-500 hover:text-gray-700 text-xs">Huỷ</button>
                  </template>
                  <template v-else>
                    <button @click="startEdit(t)" class="text-primary-600 hover:text-primary-800 text-xs font-medium mr-2">Sửa</button>
                    <button @click="deleteType(t)" :disabled="t.documents_count > 0"
                      class="text-red-500 hover:text-red-700 text-xs disabled:opacity-30 disabled:cursor-not-allowed">Xoá</button>
                  </template>
                </td>
              </tr>
            </template>
            <tr v-if="!types.length">
              <td colspan="6" class="px-5 py-10 text-center text-gray-400">Chưa có loại chứng từ nào</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';

defineProps({ types: Array });

const showAddForm = ref(false);
const editId      = ref(null);

const addForm = useForm({ code: '', name: '', description: '' });
const editForm = useForm({ name: '', code: '', description: '', is_active: true });

function submitAdd() {
  addForm.post(route('documents.types.store'), {
    onSuccess: () => { showAddForm.value = false; addForm.reset(); },
  });
}

function startEdit(t) {
  editId.value = t.id;
  editForm.name        = t.name;
  editForm.code        = t.code;
  editForm.description = t.description ?? '';
  editForm.is_active   = t.is_active;
}

function submitEdit(id) {
  editForm.put(route('documents.types.update', id), {
    onSuccess: () => { editId.value = null; },
  });
}

function deleteType(t) {
  if (!confirm(`Xoá loại "${t.name}"?`)) return;
  router.delete(route('documents.types.destroy', t.id));
}
</script>
