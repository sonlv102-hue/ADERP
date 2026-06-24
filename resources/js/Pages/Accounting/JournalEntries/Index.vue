<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <h1 class="text-2xl font-bold text-slate-900">Phiếu kế toán / Bút toán</h1>
        <div class="flex items-center gap-2 flex-wrap">
          <ExportExcelButton :endpoint="route('accounting.journal-entries.export-excel')" :filters="{ search, status, from, to }" />
          <button v-if="can('accounting.manage') && draftCount > 0" @click="showBulkApprove = true"
            class="erp-btn-secondary text-green-700 border-green-300 hover:bg-green-50 hover:border-green-400">
            <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Duyệt tất cả nháp
            <span class="ml-1 bg-green-600 text-white text-xs px-1.5 py-0.5 rounded-full font-bold">{{ draftCount }}</span>
          </button>
          <Link v-if="can('accounting.manage')" :href="route('accounting.journal-entries.create')"
            class="erp-btn-primary">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Tạo bút toán
          </Link>
        </div>
      </div>

      <!-- Filters -->
      <div class="flex flex-wrap gap-3">
        <input v-model="search" @change="applyFilters" placeholder="Tìm mã, diễn giải..."
          class="erp-input w-64" />
        <select v-model="status" @change="applyFilters" class="erp-input w-44">
          <option value="">Tất cả trạng thái</option>
          <option value="draft">Nháp</option>
          <option value="posted">Đã hạch toán</option>
          <option value="reversed">Đã đảo</option>
          <option value="voided">Đã hủy</option>
        </select>
        <input v-model="from" @change="applyFilters" type="date" class="erp-input w-40" />
        <input v-model="to" @change="applyFilters" type="date" class="erp-input w-40" />
      </div>

      <div class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 border-b border-slate-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Mã BT</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Ngày</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Diễn giải</th>
              <th class="text-right px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Tổng Nợ</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Trạng thái</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Loại</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="e in entries.data" :key="e.id"
              class="hover:bg-slate-50/70 transition-colors"
              :class="e.status === 'voided' ? 'opacity-60' : ''">
              <td class="px-5 py-3 font-mono text-xs font-semibold text-slate-700">{{ e.code }}</td>
              <td class="px-5 py-3 text-slate-600 whitespace-nowrap">{{ e.entry_date }}</td>
              <td class="px-5 py-3 text-slate-800">{{ e.description }}</td>
              <td class="px-5 py-3 text-right text-slate-800 font-medium">{{ formatVnd(e.total_debit) }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="e.status_color">{{ e.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3">
                <span v-if="e.is_auto" class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-600 border border-blue-200">
                  Tự động
                </span>
                <span v-else class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-600">
                  Thủ công
                </span>
              </td>
              <td class="px-5 py-3 text-right whitespace-nowrap">
                <Link :href="route('accounting.journal-entries.show', e.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium mr-3 text-xs">Xem →</Link>
                <button v-if="can('accounting.manage') && e.status === 'draft'" @click="confirmPost(e)"
                  class="text-green-600 hover:text-green-800 font-medium mr-3 text-xs">Duyệt</button>
                <button v-if="can('accounting.manage') && e.status === 'draft'" @click="confirmDelete(e)"
                  class="text-red-500 hover:text-red-700 font-medium text-xs">Xóa</button>
                <button v-if="can('accounting.manage') && (e.status === 'posted' || e.status === 'reversed')" @click="confirmVoid(e)"
                  class="text-red-500 hover:text-red-700 font-medium text-xs">Hủy</button>
              </td>
            </tr>
            <tr v-if="!entries.data?.length">
              <td colspan="7" class="px-5 py-14 text-center text-slate-400">
                <svg class="w-8 h-8 mx-auto mb-2 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                Chưa có bút toán nào
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="entries.links" :meta="entries.meta" />
    </div>

    <!-- Bulk Approve Modal -->
    <Modal :show="showBulkApprove" @close="showBulkApprove = false">
      <template #title>Duyệt tất cả bút toán nháp</template>
      <div class="space-y-3 text-sm text-slate-600">
        <p>Xác nhận duyệt và hạch toán <strong class="text-slate-900">{{ draftCount }} bút toán</strong> đang ở trạng thái Nháp?</p>
        <p class="text-blue-600 bg-blue-50 px-3 py-2 rounded-lg">
          Sau khi duyệt, các bút toán sẽ có hiệu lực và ảnh hưởng đến báo cáo kế toán.
        </p>
      </div>
      <template #footer>
        <button @click="showBulkApprove = false" class="erp-btn-secondary">Hủy</button>
        <button @click="doBulkApprove" class="erp-btn-primary bg-green-600 hover:bg-green-700 active:bg-green-800">
          Duyệt {{ draftCount }} bút toán
        </button>
      </template>
    </Modal>

    <!-- Delete Modal (chỉ cho draft) -->
    <Modal :show="deleteTarget !== null" @close="deleteTarget = null">
      <template #title>Xóa bút toán nháp</template>
      <div class="space-y-2 text-sm text-slate-600">
        <p>Bạn có chắc muốn xóa bút toán <strong>{{ deleteTarget?.code }}</strong>?</p>
        <p class="italic text-slate-500">{{ deleteTarget?.description }}</p>
        <p v-if="deleteTarget?.is_auto" class="text-amber-600 font-medium bg-amber-50 px-3 py-2 rounded-lg">
          Đây là bút toán tự động — xóa sẽ cần tạo lại nếu cần.
        </p>
        <p class="text-red-600 font-medium bg-red-50 px-3 py-2 rounded-lg">Không thể hoàn tác sau khi xóa.</p>
      </div>
      <template #footer>
        <button @click="deleteTarget = null" class="erp-btn-secondary">Hủy</button>
        <button @click="doDelete" class="erp-btn-danger">Xóa</button>
      </template>
    </Modal>

    <!-- Void Modal (cho posted và reversed) -->
    <Modal :show="voidTarget !== null" @close="voidTarget = null">
      <template #title>
        {{ voidTarget?.status === 'reversed' ? 'Hủy cặp bút toán' : 'Hủy bút toán' }}
      </template>
      <div class="space-y-3 text-sm text-slate-600">
        <p>Bút toán <strong>{{ voidTarget?.code }}</strong>: {{ voidTarget?.description }}</p>
        <template v-if="voidTarget?.status === 'reversed'">
          <p>Bút toán này đã được đảo. Khi hủy, hệ thống sẽ hủy cả bút toán gốc và bút toán đảo ngược liên quan.</p>
        </template>
        <p>Sau khi hủy, bút toán không còn ảnh hưởng đến sổ cái và báo cáo, nhưng vẫn được lưu lại để tra cứu lịch sử.</p>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Lý do hủy (tùy chọn)</label>
          <textarea v-model="voidReason" rows="2" maxlength="500" placeholder="Nhập lý do..."
            class="erp-input w-full" />
        </div>
      </div>
      <template #footer>
        <button @click="voidTarget = null" class="erp-btn-secondary">Hủy</button>
        <button @click="doVoid" class="erp-btn-danger">
          {{ voidTarget?.status === 'reversed' ? 'Hủy cặp bút toán' : 'Hủy bút toán' }}
        </button>
      </template>
    </Modal>

    <!-- Single Post Modal -->
    <Modal :show="postTarget !== null" @close="postTarget = null">
      <template #title>Duyệt bút toán</template>
      <div class="space-y-2 text-sm text-slate-600">
        <p>Xác nhận duyệt và hạch toán bút toán <strong>{{ postTarget?.code }}</strong>?</p>
        <p class="italic text-slate-500">{{ postTarget?.description }}</p>
        <p class="text-blue-600 bg-blue-50 px-3 py-2 rounded-lg">Sau khi duyệt, bút toán sẽ có hiệu lực và ảnh hưởng đến báo cáo kế toán.</p>
      </div>
      <template #footer>
        <button @click="postTarget = null" class="erp-btn-secondary">Hủy</button>
        <button @click="doPost" class="erp-btn-primary bg-green-600 hover:bg-green-700 active:bg-green-800">Duyệt & Hạch toán</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import Modal from '@/Components/Shared/Modal.vue';
import ExportExcelButton from '@/Components/Shared/ExportExcelButton.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ entries: Object, filters: Object, draftCount: Number });
const { hasPermission: can } = usePermission();
const { formatVnd } = useCurrency();

const search = ref(props.filters?.search ?? '');
const status = ref(props.filters?.status ?? '');
const from   = ref(props.filters?.from ?? '');
const to     = ref(props.filters?.to ?? '');

function applyFilters() {
  router.get(route('accounting.journal-entries.index'),
    { search: search.value, status: status.value, from: from.value, to: to.value },
    { preserveState: true }
  );
}

// Bulk approve
const showBulkApprove = ref(false);
const doBulkApprove = () => {
  router.post(route('accounting.journal-entries.bulk-approve'), {}, {
    onSuccess: () => { showBulkApprove.value = false; },
  });
};

// Delete (draft only)
const deleteTarget = ref(null);
const confirmDelete = (entry) => { deleteTarget.value = entry; };
const doDelete = () => {
  router.delete(route('accounting.journal-entries.destroy', deleteTarget.value.id), {
    onSuccess: () => { deleteTarget.value = null; },
  });
};

// Void (posted / reversed)
const voidTarget = ref(null);
const voidReason = ref('');
const confirmVoid = (entry) => { voidTarget.value = entry; voidReason.value = ''; };
const doVoid = () => {
  router.post(route('accounting.journal-entries.void', voidTarget.value.id),
    { void_reason: voidReason.value },
    { onSuccess: () => { voidTarget.value = null; } }
  );
};

// Single post
const postTarget = ref(null);
const confirmPost = (entry) => { postTarget.value = entry; };
const doPost = () => {
  router.post(route('accounting.journal-entries.post', postTarget.value.id), {}, {
    onSuccess: () => { postTarget.value = null; },
  });
};
</script>
