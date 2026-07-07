<template>
  <AppLayout>
    <div class="max-w-5xl space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <h1 class="text-2xl font-bold text-gray-900">Quản lý bộ phận</h1>
        <Link :href="route('admin.departments.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-semibold">
          + Thêm bộ phận
        </Link>
      </div>

      <!-- Flash -->
      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm">
        {{ $page.props.flash.success }}
      </div>

      <!-- Filters -->
      <div class="flex gap-3 flex-wrap">
        <input v-model="searchInput" @keyup.enter="applyFilters" type="text" placeholder="Tìm tên, mã..."
          class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none w-56" />
        <button @click="applyFilters" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm">Tìm</button>
        <button @click="clearFilters" class="px-4 py-2 text-gray-500 hover:text-gray-700 text-sm">Xóa lọc</button>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-4 py-3 font-medium text-gray-600">Mã bộ phận</th>
              <th class="text-left px-4 py-3 font-medium text-gray-600">Tên bộ phận</th>
              <th class="text-left px-4 py-3 font-medium text-gray-600">Ghi chú</th>
              <th class="text-center px-4 py-3 font-medium text-gray-600">Trạng thái</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="departments.data.length === 0">
              <td colspan="5" class="px-4 py-8 text-center text-gray-400">Chưa có bộ phận nào.</td>
            </tr>
            <tr v-for="d in departments.data" :key="d.id" class="hover:bg-gray-50">
              <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ d.code }}</td>
              <td class="px-4 py-3 font-medium text-gray-900">{{ d.name }}</td>
              <td class="px-4 py-3 text-gray-600 max-w-xs truncate">{{ d.notes || '—' }}</td>
              <td class="px-4 py-3 text-center">
                <span :class="d.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'"
                  class="px-2 py-0.5 rounded-full text-xs font-medium">
                  {{ d.is_active ? 'Hoạt động' : 'Ngừng' }}
                </span>
              </td>
              <td class="px-4 py-3 text-right">
                <div class="flex items-center gap-2 justify-end">
                  <Link :href="route('admin.departments.edit', d.id)"
                    class="text-primary-600 hover:underline text-xs">Sửa</Link>
                  <button @click="confirmDelete(d)" class="text-red-500 hover:underline text-xs">Xóa</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="departments.last_page > 1" class="flex gap-2">
        <Link v-for="link in departments.links" :key="link.label"
          :href="link.url || '#'"
          :class="[link.active ? 'bg-primary-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50',
                   'px-3 py-1.5 rounded-lg border border-gray-200 text-sm', !link.url && 'opacity-40 cursor-default']"
          v-html="link.label" />
      </div>
    </div>

    <!-- Delete confirm modal -->
    <div v-if="deleteTarget" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl p-6 max-w-sm w-full mx-4 shadow-xl space-y-4">
        <h3 class="font-semibold text-gray-900">Xóa bộ phận?</h3>
        <p class="text-sm text-gray-600">Bộ phận <strong>{{ deleteTarget.name }}</strong> sẽ bị xóa.</p>
        <div class="flex gap-3 justify-end">
          <button @click="deleteTarget = null" class="px-4 py-2 border border-gray-300 rounded-lg text-sm">Hủy</button>
          <button @click="doDelete" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm">Xóa</button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({ departments: Object, filters: Object });

const searchInput = ref(props.filters?.search || '');
const deleteTarget = ref(null);

function applyFilters() {
  router.get(route('admin.departments.index'), { search: searchInput.value }, { preserveState: true, replace: true });
}
function clearFilters() {
  searchInput.value = '';
  router.get(route('admin.departments.index'));
}
function confirmDelete(d) { deleteTarget.value = d; }
function doDelete() {
  router.delete(route('admin.departments.destroy', deleteTarget.value.id), {
    onSuccess: () => { deleteTarget.value = null; },
  });
}
</script>
