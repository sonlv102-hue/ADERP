<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">Hồ sơ chứng từ</h1>
        <Link v-if="can('documents.create')" :href="route('documents.documents.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          + Tải lên chứng từ
        </Link>
      </div>

      <!-- Filters -->
      <div class="bg-white rounded-xl border border-gray-200 p-4 flex flex-wrap gap-3">
        <input v-model="search" @keyup.enter="applyFilters" type="text" placeholder="Tìm theo mã, tiêu đề..."
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm flex-1 min-w-48 focus:outline-none focus:ring-2 focus:ring-primary-500" />
        <select v-model="typeId" @change="applyFilters"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả loại</option>
          <option v-for="t in document_types" :key="t.id" :value="t.id">{{ t.name }}</option>
        </select>
        <select v-model="statusFilter" @change="applyFilters"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả trạng thái</option>
          <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
        <button @click="applyFilters"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          Lọc
        </button>
        <button v-if="hasFilters" @click="clearFilters"
          class="border border-gray-300 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm">
          Xoá lọc
        </button>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tiêu đề</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Loại</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày phát hành</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Hết hạn</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">File</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Người tải</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="d in documents.data" :key="d.id"
              class="hover:bg-gray-50 cursor-pointer"
              @click="$inertia.visit(route('documents.documents.show', d.id))">
              <td class="px-5 py-3 font-mono text-primary-700 font-medium">{{ d.code }}</td>
              <td class="px-5 py-3 font-medium text-gray-900 max-w-xs truncate">{{ d.title }}</td>
              <td class="px-5 py-3 text-gray-600">{{ d.type_name }}</td>
              <td class="px-5 py-3 text-gray-600">{{ d.issued_date ?? '—' }}</td>
              <td class="px-5 py-3" :class="isExpiringSoon(d) ? 'text-orange-600 font-medium' : 'text-gray-600'">
                {{ d.expired_date ?? '—' }}
              </td>
              <td class="px-5 py-3">
                <StatusBadge :color="d.status_color">{{ d.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-gray-500 text-xs">
                <span v-if="d.file_name" class="flex items-center gap-1">
                  <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                  </svg>
                  {{ shortFileName(d.file_name) }}
                </span>
                <span v-else class="text-gray-300">—</span>
              </td>
              <td class="px-5 py-3 text-gray-600">{{ d.uploader }}</td>
            </tr>
            <tr v-if="!documents.data?.length">
              <td colspan="8" class="px-5 py-10 text-center text-gray-400">Chưa có chứng từ nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="documents.links" :meta="documents.meta" />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { usePermission } from '@/composables/usePermission';

const props = defineProps({
  documents: Object,
  document_types: Array,
  statuses: Array,
  filters: Object,
});

const { hasPermission } = usePermission();
const can = hasPermission;

const search       = ref(props.filters?.search ?? '');
const typeId       = ref(props.filters?.type_id ?? '');
const statusFilter = ref(props.filters?.status ?? '');

const hasFilters = computed(() => search.value || typeId.value || statusFilter.value);

function applyFilters() {
  router.get(route('documents.documents.index'), {
    search:  search.value || undefined,
    type_id: typeId.value || undefined,
    status:  statusFilter.value || undefined,
  }, { preserveState: true, replace: true });
}

function clearFilters() {
  search.value = '';
  typeId.value = '';
  statusFilter.value = '';
  applyFilters();
}

function shortFileName(name) {
  return name?.length > 20 ? name.slice(0, 18) + '…' : name;
}

function isExpiringSoon(d) {
  if (!d.expired_date || d.status !== 'active') return false;
  const parts = d.expired_date.split('/');
  const exp = new Date(parts[2], parts[1] - 1, parts[0]);
  const diff = (exp - new Date()) / (1000 * 60 * 60 * 24);
  return diff >= 0 && diff <= 30;
}
</script>
