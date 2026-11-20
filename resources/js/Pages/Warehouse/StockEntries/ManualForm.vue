<template>
  <AppLayout>
    <div class="w-full max-w-6xl space-y-5">
      <div class="flex items-center gap-3">
        <Link :href="backUrl" class="text-gray-500 hover:text-gray-700" title="Quay lại">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ entry ? 'Sửa phiếu nhập kho thủ công' : 'Phiếu nhập kho thủ công' }}</h1>
      </div>

      <div class="flex items-start gap-3 bg-amber-50 border border-amber-300 rounded-xl px-5 py-3 text-xs text-amber-800">
        Phiếu này không liên kết đơn mua hàng. Khi xác nhận, hệ thống ghi tăng tồn kho và tự hạch toán:
        <b class="ml-1">{{ form.kind === 'purchase' ? 'Nợ 156/152/153 + 1331 / Có 331 (NCC)' : 'Nợ 156/152/153 / Có TK đối ứng đã chọn' }}</b>.
        <span v-if="form.kind === 'purchase'" class="ml-1">Khi nhập hóa đơn đầu vào của lô hàng này, chọn loại hóa đơn
          "Hàng hóa / NVL / CCDC" để không ghi công nợ lần 2.</span>
      </div>

      <form @submit.prevent="submit" class="space-y-5">
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
          <FormField label="Loại nhập" required :error="form.errors.kind">
            <div class="flex flex-wrap gap-4 text-sm">
              <label class="flex items-center gap-2"><input type="radio" value="purchase" v-model="form.kind" /> Mua hàng của NCC (công nợ 331, có VAT)</label>
              <label class="flex items-center gap-2"><input type="radio" value="other" v-model="form.kind" /> Nhập khác (hàng tặng, thừa, góp vốn... — không VAT)</label>
            </div>
          </FormField>

          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <FormField label="Mã phiếu" required :error="form.errors.code">
              <input v-model="form.code" type="text" class="erp-input" :class="{ 'erp-input-error': form.errors.code }" />
            </FormField>
            <FormField label="Ngày nhập" required :error="form.errors.entry_date">
              <input v-model="form.entry_date" type="date" class="erp-input" :class="{ 'erp-input-error': form.errors.entry_date }" />
            </FormField>
            <FormField label="Kho nhập" required :error="form.errors.warehouse_id">
              <select v-model="form.warehouse_id" class="erp-input" :class="{ 'erp-input-error': form.errors.warehouse_id }">
                <option :value="null">-- Chọn kho --</option>
                <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
              </select>
            </FormField>
            <FormField label="Nhà cung cấp" :required="form.kind === 'purchase'" :optional="form.kind === 'other'" :error="form.errors.supplier_id">
              <RemoteSearchSelect v-model="form.supplier_id" :display-text="supplierName" :search-url="route('search.suppliers')"
                placeholder="Tìm nhà cung cấp..." :has-error="!!form.errors.supplier_id"
                @change="opt => supplierName = opt?.label ?? ''" />
            </FormField>
            <FormField v-if="form.kind === 'other'" label="TK đối ứng (bên Có)" required :error="form.errors.credit_account_code"
              hint="VD: 711 hàng tặng/biếu, 3381 hàng thừa chờ xử lý, 4111 góp vốn">
              <RemoteSearchSelect v-model="form.credit_account_code" :display-text="accountName" :search-url="route('search.account-codes')"
                :extra-params="{ detail_only: 1 }" placeholder="Tìm tài khoản..." :has-error="!!form.errors.credit_account_code"
                @change="opt => accountName = opt ? `${opt.code} - ${opt.label}` : ''" />
            </FormField>
          </div>

          <FormField label="Ghi chú / lý do nhập" :error="form.errors.notes">
            <textarea v-model="form.notes" rows="2" class="erp-input" />
          </FormField>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
          <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between flex-wrap gap-y-2">
            <h2 class="text-base font-semibold text-gray-800">Chi tiết hàng hóa nhập</h2>
            <button type="button" @click="addLine" class="erp-btn-secondary erp-btn-sm">+ Thêm dòng</button>
          </div>
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="text-left px-4 py-3 font-semibold text-gray-600 min-w-[260px]">Sản phẩm</th>
                <th class="text-right px-3 py-3 font-semibold text-gray-600">Số lượng</th>
                <th class="text-right px-3 py-3 font-semibold text-gray-600">Đơn giá (chưa VAT)</th>
                <th class="text-center px-3 py-3 font-semibold text-gray-600">% VAT</th>
                <th class="text-left px-3 py-3 font-semibold text-gray-600 min-w-[200px]">Serial (mỗi dòng 1 số)</th>
                <th class="text-right px-3 py-3 font-semibold text-gray-600">Thành tiền</th>
                <th class="px-3 py-3"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="(item, i) in form.items" :key="item.key" class="align-top">
                <td class="px-4 py-3">
                  <RemoteSearchSelect v-model="item.product_id" :display-text="item.product_name" :search-url="route('search.products')"
                    placeholder="Tìm sản phẩm..." :has-error="!!form.errors[`items.${i}.product_id`]"
                    @change="opt => onProduct(item, opt)" />
                  <p v-if="form.errors[`items.${i}.product_id`]" class="mt-1 text-xs text-red-600">{{ form.errors[`items.${i}.product_id`] }}</p>
                </td>
                <td class="px-3 py-3">
                  <input v-model.number="item.quantity" type="number" min="1" class="erp-input w-24 text-right"
                    :class="{ 'erp-input-error': form.errors[`items.${i}.quantity`] }" />
                  <p class="text-xs text-gray-400 mt-1">{{ item.unit }}</p>
                </td>
                <td class="px-3 py-3">
                  <input v-model.number="item.unit_price" type="number" min="0" step="any" class="erp-input w-36 text-right"
                    :class="{ 'erp-input-error': form.errors[`items.${i}.unit_price`] }" />
                </td>
                <td class="px-3 py-3 text-center">
                  <select v-model.number="item.tax_rate" :disabled="form.kind === 'other'" class="erp-input w-20">
                    <option v-for="r in [0, 5, 8, 10]" :key="r" :value="r">{{ r }}%</option>
                  </select>
                </td>
                <td class="px-3 py-3">
                  <textarea v-model="item.serial_text" rows="2" class="erp-input font-mono text-xs" placeholder="Không bắt buộc" />
                  <p v-if="form.errors[`items.${i}.serials`]" class="mt-1 text-xs text-red-600">{{ form.errors[`items.${i}.serials`] }}</p>
                </td>
                <td class="px-3 py-3 text-right font-medium text-gray-900 whitespace-nowrap">{{ formatVnd(lineTotal(item)) }}</td>
                <td class="px-3 py-3 text-right">
                  <button type="button" @click="form.items.splice(i, 1)" class="text-red-600 hover:text-red-800 text-xs font-medium"
                    title="Xóa dòng" :disabled="form.items.length === 1">Xóa</button>
                </td>
              </tr>
            </tbody>
            <tfoot class="bg-gray-50 border-t border-gray-200">
              <tr>
                <td colspan="5" class="px-4 py-3 text-right font-semibold text-gray-700">Tổng cộng:</td>
                <td class="px-3 py-3 text-right font-bold text-gray-900 whitespace-nowrap">{{ formatVnd(grandTotal) }}</td>
                <td></td>
              </tr>
            </tfoot>
          </table>
          <p v-if="form.errors.items" class="px-5 py-2 text-xs text-red-600">{{ form.errors.items }}</p>
        </div>

        <div class="flex gap-3">
          <button type="submit" :disabled="form.processing" class="erp-btn-primary">
            {{ form.processing ? 'Đang lưu...' : entry ? 'Cập nhật phiếu nhập' : 'Tạo phiếu nhập (nháp)' }}
          </button>
          <Link :href="backUrl" class="erp-btn-secondary">Hủy</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import FormField from '@/Components/Shared/FormField.vue';
import RemoteSearchSelect from '@/Components/Shared/RemoteSearchSelect.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  nextCode: String,
  warehouses: Array,
  entry: { type: Object, default: null },
});

const { formatVnd } = useCurrency();
let seq = 0;
const newLine = (src = {}) => ({
  key: ++seq,
  product_id: src.product_id ?? null,
  product_name: src.product_name ?? '',
  unit: src.unit ?? '',
  quantity: src.quantity ?? 1,
  unit_price: src.unit_price ?? 0,
  tax_rate: src.tax_rate ?? 10,
  serial_text: (src.serials ?? []).join('\n'),
});

const e = props.entry;
const supplierName = ref(e?.supplier_name ?? '');
const accountName = ref(e?.credit_account_name ?? '');
const backUrl = computed(() => e ? route('warehouse.stock-entries.show', e.id) : route('warehouse.stock-entries.index'));

const form = useForm({
  kind: e?.kind ?? 'purchase',
  code: props.nextCode ?? '',
  entry_date: e?.entry_date ?? new Date().toISOString().slice(0, 10),
  warehouse_id: e?.warehouse_id ?? (props.warehouses.length === 1 ? props.warehouses[0].id : null),
  supplier_id: e?.supplier_id ?? null,
  credit_account_code: e?.kind === 'other' ? e.credit_account_code : null,
  notes: e?.notes ?? '',
  items: e?.items?.length ? e.items.map(newLine) : [newLine()],
});

// Nhập khác không có hóa đơn GTGT → VAT 0
watch(() => form.kind, k => { if (k === 'other') form.items.forEach(it => { it.tax_rate = 0; }); });

const addLine = () => form.items.push(newLine({ tax_rate: form.kind === 'other' ? 0 : 10 }));

function onProduct(item, opt) {
  item.product_name = opt ? `${opt.code} - ${opt.label}` : '';
  item.unit = opt?.unit ?? '';
  if (opt && form.kind === 'purchase') item.tax_rate = [0, 5, 8, 10].includes(opt.vat_percent) ? opt.vat_percent : 10;
}

const lineTotal = it => Math.round((it.quantity || 0) * (it.unit_price || 0) * (1 + (it.tax_rate || 0) / 100));
const grandTotal = computed(() => form.items.reduce((s, it) => s + lineTotal(it), 0));

function submit() {
  form.transform(d => ({
    ...d,
    credit_account_code: d.kind === 'other' ? d.credit_account_code : null,
    items: d.items.map(({ product_id, quantity, unit_price, tax_rate, serial_text }) => ({
      product_id, quantity, unit_price, tax_rate,
      serials: serial_text.split(/[\r\n,;]+/).map(s => s.trim()).filter(Boolean),
    })),
  }));
  if (e) form.put(route('warehouse.stock-entries.manual.update', e.id));
  else form.post(route('warehouse.stock-entries.manual.store'));
}
</script>
