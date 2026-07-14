<template>
  <AppLayout>
    <div class="max-w-5xl space-y-6">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <Link :href="route('projects.projects.show', project.id)" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
          </Link>
          <div>
            <h1 class="text-2xl font-bold text-gray-900">HĐ {{ subcontract.contract_no }} — {{ subcontract.contractor_name }}</h1>
            <p class="text-sm text-gray-500 mt-0.5">{{ project.code }} — {{ project.name }}</p>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <span :class="['text-xs px-2.5 py-1 rounded-full font-medium',
            subcontract.status_color === 'green'  ? 'bg-green-100 text-green-700' :
            subcontract.status_color === 'blue'   ? 'bg-blue-100 text-blue-700' :
            subcontract.status_color === 'yellow' ? 'bg-yellow-100 text-yellow-700' :
            subcontract.status_color === 'red'    ? 'bg-red-100 text-red-600' : 'bg-gray-100 text-gray-600']">
            {{ subcontract.status_label }}
          </span>
          <Link v-if="can('projects.subcontracts.update') && subcontract.status === 'draft'"
            :href="route('projects.projects.subcontracts.edit', [project.id, subcontract.id])"
            class="text-xs border border-gray-300 px-3 py-1.5 rounded-lg hover:bg-gray-50">Sửa</Link>
          <button v-if="can('projects.subcontracts.approve') && subcontract.status === 'draft'"
            @click="approve" class="text-xs bg-primary-600 hover:bg-primary-700 text-white px-3 py-1.5 rounded-lg">Duyệt hợp đồng</button>
          <button v-if="can('projects.subcontracts.approve') && ['active','partially_accepted'].includes(subcontract.status)"
            @click="close" class="text-xs border border-gray-300 px-3 py-1.5 rounded-lg hover:bg-gray-50">Đóng hợp đồng</button>
          <button v-if="can('projects.subcontracts.cancel') && subcontract.status !== 'cancelled'"
            @click="cancelSubcontract" class="text-xs text-red-500 hover:underline px-2">Hủy</button>
        </div>
      </div>

      <!-- Tổng quan -->
      <div class="bg-white rounded-xl border border-gray-200 p-6 grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
        <div><p class="text-gray-500 text-xs">Giá trị HĐ</p><p class="font-semibold text-gray-900">{{ formatVnd(subcontract.total_amount) }}</p></div>
        <div><p class="text-gray-500 text-xs">Đã tạm ứng</p><p class="font-semibold text-gray-900">{{ formatVnd(subcontract.advance_amount) }}</p></div>
        <div><p class="text-gray-500 text-xs">Đã nghiệm thu</p><p class="font-semibold text-gray-900">{{ formatVnd(subcontract.accepted_total) }}</p></div>
        <div><p class="text-gray-500 text-xs">Đã thanh toán</p><p class="font-semibold text-gray-900">{{ formatVnd(subcontract.paid_total) }}</p></div>
        <div><p class="text-gray-500 text-xs">Còn phải trả</p><p class="font-semibold text-red-600">{{ formatVnd(subcontract.amount_due) }}</p></div>
        <div><p class="text-gray-500 text-xs">Giữ lại bảo hành</p><p class="font-semibold text-gray-900">{{ formatVnd(subcontract.retention_amount) }}</p></div>
        <div><p class="text-gray-500 text-xs">Loại</p><p class="text-gray-800">{{ typeLabel }}</p></div>
        <div><p class="text-gray-500 text-xs">Hạng mục</p><p class="text-gray-800">{{ costGroupLabel }}</p></div>
      </div>

      <!-- 3 action forms -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <!-- Tạm ứng -->
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <button @click="showAdvanceForm = !showAdvanceForm" class="text-sm font-semibold text-gray-700">+ Tạm ứng</button>
          <div v-if="showAdvanceForm" class="mt-3 space-y-2">
            <input v-model="advanceForm.advance_date" type="date" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs" />
            <input v-model="advanceForm.amount" type="number" placeholder="Số tiền" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs" />
            <select v-model="advanceForm.payment_method" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs">
              <option value="cash">Tiền mặt</option>
              <option value="bank">Ngân hàng</option>
            </select>
            <select v-if="advanceForm.payment_method === 'cash'" v-model="advanceForm.fund_id" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs">
              <option value="">-- Quỹ --</option>
              <option v-for="f in funds" :key="f.id" :value="f.id">{{ f.name }}</option>
            </select>
            <select v-else v-model="advanceForm.bank_account_id" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs">
              <option value="">-- TK ngân hàng --</option>
              <option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.bank_name }} - {{ b.account_number }}</option>
            </select>
            <button @click="submitAdvance" class="w-full bg-primary-600 hover:bg-primary-700 text-white px-3 py-1.5 rounded-lg text-xs font-medium">Ghi nhận tạm ứng</button>
          </div>
        </div>

        <!-- Nghiệm thu -->
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <button @click="showAcceptanceForm = !showAcceptanceForm" class="text-sm font-semibold text-gray-700">+ Nghiệm thu</button>
          <div v-if="showAcceptanceForm" class="mt-3 space-y-2">
            <input v-model="acceptanceForm.acceptance_no" type="text" placeholder="Số biên bản" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs" />
            <input v-model="acceptanceForm.acceptance_date" type="date" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs" />
            <textarea v-model="acceptanceForm.description" placeholder="Diễn giải" rows="1" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs"></textarea>
            <input v-model="acceptanceForm.amount_before_vat" type="number" placeholder="Giá trị trước VAT" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs" @input="computeAcceptanceVat" />
            <template v-if="subcontract.contractor_type === 'company'">
              <input v-model="acceptanceForm.vat_rate" type="number" placeholder="VAT %" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs" @input="computeAcceptanceVat" />
              <input v-model="acceptanceForm.invoice_no" type="text" placeholder="Số hóa đơn" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs" />
              <input v-model="acceptanceForm.invoice_date" type="date" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs" />
            </template>
            <button @click="submitAcceptance" class="w-full bg-primary-600 hover:bg-primary-700 text-white px-3 py-1.5 rounded-lg text-xs font-medium">Ghi nhận nghiệm thu</button>
          </div>
        </div>

        <!-- Thanh toán -->
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <button @click="showPaymentForm = !showPaymentForm" class="text-sm font-semibold text-gray-700">+ Thanh toán</button>
          <div v-if="showPaymentForm" class="mt-3 space-y-2">
            <input v-model="paymentForm.payment_date" type="date" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs" />
            <input v-model="paymentForm.amount" type="number" placeholder="Số tiền" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs" />
            <select v-model="paymentForm.payment_method" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs">
              <option value="cash">Tiền mặt</option>
              <option value="bank">Ngân hàng</option>
            </select>
            <select v-if="paymentForm.payment_method === 'cash'" v-model="paymentForm.fund_id" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs">
              <option value="">-- Quỹ --</option>
              <option v-for="f in funds" :key="f.id" :value="f.id">{{ f.name }}</option>
            </select>
            <select v-else v-model="paymentForm.bank_account_id" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs">
              <option value="">-- TK ngân hàng --</option>
              <option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.bank_name }} - {{ b.account_number }}</option>
            </select>
            <label v-if="subcontract.contractor_type !== 'company'" class="flex items-center gap-1.5 text-xs text-gray-600">
              <input type="checkbox" v-model="paymentForm.pit_withholding_enabled" />
              Khấu trừ thuế TNCN
            </label>
            <input v-if="paymentForm.pit_withholding_enabled" v-model="paymentForm.pit_rate" type="number" placeholder="Thuế suất TNCN %" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs" />
            <button @click="submitPayment" class="w-full bg-primary-600 hover:bg-primary-700 text-white px-3 py-1.5 rounded-lg text-xs font-medium">Ghi nhận thanh toán</button>
          </div>
        </div>
      </div>

      <!-- Danh sách nghiệm thu -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="p-4 border-b border-gray-100"><h2 class="text-sm font-semibold text-gray-700">Nghiệm thu</h2></div>
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-3 py-2 font-semibold text-gray-600">Số BB</th>
              <th class="text-left px-3 py-2 font-semibold text-gray-600">Ngày</th>
              <th class="text-right px-3 py-2 font-semibold text-gray-600">Trước VAT</th>
              <th class="text-right px-3 py-2 font-semibold text-gray-600">VAT</th>
              <th class="text-right px-3 py-2 font-semibold text-gray-600">Tổng</th>
              <th class="text-left px-3 py-2 font-semibold text-gray-600">Bút toán</th>
              <th class="text-left px-3 py-2 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-3 py-2" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="a in subcontract.acceptances" :key="a.id" :class="a.status === 'cancelled' ? 'opacity-50' : ''">
              <td class="px-3 py-2">{{ a.acceptance_no ?? '—' }}</td>
              <td class="px-3 py-2">{{ a.acceptance_date }}</td>
              <td class="px-3 py-2 text-right">{{ formatVnd(a.amount_before_vat) }}</td>
              <td class="px-3 py-2 text-right">{{ formatVnd(a.vat_amount) }}</td>
              <td class="px-3 py-2 text-right font-medium">{{ formatVnd(a.total_amount) }}</td>
              <td class="px-3 py-2 text-xs font-mono text-gray-500">#{{ a.journal_entry_id }}</td>
              <td class="px-3 py-2 text-xs">{{ a.status === 'posted' ? 'Đang hiệu lực' : 'Đã hủy' }}</td>
              <td class="px-3 py-2 text-right">
                <button v-if="a.status === 'posted' && can('projects.subcontracts.cancel')" @click="cancelAcceptance(a)" class="text-xs text-red-500 hover:underline">Hủy</button>
              </td>
            </tr>
            <tr v-if="!subcontract.acceptances?.length"><td colspan="8" class="px-3 py-6 text-center text-gray-400 text-xs">Chưa có nghiệm thu.</td></tr>
          </tbody>
        </table>
      </div>

      <!-- Danh sách tạm ứng + thanh toán -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="p-4 border-b border-gray-100"><h2 class="text-sm font-semibold text-gray-700">Tạm ứng</h2></div>
          <table class="min-w-full text-sm">
            <tbody class="divide-y divide-gray-100">
              <tr v-for="ad in subcontract.advances" :key="ad.id" :class="ad.status === 'cancelled' ? 'opacity-50' : ''">
                <td class="px-3 py-2">{{ ad.advance_date }}</td>
                <td class="px-3 py-2 text-right font-medium">{{ formatVnd(ad.amount) }}</td>
                <td class="px-3 py-2 text-xs">{{ ad.status === 'posted' ? 'OK' : 'Đã hủy' }}</td>
                <td class="px-3 py-2 text-right">
                  <button v-if="ad.status === 'posted' && can('projects.subcontracts.cancel')" @click="cancelAdvance(ad)" class="text-xs text-red-500 hover:underline">Hủy</button>
                </td>
              </tr>
              <tr v-if="!subcontract.advances?.length"><td colspan="4" class="px-3 py-6 text-center text-gray-400 text-xs">Chưa có tạm ứng.</td></tr>
            </tbody>
          </table>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="p-4 border-b border-gray-100"><h2 class="text-sm font-semibold text-gray-700">Thanh toán</h2></div>
          <table class="min-w-full text-sm">
            <tbody class="divide-y divide-gray-100">
              <tr v-for="p in subcontract.payments" :key="p.id" :class="p.status === 'cancelled' ? 'opacity-50' : ''">
                <td class="px-3 py-2">{{ p.payment_date }}</td>
                <td class="px-3 py-2 text-right font-medium">{{ formatVnd(p.amount) }}</td>
                <td class="px-3 py-2 text-xs">{{ p.status === 'posted' ? 'OK' : 'Đã hủy' }}</td>
                <td class="px-3 py-2 text-right">
                  <button v-if="p.status === 'posted' && can('projects.subcontracts.cancel')" @click="cancelPayment(p)" class="text-xs text-red-500 hover:underline">Hủy</button>
                </td>
              </tr>
              <tr v-if="!subcontract.payments?.length"><td colspan="4" class="px-3 py-6 text-center text-gray-400 text-xs">Chưa có thanh toán.</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Đính kèm -->
      <div class="bg-white rounded-xl border border-gray-200 p-4">
        <h2 class="text-sm font-semibold text-gray-700 mb-3">Đính kèm file</h2>
        <input type="file" multiple @change="onFileChange" class="text-xs mb-2" />
        <ul class="text-xs space-y-1">
          <li v-for="att in subcontract.attachments" :key="att.id" class="flex items-center justify-between">
            <a :href="`/storage/${att.file_path}`" target="_blank" class="text-primary-600 hover:underline">{{ att.file_name }}</a>
            <button @click="deleteAttachment(att)" class="text-red-400 hover:text-red-600">Xóa</button>
          </li>
        </ul>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  project:      Object,
  subcontract:  Object,
  funds:        { type: Array, default: () => [] },
  bankAccounts: { type: Array, default: () => [] },
});

const { hasPermission } = usePermission();
const can = hasPermission;
const { formatVnd } = useCurrency();

const typeLabel = computed(() => ({ company: 'Có hóa đơn (pháp nhân)', team: 'Đội nhóm', individual: 'Cá nhân' }[props.subcontract.contractor_type] ?? props.subcontract.contractor_type));
const costGroupLabel = computed(() => ({ subcontractor: 'Nhà thầu phụ', labor: 'Nhân công', equipment: 'Máy thi công', transport: 'Vận chuyển', other: 'Khác' }[props.subcontract.cost_group] ?? props.subcontract.cost_group));

// Advance
const showAdvanceForm = ref(false);
const advanceForm = ref({ advance_date: new Date().toISOString().slice(0, 10), amount: '', payment_method: 'cash', fund_id: '', bank_account_id: '' });
function submitAdvance() {
  router.post(route('projects.projects.subcontracts.advances.store', [props.project.id, props.subcontract.id]), advanceForm.value, {
    preserveScroll: true,
    onSuccess: () => { showAdvanceForm.value = false; advanceForm.value.amount = ''; },
  });
}

// Acceptance
const showAcceptanceForm = ref(false);
const acceptanceForm = ref({ acceptance_no: '', acceptance_date: new Date().toISOString().slice(0, 10), description: '', amount_before_vat: '', vat_rate: 10, vat_amount: 0, invoice_no: '', invoice_date: '' });
function computeAcceptanceVat() {
  const amount = parseFloat(acceptanceForm.value.amount_before_vat) || 0;
  const rate   = parseFloat(acceptanceForm.value.vat_rate) || 0;
  acceptanceForm.value.vat_amount = props.subcontract.contractor_type === 'company' && rate > 0 ? Math.round(amount * rate / 100) : 0;
}
function submitAcceptance() {
  router.post(route('projects.projects.subcontracts.acceptances.store', [props.project.id, props.subcontract.id]), acceptanceForm.value, {
    preserveScroll: true,
    onSuccess: () => { showAcceptanceForm.value = false; acceptanceForm.value.amount_before_vat = ''; },
  });
}
function cancelAcceptance(a) {
  const reason = prompt('Lý do hủy nghiệm thu?');
  if (!reason) return;
  router.delete(route('projects.projects.subcontracts.acceptances.cancel', [props.project.id, props.subcontract.id, a.id]), { data: { cancel_reason: reason }, preserveScroll: true });
}

// Payment
const showPaymentForm = ref(false);
const paymentForm = ref({ payment_date: new Date().toISOString().slice(0, 10), amount: '', payment_method: 'cash', fund_id: '', bank_account_id: '', pit_withholding_enabled: false, pit_rate: 10 });
function submitPayment() {
  router.post(route('projects.projects.subcontracts.payments.store', [props.project.id, props.subcontract.id]), paymentForm.value, {
    preserveScroll: true,
    onSuccess: () => { showPaymentForm.value = false; paymentForm.value.amount = ''; },
  });
}
function cancelAdvance(ad) {
  const reason = prompt('Lý do hủy tạm ứng?');
  if (!reason) return;
  router.delete(route('projects.projects.subcontracts.advances.cancel', [props.project.id, props.subcontract.id, ad.id]), { data: { cancel_reason: reason }, preserveScroll: true });
}
function cancelPayment(p) {
  const reason = prompt('Lý do hủy thanh toán?');
  if (!reason) return;
  router.delete(route('projects.projects.subcontracts.payments.cancel', [props.project.id, props.subcontract.id, p.id]), { data: { cancel_reason: reason }, preserveScroll: true });
}

// Contract-level actions
function approve() {
  router.post(route('projects.projects.subcontracts.approve', [props.project.id, props.subcontract.id]), {}, { preserveScroll: true });
}
function close() {
  router.post(route('projects.projects.subcontracts.close', [props.project.id, props.subcontract.id]), {}, { preserveScroll: true });
}
function cancelSubcontract() {
  const reason = prompt('Lý do hủy hợp đồng?');
  if (!reason) return;
  router.post(route('projects.projects.subcontracts.cancel', [props.project.id, props.subcontract.id]), { cancel_reason: reason }, { preserveScroll: true });
}

// Attachments (dùng route chung attachments.store/destroy)
function onFileChange(e) {
  const files = e.target.files;
  if (!files.length) return;
  const formData = new FormData();
  for (const f of files) formData.append('files[]', f);
  router.post(route('attachments.store', ['project_subcontract', props.subcontract.id]), formData, { preserveScroll: true });
}
function deleteAttachment(att) {
  router.delete(route('attachments.destroy', att.id), { preserveScroll: true });
}
</script>
