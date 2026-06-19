<template>
  <AppLayout>
    <div class="max-w-4xl mx-auto space-y-6">
      <div class="flex items-center gap-3">
        <Link :href="route('accounting.fixed-assets.index')" class="text-slate-400 hover:text-slate-600">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-slate-900">{{ asset ? 'Sửa TSCĐ' : 'Thêm TSCĐ mới' }}</h1>
      </div>

      <!-- Banner: pre-filled from purchase invoice -->
      <div v-if="prefill?.purchase_invoice_id" class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-sm text-blue-800">
        Thông tin đã được điền sẵn từ hóa đơn đầu vào. Kiểm tra và bổ sung các trường còn thiếu trước khi lưu.
      </div>

      <!-- Warning: under 30M -->
      <div v-if="underThresholdWarning" class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-800">
        <strong>Cảnh báo:</strong> Nguyên giá dưới 30.000.000 VND — tài sản này không đủ tiêu chuẩn ghi nhận TSCĐ theo TT45. Đề xuất ghi nhận CCDC (TK153) hoặc chi phí trả trước (TK242).
      </div>

      <form @submit.prevent="submit" class="space-y-6">
        <!-- Thông tin cơ bản -->
        <div class="bg-white rounded-xl border border-slate-200 p-6 space-y-4">
          <h2 class="font-semibold text-slate-800 text-base border-b border-slate-100 pb-2">Thông tin cơ bản</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="erp-label">Mã TSCĐ</label>
              <input v-model="form.code" class="erp-input w-full font-mono" placeholder="Tự sinh nếu để trống" />
            </div>
            <div>
              <label class="erp-label">Tên tài sản <span class="text-red-500">*</span></label>
              <input v-model="form.name" class="erp-input w-full" required />
            </div>
            <div>
              <label class="erp-label">Nhóm tài sản</label>
              <select v-model="form.category_id" @change="fillAccountsFromCategory" class="erp-input w-full">
                <option value="">— Chọn nhóm —</option>
                <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.code }} – {{ cat.name }}</option>
              </select>
            </div>
            <div>
              <label class="erp-label">Loại tài sản <span class="text-red-500">*</span></label>
              <select v-model="form.asset_type" class="erp-input w-full">
                <option v-for="t in assetTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
              </select>
            </div>
            <div>
              <label class="erp-label">Nguồn hình thành</label>
              <select v-model="form.source_type" class="erp-input w-full">
                <option value="">— Chọn —</option>
                <option v-for="s in sourceTypes" :key="s.value" :value="s.value">{{ s.label }}</option>
              </select>
            </div>
            <div>
              <label class="erp-label">Số serial / biển số / số khung</label>
              <input v-model="form.serial_number" class="erp-input w-full" />
            </div>
            <div class="col-span-2">
              <label class="erp-label">Mô tả / ghi chú</label>
              <textarea v-model="form.notes" class="erp-input w-full" rows="2" />
            </div>
          </div>
        </div>

        <!-- Thông tin mua -->
        <div class="bg-white rounded-xl border border-slate-200 p-6 space-y-4">
          <h2 class="font-semibold text-slate-800 text-base border-b border-slate-100 pb-2">Thông tin mua sắm</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="erp-label">Nhà cung cấp</label>
              <select v-model="form.supplier_id" class="erp-input w-full">
                <option value="">— Chọn NCC —</option>
                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.code }} – {{ s.name }}</option>
              </select>
            </div>
            <div>
              <label class="erp-label">Ngày hóa đơn</label>
              <input v-model="form.invoice_date" type="date" class="erp-input w-full" />
            </div>
            <div>
              <label class="erp-label">Ngày mua <span class="text-red-500">*</span></label>
              <input v-model="form.acquisition_date" type="date" class="erp-input w-full" required />
            </div>
            <div>
              <label class="erp-label">Ngày ghi tăng</label>
              <input v-model="form.recognition_date" type="date" class="erp-input w-full" />
            </div>
            <div>
              <label class="erp-label">Nguyên giá (chưa VAT) <span class="text-red-500">*</span></label>
              <input v-model.number="form.acquisition_cost" type="number" min="0" step="1" class="erp-input w-full" required @input="recalcTotal" />
            </div>
            <div>
              <label class="erp-label">Thuế VAT</label>
              <input v-model.number="form.vat_amount" type="number" min="0" step="1" class="erp-input w-full" @input="recalcTotal" />
            </div>
            <div>
              <label class="erp-label">Tổng thanh toán</label>
              <input :value="totalAmount" class="erp-input w-full bg-slate-50" readonly />
            </div>
            <div>
              <label class="erp-label">Giá trị tính khấu hao</label>
              <input v-model.number="form.depreciable_amount" type="number" min="0" step="1" class="erp-input w-full" />
              <p class="text-xs text-slate-400 mt-1">Mặc định bằng nguyên giá nếu để trống</p>
            </div>
          </div>
        </div>

        <!-- Khấu hao -->
        <div class="bg-white rounded-xl border border-slate-200 p-6 space-y-4">
          <h2 class="font-semibold text-slate-800 text-base border-b border-slate-100 pb-2">Thông tin khấu hao</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="erp-label">Ngày đưa vào sử dụng</label>
              <input v-model="form.placed_in_service_date" type="date" class="erp-input w-full" />
            </div>
            <div>
              <label class="erp-label">Thời gian khấu hao (tháng) <span class="text-red-500">*</span></label>
              <input v-model.number="form.useful_life_months" type="number" min="1" class="erp-input w-full" required />
              <p v-if="usefulLifeYears" class="text-xs text-slate-400 mt-1">= {{ usefulLifeYears }} năm</p>
            </div>
            <div>
              <label class="erp-label">Phương pháp KH</label>
              <select v-model="form.depreciation_method" class="erp-input w-full">
                <option value="straight_line">Đường thẳng</option>
              </select>
            </div>
            <div>
              <label class="erp-label">Hao mòn lũy kế đầu kỳ (số dư)</label>
              <input v-model.number="form.opening_accumulated_depreciation" type="number" min="0" step="1" class="erp-input w-full" />
            </div>
          </div>
          <div v-if="categoryLifeRange" class="bg-indigo-50 border border-indigo-200 rounded-lg p-3 text-sm text-indigo-700">
            Khung thời gian TT45 cho nhóm này: {{ categoryLifeRange }}
          </div>
          <div v-if="lifeRangeWarning" class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800">
            <strong>Cảnh báo TT45:</strong> {{ lifeRangeWarning }}
          </div>
        </div>

        <!-- Tài khoản kế toán -->
        <div class="bg-white rounded-xl border border-slate-200 p-6 space-y-4">
          <h2 class="font-semibold text-slate-800 text-base border-b border-slate-100 pb-2">Tài khoản kế toán</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="erp-label">TK nguyên giá</label>
              <select v-model="form.original_cost_account_code" class="erp-input w-full font-mono">
                <option v-for="ac in assetAccounts" :key="ac.code" :value="ac.code">{{ ac.code }} – {{ ac.name }}</option>
              </select>
            </div>
            <div>
              <label class="erp-label">TK hao mòn</label>
              <select v-model="form.accumulated_dep_account_code" class="erp-input w-full font-mono">
                <option v-for="ac in depAccounts" :key="ac.code" :value="ac.code">{{ ac.code }} – {{ ac.name }}</option>
              </select>
            </div>
            <div>
              <label class="erp-label">TK chi phí khấu hao</label>
              <select v-model="form.depreciation_expense_account_code" class="erp-input w-full font-mono">
                <option v-for="ac in expenseAccounts" :key="ac.code" :value="ac.code">{{ ac.code }} – {{ ac.name }}</option>
              </select>
            </div>
            <div>
              <label class="erp-label">TK thanh toán / công nợ</label>
              <select v-model="form.payable_account_code" class="erp-input w-full font-mono">
                <option v-for="ac in payableAccounts" :key="ac.code" :value="ac.code">{{ ac.code }} – {{ ac.name }}</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Vị trí / bộ phận / người sử dụng -->
        <div class="bg-white rounded-xl border border-slate-200 p-6 space-y-4">
          <h2 class="font-semibold text-slate-800 text-base border-b border-slate-100 pb-2">Vị trí & Bộ phận sử dụng</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="erp-label">Bộ phận sử dụng</label>
              <input v-model="form.department" class="erp-input w-full" placeholder="VD: Ban Giám đốc, Kinh doanh..." />
            </div>
            <div>
              <label class="erp-label">Người sử dụng</label>
              <input v-model="form.responsible_user" class="erp-input w-full" placeholder="VD: Nguyễn Văn A" />
            </div>
            <div>
              <label class="erp-label">Vị trí / địa điểm</label>
              <input v-model="form.location" class="erp-input w-full" />
            </div>
            <div>
              <label class="erp-label">Mục đích sử dụng</label>
              <input v-model="form.usage_purpose" class="erp-input w-full" placeholder="VD: Quản lý điều hành, đi thị trường..." />
            </div>
          </div>
        </div>

        <!-- Thuế TNDN / TT45 -->
        <div class="bg-white rounded-xl border border-slate-200 p-6 space-y-4">
          <h2 class="font-semibold text-slate-800 text-base border-b border-slate-100 pb-2">Thuế TNDN & TT45</h2>

          <div class="flex flex-wrap gap-6">
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input v-model="form.is_for_business" type="checkbox" class="erp-checkbox" />
              <span class="text-sm text-slate-700">Tài sản phục vụ sản xuất kinh doanh (chi phí hợp lệ TNDN)</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer select-none">
              <input v-model="form.is_sedan_under_9_seats" type="checkbox" class="erp-checkbox" @change="onSedanChange" />
              <span class="text-sm text-slate-700">Ô tô chở người từ 9 chỗ trở xuống (áp dụng giới hạn 1,6 tỷ)</span>
            </label>
          </div>

          <!-- Cảnh báo xe vượt 1,6 tỷ -->
          <div v-if="sedanOverCap" class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800">
            <strong>Lưu ý TT45:</strong> Nguyên giá {{ fmtVnd(form.acquisition_cost) }} vượt mức 1.600.000.000 VND.
            Phần vượt <strong>{{ fmtVnd(form.acquisition_cost - 1600000000) }}</strong> không được trừ khi tính thuế TNDN.
            <br />
            Nguyên giá được trừ: <strong>{{ fmtVnd(1600000000) }}</strong> —
            KH hàng tháng được trừ: <strong>{{ fmtVnd(taxDeductibleMonthly) }}</strong> —
            KH không được trừ: <strong>{{ fmtVnd(nonDeductibleMonthly) }}</strong>
          </div>

          <!-- Tóm tắt khi là xe nhưng không vượt cap -->
          <div v-else-if="form.is_sedan_under_9_seats && form.acquisition_cost > 0" class="bg-green-50 border border-green-200 rounded-lg p-3 text-sm text-green-800">
            Nguyên giá nằm trong mức giới hạn 1,6 tỷ — toàn bộ khấu hao {{ fmtVnd(taxDeductibleMonthly) }}/tháng được trừ thuế TNDN.
          </div>
        </div>

        <!-- Tuỳ chọn bút toán -->
        <div v-if="!asset" class="bg-white rounded-xl border border-slate-200 p-6">
          <label class="flex items-center gap-3 cursor-pointer">
            <input v-model="form.create_journal" type="checkbox" class="erp-checkbox" />
            <span class="text-sm text-slate-700">Tự động tạo bút toán ghi tăng TSCĐ (Dr 211x / Cr 331/111/112)</span>
          </label>
        </div>

        <div class="flex justify-end gap-3">
          <Link :href="route('accounting.fixed-assets.index')" class="erp-btn-secondary">Hủy</Link>
          <button type="submit" class="erp-btn-primary">
            {{ asset ? 'Lưu thay đổi' : 'Thêm TSCĐ' }}
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  asset: Object,
  prefill: Object,
  categories: Array,
  suppliers: Array,
  detailAccounts: Array,
  assetTypes: Array,
  sourceTypes: Array,
});

const p = props.prefill || {};
const form = ref({
  code: props.asset?.code || '',
  name: props.asset?.name || '',
  category_id: props.asset?.category_id || '',
  asset_type: props.asset?.asset_type || 'tangible',
  source_type: props.asset?.source_type || p.source_type || 'purchased',
  serial_number: props.asset?.serial_number || '',
  supplier_id: props.asset?.supplier_id || p.supplier_id || '',
  purchase_invoice_id: props.asset?.purchase_invoice_id || p.purchase_invoice_id || '',
  invoice_date: props.asset?.invoice_date || p.invoice_date || '',
  acquisition_date: props.asset?.acquisition_date || p.acquisition_date || '',
  recognition_date: props.asset?.recognition_date || '',
  placed_in_service_date: props.asset?.placed_in_service_date || '',
  acquisition_cost: props.asset?.acquisition_cost || p.acquisition_cost || 0,
  vat_amount: props.asset?.vat_amount || p.vat_amount || 0,
  depreciable_amount: props.asset?.depreciable_amount || 0,
  opening_accumulated_depreciation: props.asset?.opening_accumulated_depreciation || 0,
  useful_life_months: props.asset?.useful_life_months || 60,
  depreciation_method: props.asset?.depreciation_method || 'straight_line',
  original_cost_account_code: props.asset?.original_cost_account_code || '2111',
  accumulated_dep_account_code: props.asset?.accumulated_dep_account_code || '2141',
  depreciation_expense_account_code: props.asset?.depreciation_expense_account_code || '6421',
  payable_account_code: props.asset?.payable_account_code || '3311',
  department: props.asset?.department || '',
  responsible_user: props.asset?.responsible_user || '',
  usage_purpose: props.asset?.usage_purpose || '',
  is_for_business: props.asset?.is_for_business ?? true,
  is_sedan_under_9_seats: props.asset?.is_sedan_under_9_seats ?? false,
  tax_deductible_cost: props.asset?.tax_deductible_cost || null,
  location: props.asset?.location || '',
  notes: props.asset?.notes || '',
  create_journal: false,
});

const totalAmount = computed(() => (form.value.acquisition_cost || 0) + (form.value.vat_amount || 0));
const usefulLifeYears = computed(() => form.value.useful_life_months ? (form.value.useful_life_months / 12).toFixed(1) : null);
const underThresholdWarning = computed(() => form.value.acquisition_cost > 0 && form.value.acquisition_cost < 30000000);

// TT45 / xe ≤9 chỗ
const SEDAN_CAP = 1_600_000_000;
const sedanOverCap = computed(() => form.value.is_sedan_under_9_seats && form.value.acquisition_cost > SEDAN_CAP);
const taxDeductibleBase = computed(() => form.value.is_sedan_under_9_seats
  ? Math.min(form.value.acquisition_cost || 0, SEDAN_CAP)
  : (form.value.acquisition_cost || 0));
const taxDeductibleMonthly = computed(() => form.value.useful_life_months > 0
  ? Math.round(taxDeductibleBase.value / form.value.useful_life_months)
  : 0);
const nonDeductibleMonthly = computed(() => {
  if (!form.value.useful_life_months || !form.value.is_sedan_under_9_seats) return 0;
  const total = Math.round((form.value.acquisition_cost || 0) / form.value.useful_life_months);
  return Math.max(0, total - taxDeductibleMonthly.value);
});

// TT45 life range warning (soft — không block submit)
const lifeRangeWarning = computed(() => {
  if (!selectedCategory.value) return null;
  const cat = selectedCategory.value;
  const months = form.value.useful_life_months;
  if (!months) return null;
  if (cat.min_useful_life_months && months < cat.min_useful_life_months) {
    return `Thời gian ${months} tháng thấp hơn khung tối thiểu ${cat.min_useful_life_months} tháng theo TT45. Cần giải trình.`;
  }
  if (cat.max_useful_life_months && months > cat.max_useful_life_months) {
    return `Thời gian ${months} tháng cao hơn khung tối đa ${cat.max_useful_life_months} tháng theo TT45. Cần giải trình.`;
  }
  return null;
});

function fmtVnd(v) {
  return new Intl.NumberFormat('vi-VN').format(Math.round(v || 0));
}

const selectedCategory = computed(() => props.categories?.find(c => c.id === form.value.category_id));

const categoryLifeRange = computed(() => {
  if (!selectedCategory.value) return null;
  const cat = selectedCategory.value;
  if (!cat.min_useful_life_months && !cat.max_useful_life_months) return null;
  const min = cat.min_useful_life_months ? cat.min_useful_life_months + ' tháng' : '';
  const max = cat.max_useful_life_months ? cat.max_useful_life_months + ' tháng' : '';
  return min && max ? `${min} – ${max}` : (min || max);
});

// Filter accounts by type
const assetAccounts  = computed(() => props.detailAccounts?.filter(a => ['2111','2112','2113'].includes(a.code)) || []);
const depAccounts    = computed(() => props.detailAccounts?.filter(a => ['2141','2142','2143'].includes(a.code)) || []);
const expenseAccounts = computed(() => props.detailAccounts?.filter(a =>
  a.code.startsWith('154') ||   // WIP dự án
  a.code.startsWith('627') ||   // Chi phí sản xuất chung
  a.code.startsWith('641') ||   // Chi phí bán hàng (641, 6411...)
  a.code.startsWith('642')      // Chi phí QLDN (642, 6421, 6422...)
) || []);
const payableAccounts = computed(() => props.detailAccounts?.filter(a => ['1111','1121','3311'].includes(a.code)) || []);

function recalcTotal() {
  // total_amount = acquisition_cost + vat_amount (read-only computed)
}

function onSedanChange() {
  // tax_deductible_cost được tính lại ở server khi submit
  // không cần client-side state cho trường này
}

function fillAccountsFromCategory() {
  const cat = selectedCategory.value;
  if (!cat) return;
  if (cat.asset_account_code) form.value.original_cost_account_code = cat.asset_account_code;
  if (cat.depreciation_account_code) form.value.accumulated_dep_account_code = cat.depreciation_account_code;
  if (cat.expense_account_code) form.value.depreciation_expense_account_code = cat.expense_account_code;
}

function submit() {
  const payload = { ...form.value, total_amount: totalAmount.value };
  if (props.asset) {
    router.put(route('accounting.fixed-assets.update', props.asset.id), payload);
  } else {
    router.post(route('accounting.fixed-assets.store'), payload);
  }
}
</script>
