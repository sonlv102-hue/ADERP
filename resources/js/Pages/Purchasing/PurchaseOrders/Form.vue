<template>
  <AppLayout>
    <div class="max-w-5xl space-y-5">

      <!-- Page header -->
      <div class="flex items-center gap-3">
        <Link
          :href="purchaseOrder
            ? route('purchasing.purchase-orders.show', purchaseOrder.id)
            : route('purchasing.purchase-orders.index')"
          class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
        >
          <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <div>
          <p class="mb-0.5 text-xs font-medium text-gray-400">Mua hàng / Đơn mua</p>
          <h1 class="text-xl font-bold text-gray-900">
            {{ purchaseOrder ? 'Sửa đơn ' + purchaseOrder.code : 'Tạo đơn mua hàng' }}
          </h1>
        </div>
      </div>

      <form @submit.prevent="submit" class="space-y-5">

        <!-- ─── Section 1: Thông tin đơn mua ─── -->
        <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
          <div class="flex items-center gap-2.5 border-b border-gray-100 bg-gray-50/60 px-6 py-4">
            <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-100">
              <svg class="h-3.5 w-3.5 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
            </div>
            <h2 class="text-sm font-semibold text-gray-800">Thông tin đơn mua</h2>
          </div>

          <div class="p-6 space-y-5">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">

              <!-- Mã đơn -->
              <FormField label="Mã đơn" required :error="form.errors.code">
                <input
                  v-model="form.code"
                  type="text"
                  :disabled="!!purchaseOrder"
                  placeholder="VD: PO-2024-001"
                  class="w-full rounded-xl border px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow] placeholder:text-gray-300"
                  :class="form.errors.code
                    ? 'border-red-400 bg-red-50/40 focus:border-red-400 focus:ring-2 focus:ring-red-100'
                    : purchaseOrder
                      ? 'border-gray-200 bg-gray-50 text-gray-500 cursor-not-allowed'
                      : 'border-gray-200 bg-white focus:border-primary-500 focus:ring-2 focus:ring-primary-100'"
                />
              </FormField>

              <!-- Nhà cung cấp -->
              <FormField label="Nhà cung cấp" required :error="form.errors.supplier_id">
                <select
                  v-model="form.supplier_id"
                  class="w-full rounded-xl border px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow]"
                  :class="form.errors.supplier_id
                    ? 'border-red-400 bg-red-50/40 focus:border-red-400 focus:ring-2 focus:ring-red-100'
                    : 'border-gray-200 bg-white focus:border-primary-500 focus:ring-2 focus:ring-primary-100'"
                >
                  <option value="">— Chọn nhà cung cấp —</option>
                  <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.code }} – {{ s.name }}</option>
                </select>
              </FormField>

              <!-- Kho nhận hàng -->
              <FormField label="Kho nhận hàng" required :error="form.errors.warehouse_id">
                <select
                  v-model="form.warehouse_id"
                  class="w-full rounded-xl border px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow]"
                  :class="form.errors.warehouse_id
                    ? 'border-red-400 bg-red-50/40 focus:border-red-400 focus:ring-2 focus:ring-red-100'
                    : 'border-gray-200 bg-white focus:border-primary-500 focus:ring-2 focus:ring-primary-100'"
                >
                  <option value="">— Chọn kho —</option>
                  <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                </select>
              </FormField>

              <!-- Ngày đặt -->
              <FormField label="Ngày đặt" required :error="form.errors.order_date">
                <input
                  v-model="form.order_date"
                  type="date"
                  class="w-full rounded-xl border px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow]"
                  :class="form.errors.order_date
                    ? 'border-red-400 bg-red-50/40 focus:border-red-400 focus:ring-2 focus:ring-red-100'
                    : 'border-gray-200 bg-white focus:border-primary-500 focus:ring-2 focus:ring-primary-100'"
                />
              </FormField>

              <!-- Ngày dự kiến nhận -->
              <FormField label="Ngày dự kiến nhận" optional>
                <input
                  v-model="form.expected_date"
                  type="date"
                  class="w-full rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow] focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
                />
              </FormField>

              <!-- Loại hóa đơn đầu vào -->
              <FormField label="Loại hóa đơn đầu vào" optional>
                <select
                  v-model="form.invoice_type"
                  class="w-full rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow] focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
                >
                  <option v-for="t in invoiceTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
              </FormField>

              <!-- Đơn hàng bán liên kết -->
              <FormField label="Đơn hàng bán liên kết" optional>
                <select
                  v-model="form.order_id"
                  class="w-full rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow] focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
                >
                  <option :value="null">— Không liên kết —</option>
                  <option v-for="o in orders" :key="o.id" :value="o.id">{{ o.label }}</option>
                </select>
              </FormField>

              <!-- Dự án liên kết -->
              <FormField label="Dự án liên kết" optional>
                <select
                  v-model="form.project_id"
                  class="w-full rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow] focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
                >
                  <option :value="null">— Không liên kết —</option>
                  <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
                </select>
              </FormField>
            </div>

            <!-- Ghi chú -->
            <FormField label="Ghi chú" optional>
              <textarea
                v-model="form.notes"
                rows="2"
                placeholder="Yêu cầu đặc biệt, điều khoản..."
                class="w-full resize-none rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow] placeholder:text-gray-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
              />
            </FormField>
          </div>
        </div>

        <!-- ─── Section 2: Chi tiết hàng hóa ─── -->
        <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
          <div class="flex items-center justify-between border-b border-gray-100 bg-gray-50/60 px-6 py-4">
            <div class="flex items-center gap-2.5">
              <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-100">
                <svg class="h-3.5 w-3.5 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                </svg>
              </div>
              <h2 class="text-sm font-semibold text-gray-800">Chi tiết hàng hóa</h2>
              <span v-if="form.items.length"
                class="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-600">
                {{ form.items.length }}
              </span>
            </div>
            <button
              type="button"
              @click="addRow"
              class="inline-flex items-center gap-1.5 rounded-lg border border-primary-200 bg-primary-50 px-3 py-1.5 text-xs font-semibold text-primary-700 transition hover:bg-primary-100"
            >
              <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
              </svg>
              Thêm dòng
            </button>
          </div>

          <!-- Empty state -->
          <div v-if="!form.items.length" class="flex flex-col items-center gap-2.5 px-6 py-14 text-center">
            <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-100">
              <svg class="h-7 w-7 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                  d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
              </svg>
            </div>
            <div>
              <p class="text-sm font-medium text-gray-600">Chưa có hàng hóa nào</p>
              <p class="mt-0.5 text-xs text-gray-400">Nhấn "+ Thêm dòng" để thêm sản phẩm vào đơn mua.</p>
            </div>
          </div>

          <!-- Table -->
          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="border-b border-gray-100">
                <tr class="bg-gray-50/60">
                  <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-400">Sản phẩm</th>
                  <th class="w-16 px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-400">ĐVT</th>
                  <th class="w-20 px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-400">SL</th>
                  <th class="w-36 px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-400">Đơn giá</th>
                  <th class="w-24 px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-400">VAT</th>
                  <th class="w-36 px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-400">Thành tiền</th>
                  <th class="w-8 px-3 py-3" />
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-50">
                <tr v-for="(item, index) in form.items" :key="index"
                  class="transition-colors hover:bg-blue-50/20">
                  <td class="px-5 py-2.5">
                    <ProductSearch
                      :options="products"
                      v-model="item.product_id"
                      @select="onProductSelect(index, $event)"
                    />
                  </td>
                  <td class="px-3 py-2.5">
                    <input
                      :value="itemUnit(item.product_id)"
                      type="text"
                      readonly
                      tabindex="-1"
                      class="w-full rounded-lg border border-gray-100 bg-gray-50 px-2.5 py-1.5 text-xs text-gray-500 outline-none"
                    />
                  </td>
                  <td class="px-3 py-2.5">
                    <input
                      v-model.number="item.quantity"
                      type="number"
                      min="1"
                      class="w-full rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs text-right outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
                    />
                  </td>
                  <td class="px-3 py-2.5">
                    <input
                      v-model.number="item.unit_price"
                      type="number"
                      min="0"
                      step="any"
                      class="w-full rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs text-right outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
                    />
                  </td>
                  <td class="px-3 py-2.5">
                    <select
                      v-model.number="item.vat_rate"
                      class="w-full rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
                    >
                      <option :value="null">—</option>
                      <option :value="0">0%</option>
                      <option :value="5">5%</option>
                      <option :value="8">8%</option>
                      <option :value="10">10%</option>
                    </select>
                  </td>
                  <td class="px-3 py-2.5 text-right">
                    <p class="text-sm font-semibold text-gray-800">{{ formatVnd(itemTotalWithVat(item)) }}</p>
                    <p v-if="item.vat_rate" class="text-xs text-blue-500">+{{ item.vat_rate }}% VAT</p>
                  </td>
                  <td class="px-3 py-2.5 text-center">
                    <button
                      type="button"
                      @click="removeRow(index)"
                      class="rounded-lg p-1.5 text-gray-300 transition hover:bg-red-50 hover:text-red-500 focus:outline-none"
                      title="Xóa dòng"
                    >
                      <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                      </svg>
                    </button>
                  </td>
                </tr>
              </tbody>
              <tfoot class="border-t-2 border-gray-100 bg-gray-50/40">
                <tr>
                  <td colspan="5" class="px-5 py-2 text-right text-xs text-gray-400">Cộng hàng (chưa VAT)</td>
                  <td class="px-3 py-2 text-right text-sm text-gray-600">{{ formatVnd(subtotal) }}</td>
                  <td />
                </tr>
                <tr v-if="totalVat > 0">
                  <td colspan="5" class="px-5 py-2 text-right text-xs text-gray-400">Thuế VAT</td>
                  <td class="px-3 py-2 text-right text-sm font-medium text-blue-600">{{ formatVnd(totalVat) }}</td>
                  <td />
                </tr>
                <tr>
                  <td colspan="5" class="px-5 py-3.5 text-right text-sm font-bold text-gray-700">Tổng cộng</td>
                  <td class="px-3 py-3.5 text-right text-lg font-bold text-primary-700">{{ formatVnd(grandTotal) }}</td>
                  <td />
                </tr>
              </tfoot>
            </table>
          </div>

          <p v-if="form.errors.items" class="border-t border-red-100 bg-red-50 px-5 py-2.5 text-xs text-red-600">
            {{ form.errors.items }}
          </p>
        </div>

        <!-- Action bar -->
        <div class="flex items-center gap-3 pb-2">
          <button
            type="submit"
            :disabled="form.processing"
            class="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-60"
          >
            <svg v-if="form.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            {{ form.processing ? 'Đang lưu...' : (purchaseOrder ? 'Cập nhật đơn mua' : 'Tạo đơn mua hàng') }}
          </button>
          <Link
            :href="purchaseOrder
              ? route('purchasing.purchase-orders.show', purchaseOrder.id)
              : route('purchasing.purchase-orders.index')"
            class="rounded-xl border border-gray-200 px-5 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-50"
          >
            Hủy
          </Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import FormField from '@/Components/Shared/FormField.vue';
import ProductSearch from '@/Components/Shared/ProductSearch.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  nextCode: String,
  suppliers: Array,
  warehouses: Array,
  products: Array,
  projects: { type: Array, default: () => [] },
  orders: { type: Array, default: () => [] },
  prefillOrderId: { type: Number, default: null },
  invoiceTypes: Array,
  purchaseOrder: Object,
});

const { formatVnd } = useCurrency();

const today = new Date().toISOString().slice(0, 10);

const form = useForm({
  code:          props.purchaseOrder?.code          ?? props.nextCode ?? '',
  supplier_id:   props.purchaseOrder?.supplier_id   ?? '',
  warehouse_id:  props.purchaseOrder?.warehouse_id  ?? '',
  project_id:    props.purchaseOrder?.project_id    ?? null,
  order_id:      props.purchaseOrder?.order_id      ?? props.prefillOrderId ?? null,
  order_date:    props.purchaseOrder?.order_date     ?? today,
  expected_date: props.purchaseOrder?.expected_date  ?? '',
  notes:         props.purchaseOrder?.notes          ?? '',
  invoice_type:  props.purchaseOrder?.invoice_type   ?? 'vat',
  items:         props.purchaseOrder?.items          ?? [],
});

const addRow = () => {
  form.items.push({ product_id: '', quantity: 1, unit_price: 0, vat_rate: 10 });
};

const removeRow = (index) => {
  form.items.splice(index, 1);
};

const onProductSelect = (index, product) => {
  if (product) form.items[index].unit_price = product.cost_price ?? 0;
};

const itemUnit = (productId) => props.products.find(p => p.id === productId)?.unit ?? '';

const itemBase = (item) => (item.quantity || 0) * (item.unit_price || 0);
const itemVat  = (item) => Math.round(itemBase(item) * (item.vat_rate || 0) / 100);
const itemTotalWithVat = (item) => itemBase(item) + itemVat(item);

const subtotal = computed(() => form.items.reduce((s, i) => s + itemBase(i), 0));
const totalVat = computed(() => form.items.reduce((s, i) => s + itemVat(i), 0));
const grandTotal = computed(() => subtotal.value + totalVat.value);

const submit = () => {
  if (props.purchaseOrder) {
    form.put(route('purchasing.purchase-orders.update', props.purchaseOrder.id));
  } else {
    form.post(route('purchasing.purchase-orders.store'));
  }
};
</script>
