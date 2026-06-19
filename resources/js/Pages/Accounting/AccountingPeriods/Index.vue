<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <h1 class="text-2xl font-bold text-gray-900">Kỳ kế toán</h1>
        <button v-if="can('accounting.manage')" @click="showAdd = true"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Mở kỳ mới
        </button>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Kỳ kế toán</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Đóng lúc</th>
              <th v-if="can('accounting.manage')" class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="p in periods" :key="p.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-semibold text-gray-800">{{ p.label }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="p.status_color">{{ p.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-gray-500 text-xs">{{ p.closed_at ?? '—' }}</td>
              <td v-if="can('accounting.manage')" class="px-5 py-3 text-right space-x-2">
                <button v-if="p.status === 'open'" @click="doAction('close', p.id)"
                  class="text-yellow-600 hover:text-yellow-800 text-xs font-medium">Đóng kỳ</button>
                <button v-if="p.status === 'closed'" @click="doAction('lock', p.id)"
                  class="text-red-600 hover:text-red-800 text-xs font-medium">Khóa</button>
                <button v-if="p.status === 'closed'" @click="doAction('reopen', p.id)"
                  class="text-blue-600 hover:text-blue-800 text-xs font-medium">Mở lại</button>
              </td>
            </tr>
            <tr v-if="!periods.length">
              <td colspan="4" class="px-5 py-10 text-center text-gray-400">Chưa có kỳ kế toán nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Modal: Mở kỳ mới -->
      <div v-if="showAdd" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-sm">
          <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900">Mở kỳ kế toán</h3>
            <button @click="showAdd = false" class="text-gray-400 hover:text-gray-600">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <form @submit.prevent="submitAdd" class="p-6 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Tháng</label>
                <select v-model="addForm.month" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                  <option v-for="m in 12" :key="m" :value="m">Tháng {{ m }}</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Năm</label>
                <input v-model="addForm.year" type="number" required min="2020" max="2099"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
              </div>
            </div>
            <div class="flex justify-end gap-3">
              <button type="button" @click="showAdd = false" class="px-4 py-2 text-sm border border-gray-300 rounded-lg text-gray-700">Hủy</button>
              <button type="submit" class="px-4 py-2 text-sm bg-primary-600 text-white rounded-lg hover:bg-primary-700">Mở kỳ</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { usePermission } from '@/composables/usePermission';

defineProps({ periods: Array });
const { hasPermission: can } = usePermission();

const showAdd = ref(false);
const now = new Date();
const addForm = useForm({ month: now.getMonth() + 1, year: now.getFullYear() });

function submitAdd() {
  addForm.post(route('accounting.accounting-periods.store'), {
    onSuccess: () => { showAdd.value = false; addForm.reset(); },
  });
}

function doAction(action, id) {
  router.post(route(`accounting.accounting-periods.${action}`, id));
}
</script>
