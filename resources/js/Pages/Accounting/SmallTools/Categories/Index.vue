<template>
  <AppLayout>
    <div class="max-w-3xl">
      <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Danh mục CCDC</h1>
        <button v-if="can('ccdc.manage')" @click="openCreate"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          + Thêm danh mục
        </button>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b">
            <tr>
              <th class="px-4 py-3 text-left">Mã</th>
              <th class="px-4 py-3 text-left">Tên danh mục</th>
              <th class="px-4 py-3 text-left">Mô tả</th>
              <th class="px-4 py-3 text-right">Số CCDC</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="cat in categories" :key="cat.id" class="hover:bg-gray-50">
              <td class="px-4 py-3 font-mono text-xs font-semibold">{{ cat.code }}</td>
              <td class="px-4 py-3 font-medium">{{ cat.name }}</td>
              <td class="px-4 py-3 text-gray-500">{{ cat.description || '—' }}</td>
              <td class="px-4 py-3 text-right">{{ cat.small_tools_count }}</td>
              <td class="px-4 py-3 text-right">
                <button v-if="can('ccdc.manage')" @click="openEdit(cat)"
                  class="text-xs text-primary-600 hover:underline mr-3">Sửa</button>
                <button v-if="can('ccdc.manage') && cat.small_tools_count === 0" @click="destroy(cat)"
                  class="text-xs text-red-500 hover:underline">Xóa</button>
              </td>
            </tr>
            <tr v-if="!categories.length">
              <td colspan="5" class="px-4 py-8 text-center text-gray-400">Chưa có danh mục nào.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Modal -->
      <div v-if="modal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-xl p-6 w-96">
          <h2 class="text-lg font-bold mb-4">{{ editing ? 'Sửa danh mục' : 'Thêm danh mục' }}</h2>
          <form @submit.prevent="submitModal" class="space-y-4">
            <div>
              <label class="erp-label">Mã <span class="text-red-500">*</span></label>
              <input v-model="form.code" type="text" class="erp-input" :class="{ 'border-red-500': form.errors.code }" />
              <p v-if="form.errors.code" class="erp-error">{{ form.errors.code }}</p>
            </div>
            <div>
              <label class="erp-label">Tên <span class="text-red-500">*</span></label>
              <input v-model="form.name" type="text" class="erp-input" :class="{ 'border-red-500': form.errors.name }" />
              <p v-if="form.errors.name" class="erp-error">{{ form.errors.name }}</p>
            </div>
            <div>
              <label class="erp-label">Mô tả</label>
              <textarea v-model="form.description" rows="2" class="erp-input" />
            </div>
            <div class="flex gap-3 pt-2">
              <button type="submit" :disabled="form.processing"
                class="bg-primary-600 text-white px-5 py-2 rounded-lg text-sm font-medium">
                {{ form.processing ? 'Đang lưu...' : 'Lưu' }}
              </button>
              <button type="button" @click="modal = false"
                class="px-5 py-2 border border-gray-300 rounded-lg text-sm text-gray-700">Hủy</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { usePermission } from '@/composables/usePermission';

const { hasPermission: can } = usePermission();
const props = defineProps({ categories: Array });

const modal   = ref(false);
const editing = ref(null);

const form = useForm({ code: '', name: '', description: '' });

function openCreate() {
  editing.value = null;
  form.reset();
  modal.value = true;
}
function openEdit(cat) {
  editing.value = cat;
  form.code        = cat.code;
  form.name        = cat.name;
  form.description = cat.description || '';
  modal.value = true;
}
function submitModal() {
  if (editing.value) {
    form.put(route('accounting.small-tools.categories.update', editing.value.id), {
      onSuccess: () => { modal.value = false; },
    });
  } else {
    form.post(route('accounting.small-tools.categories.store'), {
      onSuccess: () => { modal.value = false; form.reset(); },
    });
  }
}
function destroy(cat) {
  if (confirm(`Xóa danh mục "${cat.name}"?`)) {
    router.delete(route('accounting.small-tools.categories.destroy', cat.id));
  }
}
</script>
