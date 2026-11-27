<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="erp-page-header">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Kiểm soát sai lệch tồn kho và kế toán</h1>
          <p class="text-sm text-gray-500 mt-0.5">Inventory &amp; Accounting Integrity Report — audit chỉ đọc dữ liệu nghiệp vụ, không tự sửa tồn kho/chứng từ/JE</p>
        </div>
        <div class="flex gap-2 flex-wrap items-center">
          <template v-if="can.audit">
            <select v-model="runForm.scope" class="erp-input w-full sm:w-auto" aria-label="Phạm vi kiểm tra">
              <option v-for="(l, k) in RUN_SCOPES" :key="k" :value="k">{{ l }}</option>
            </select>
            <input v-if="runForm.scope !== 'all'" v-model="runForm.value" class="erp-input w-full sm:w-44" :placeholder="RUN_HINT[runForm.scope]" />
            <button type="button" class="erp-btn-primary" :disabled="running" @click="runAudit">{{ running ? 'Đang kiểm tra...' : 'Kiểm tra' }}</button>
          </template>
          <a v-if="can.export" :href="route('reports.inventory_integrity.export', activeFilters)" class="erp-btn-secondary">Xuất Excel</a>
        </div>
      </div>

      <KpiPanel :kpis="kpis" :last-run="lastRun" :last-all-run="lastAllRun" :last-failed="lastFailed" @drill="drill" />
      <ReconciliationPanel v-if="lastAllRun?.reconciliation" :rec="lastAllRun.reconciliation" />

      <!-- Bộ lọc -->
      <div class="flex gap-2 flex-wrap items-center">
        <input v-model="f.search" class="erp-input w-full sm:w-56" placeholder="SKU, tên SP, chứng từ, nguyên nhân..." @keyup.enter="apply" />
        <input v-model="f.document" class="erp-input w-full sm:w-32" placeholder="Mã chứng từ" @keyup.enter="apply" />
        <select v-model="f.status" class="erp-input w-full sm:w-auto"><option value="open">Đang mở</option><option value="all">Tất cả</option>
          <option v-for="s in statuses" :key="s" :value="s">{{ STATUS_LABELS[s] }}</option></select>
        <select v-model="f.rule_code" class="erp-input w-full sm:w-auto"><option value="">Mọi nhóm sai lệch</option>
          <option v-for="r in rules" :key="r.code" :value="r.code">{{ RULE_LABELS[r.code] ?? r.code }}</option></select>
        <select v-model="f.category" class="erp-input w-full sm:w-auto"><option value="">Mọi phân loại</option>
          <option v-for="c in categories" :key="c" :value="c">{{ CATEGORY_LABELS[c] }}</option></select>
        <select v-model="f.severity" class="erp-input w-full sm:w-auto"><option value="">Mọi mức độ</option>
          <option v-for="(v, k) in SEVERITY" :key="k" :value="k">{{ v[0] }}</option></select>
        <select v-model="f.warehouse_id" class="erp-input w-full sm:w-auto"><option value="">Mọi kho</option>
          <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select>
        <div class="w-full sm:w-56"><RemoteSearchSelect v-model="f.product_id" :search-url="route('search.products')" placeholder="Sản phẩm" /></div>
        <div class="w-full sm:w-44"><RemoteSearchSelect v-model="f.project_id" :search-url="route('search.projects')" placeholder="Dự án" /></div>
        <select v-model="f.assignee_id" class="erp-input w-full sm:w-auto"><option value="">Mọi người phụ trách</option>
          <option v-for="u in assignees" :key="u.id" :value="u.id">{{ u.name }}</option></select>
        <button type="button" class="erp-btn-primary" @click="apply">Lọc</button>
        <button type="button" class="erp-btn-secondary" @click="reset">Bỏ lọc</button>
      </div>
      <p v-if="filters.group || filters.bucket" class="text-sm text-primary-700">
        Đang xem: {{ KPI_GROUP_LABELS[filters.group] ?? BUCKET_LABELS[filters.bucket] }}
      </p>

      <div v-if="can.assign && selected.length" class="flex flex-wrap gap-2 items-center bg-primary-50 border border-primary-100 rounded-lg p-3 text-sm">
        <span class="font-medium">Đã chọn {{ selected.length }} vấn đề</span>
        <select v-model="assign.assignee_id" class="erp-input w-full sm:w-48"><option :value="null">— Người phụ trách —</option>
          <option v-for="u in assignees" :key="u.id" :value="u.id">{{ u.name }}</option></select>
        <select v-model="assign.category" class="erp-input w-full sm:w-40"><option :value="null">Giữ phân loại</option>
          <option v-for="c in categories" :key="c" :value="c">{{ CATEGORY_LABELS[c] }}</option></select>
        <input v-model="assign.due_date" type="date" class="erp-input w-full sm:w-40" aria-label="Thời hạn" />
        <input v-model="assign.request_note" class="erp-input w-full sm:w-72" placeholder="Nội dung yêu cầu" />
        <button type="button" class="erp-btn-primary" @click="doAssign">Giao việc</button>
      </div>

      <IssueTable v-model="selected" :rows="issues.data" :selectable="can.assign" :sort="filters.sort" :direction="filters.direction"
        @open="i => openId = i.id" @sort="sortBy" />
      <Pagination :links="issues.links" :meta="issues" />

      <details class="bg-white rounded-xl border border-gray-200 p-4 text-sm">
        <summary class="font-semibold cursor-pointer">Phạm vi và quy tắc audit ({{ rules.length }} quy tắc)</summary>
        <RuleCatalog :rules="rules" />
      </details>
    </div>
    <IssueDetailModal :issue-id="openId" :can="can" :manual-statuses="manualStatuses" @close="openId = null" />
  </AppLayout>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/Layout/AppLayout.vue'
import Pagination from '@/Components/Shared/Pagination.vue'
import RemoteSearchSelect from '@/Components/Shared/RemoteSearchSelect.vue'
import KpiPanel from './Partials/KpiPanel.vue'
import IssueTable from './Partials/IssueTable.vue'
import IssueDetailModal from './Partials/IssueDetailModal.vue'
import RuleCatalog from './Partials/RuleCatalog.vue'
import ReconciliationPanel from './Partials/ReconciliationPanel.vue'
import { RULE_LABELS, STATUS_LABELS, CATEGORY_LABELS, SEVERITY, KPI_GROUP_LABELS, BUCKET_LABELS } from './labels'

const props = defineProps({
  issues: Object, kpis: Object, lastRun: Object, lastAllRun: Object, lastFailed: Object, filters: Object, rules: Array,
  warehouses: Array, assignees: Array, statuses: Array, manualStatuses: Array, categories: Array, can: Object,
})

const RUN_SCOPES = { all: 'Toàn hệ thống', product: 'Một sản phẩm', warehouse: 'Theo kho', project: 'Theo dự án', document: 'Theo chứng từ' }
const RUN_HINT = { product: 'Mã/ID sản phẩm', warehouse: 'ID kho', project: 'Mã/ID dự án', document: 'Mã chứng từ' }
const FIELDS = ['search', 'document', 'status', 'rule_code', 'category', 'severity', 'warehouse_id', 'product_id', 'project_id', 'assignee_id', 'group', 'bucket', 'sort', 'direction']

const f = reactive(Object.fromEntries(FIELDS.map(k => [k, props.filters?.[k] ?? (k === 'status' ? 'open' : '')])))
const runForm = reactive({ scope: 'all', value: '' })
const running = ref(false)
const selected = ref([])
const openId = ref(null)
const assign = reactive({ assignee_id: null, category: null, due_date: '', request_note: '' })

const activeFilters = computed(() => Object.fromEntries(Object.entries(props.filters ?? {}).filter(([, v]) => v !== '' && v !== null)))
const clean = (o) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== '' && v !== null && v !== undefined))

function go(params) {
  selected.value = []
  router.get(route('reports.inventory_integrity.index'), clean(params), { preserveState: true, preserveScroll: true })
}
const apply = () => go({ ...f, group: '', bucket: '' })
const reset = () => go({ status: 'open' })
const drill = (p) => go({ ...p })
const sortBy = (col) => go({ ...activeFilters.value, sort: col, direction: props.filters?.sort === col && props.filters?.direction !== 'desc' ? 'desc' : 'asc' })

function runAudit() {
  running.value = true
  router.post(route('reports.inventory_integrity.run'), { scope: runForm.scope, value: runForm.scope === 'all' ? null : runForm.value },
    { preserveScroll: true, onFinish: () => { running.value = false } })
}

function doAssign() {
  router.post(route('reports.inventory_integrity.assign'), { ids: selected.value, ...clean(assign) }, {
    preserveScroll: true, onSuccess: () => { selected.value = []; Object.assign(assign, { assignee_id: null, category: null, due_date: '', request_note: '' }) },
  })
}
</script>
