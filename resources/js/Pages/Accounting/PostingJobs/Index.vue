<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-slate-900">Bút toán tự động</h1>
          <p class="text-sm text-slate-500 mt-1">Theo dõi và retry các bút toán hạch toán tự động bị lỗi</p>
        </div>
      </div>

      <!-- Filters -->
      <div class="flex flex-wrap gap-3">
        <select v-model="status" @change="applyFilters" class="erp-input w-44">
          <option value="">Tất cả trạng thái</option>
          <option value="failed">Lỗi</option>
          <option value="pending">Chờ hạch toán</option>
          <option value="posted">Đã hạch toán</option>
        </select>
        <select v-model="sourceType" @change="applyFilters" class="erp-input w-52">
          <option value="">Tất cả loại chứng từ</option>
          <option v-for="(label, key) in sourceTypes" :key="key" :value="key">{{ label }}</option>
        </select>
      </div>

      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 border-b border-slate-200">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Chứng từ</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Loại hạch toán</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Ngày CT</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Trạng thái</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Lỗi</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Lần thử</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Lần cuối</th>
              <th v-if="can('accounting.manage')" class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="jobs.data.length === 0">
              <td :colspan="can('accounting.manage') ? 8 : 7" class="px-4 py-12 text-center text-slate-400">
                Không có dữ liệu
              </td>
            </tr>
            <tr v-for="job in jobs.data" :key="job.id" class="hover:bg-slate-50">
              <td class="px-4 py-3">
                <div class="font-medium text-slate-900">{{ job.source_type_label }}</div>
                <div class="text-xs text-slate-400">#{{ job.source_id }}</div>
              </td>
              <td class="px-4 py-3 text-slate-600">{{ job.posting_type }}</td>
              <td class="px-4 py-3 text-slate-600">{{ job.posting_date }}</td>
              <td class="px-4 py-3">
                <StatusBadge :color="job.status_color">{{ job.status_label }}</StatusBadge>
              </td>
              <td class="px-4 py-3">
                <div v-if="job.error_code" class="text-xs font-mono text-red-600 font-semibold">{{ job.error_code }}</div>
                <div v-if="job.error_message" class="text-xs text-slate-500 max-w-xs truncate" :title="job.error_message">
                  {{ job.error_message }}
                </div>
              </td>
              <td class="px-4 py-3 text-center text-slate-600">{{ job.attempts }}</td>
              <td class="px-4 py-3 text-xs text-slate-500">{{ job.last_attempted_at ?? '—' }}</td>
              <td v-if="can('accounting.manage')" class="px-4 py-3 text-right">
                <button v-if="job.status !== 'posted'"
                  @click="retry(job)"
                  :disabled="retrying === job.id"
                  class="erp-btn-secondary text-xs py-1 px-3">
                  {{ retrying === job.id ? 'Đang retry...' : 'Retry' }}
                </button>
                <span v-else class="text-xs text-green-600">✓ Đã hạch toán</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="jobs.links" />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { usePermission } from '@/composables/usePermission';

const { hasPermission: can } = usePermission();
const props = defineProps({ jobs: Object, filters: Object, sourceTypes: Object });

const status     = ref(props.filters.status ?? '');
const sourceType = ref(props.filters.source_type ?? '');
const retrying   = ref(null);

function applyFilters() {
  router.get(route('accounting.posting-jobs.index'), {
    status: status.value,
    source_type: sourceType.value,
  }, { preserveState: true });
}

function retry(job) {
  retrying.value = job.id;
  router.post(route('accounting.posting-jobs.retry', job.id), {}, {
    onFinish: () => { retrying.value = null; },
  });
}
</script>
