<template>
  <AppLayout>
    <div class="max-w-5xl space-y-5">

      <!-- Page header -->
      <div class="flex items-center gap-3">
        <Link
          :href="order ? route('sales.orders.show', order.id) : route('sales.orders.index')"
          class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
        >
          <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <div>
          <p class="mb-0.5 text-xs font-medium text-gray-400">Bán hàng / Đơn hàng</p>
          <h1 class="text-xl font-bold text-gray-900">
            {{ order ? 'Sửa đơn ' + order.code : 'Tạo đơn hàng mới' }}
          </h1>
        </div>
      </div>

      <!-- FDI Banner -->
      <div v-if="selectedCustomerIsFdi"
        class="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4">
        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-amber-100">
          <svg class="h-4 w-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
          </svg>
        </div>
        <div>
          <p class="text-sm font-semibold text-amber-900">Khách hàng FDI — Yêu cầu khai báo hải quan</p>
          <p class="mt-0.5 text-xs leading-relaxed text-amber-700">
            Doanh nghiệp có vốn đầu tư nước ngoài. Sau khi tạo đơn, cần hoàn thành
            <strong>thủ tục khai báo hải quan</strong> và đính kèm tờ khai trước khi giao hàng.
          </p>
        </div>
      </div>

      <!-- Supplementary Banner -->
      <div v-if="supplementaryFor"
        class="flex items-center gap-3 rounded-2xl border border-orange-200 bg-orange-50 px-5 py-3.5">
        <svg class="h-4 w-4 shrink-0 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <p class="text-sm text-orange-800">
          Đây là <strong>đơn bổ sung</strong> cho đơn
          <strong>{{ supplementaryFor.code }}</strong> ({{ supplementaryFor.customer_name }}).
          Khi hoàn thành, cảnh báo xuất kho vượt sẽ tự động được giải quyết.
        </p>
      </div>

      <form @submit.prevent="submit" class="space-y-5">

        <!-- ─── Section 1: Thông tin đơn hàng ─── -->
        <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
          <div class="flex items-center gap-2.5 border-b border-gray-100 bg-gray-50/60 px-6 py-4">
            <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-100">
              <svg class="h-3.5 w-3.5 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
            </div>
            <h2 class="text-sm font-semibold text-gray-800">Thông tin đơn hàng</h2>
          </div>

          <div class="p-6 space-y-5">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">

              <!-- Mã đơn hàng -->
              <FormField label="Mã đơn hàng" required :error="form.errors.code">
                <input
                  v-model="form.code"
                  type="text"
                  :readonly="!!order"
                  placeholder="VD: SO-2024-001"
                  class="w-full rounded-xl border px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow] placeholder:text-gray-300"
                  :class="form.errors.code
                    ? 'border-red-400 bg-red-50/40 focus:border-red-400 focus:ring-2 focus:ring-red-100'
                    : order
                      ? 'border-gray-200 bg-gray-50 text-gray-500 cursor-default'
                      : 'border-gray-200 bg-white focus:border-primary-500 focus:ring-2 focus:ring-primary-100'"
                />
              </FormField>

              <!-- Khách hàng -->
              <FormField label="Khách hàng" required :error="form.errors.customer_id">
                <RemoteSearchSelect
                  v-model="form.customer_id"
                  :search-url="route('search.customers')"
                  :display-text="initialCustomerDisplay"
                  placeholder="— Chọn khách hàng —"
                  :has-error="!!form.errors.customer_id"
                  @change="onCustomerChange"
                />
              </FormField>

              <!-- Ngày đặt hàng -->
              <FormField label="Ngày đặt hàng" required :error="form.errors.order_date">
                <input
                  v-model="form.order_date"
                  type="date"
                  class="w-full rounded-xl border px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow]"
                  :class="form.errors.order_date
                    ? 'border-red-400 bg-red-50/40 focus:border-red-400 focus:ring-2 focus:ring-red-100'
                    : 'border-gray-200 bg-white focus:border-primary-500 focus:ring-2 focus:ring-primary-100'"
                />
              </FormField>

              <!-- Ngày giao dự kiến -->
              <FormField label="Ngày giao dự kiến" optional>
                <input
                  v-model="form.expected_delivery"
                  type="date"
                  class="w-full rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow] focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
                />
              </FormField>
            </div>

            <!-- Báo giá liên kết -->
            <FormField
              label="Báo giá liên kết"
              optional
              hint="Chọn báo giá để tự động điền khách hàng và danh sách hàng hoá."
            >
              <select
                v-model="form.quotation_id"
                @change="onQuotationChange"
                class="w-full rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow] focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
              >
                <option :value="null">— Không liên kết —</option>
                <option v-for="q in quotations" :key="q.id" :value="q.id">{{ q.code }}</option>
              </select>
              <div v-if="form.quotation_id" class="mt-2 flex items-center gap-1.5 text-xs font-medium text-green-700">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                </svg>
                Đã liên kết — khách hàng và hàng hoá đã được điền tự động.
              </div>
            </FormField>

            <!-- Ghi chú -->
            <FormField label="Ghi chú" optional>
              <textarea
                v-model="form.notes"
                rows="2"
                placeholder="Ghi chú nội bộ, yêu cầu đặc biệt..."
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
              <h2 class="text-sm font-semibold text-gray-800">Chi tiết hàng hóa / dịch vụ</h2>
              <span
                v-if="form.items.length"
                class="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-600"
              >
                {{ form.items.length }}
              </span>
            </div>
            <div class="flex items-center gap-2">
              <button
                type="button"
                @click="addRow('product')"
                class="inline-flex items-center gap-1.5 rounded-lg border border-primary-200 bg-primary-50 px-3 py-1.5 text-xs font-semibold text-primary-700 transition hover:bg-primary-100"
              >
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                </svg>
                Sản phẩm
              </button>
              <button
                type="button"
                @click="addRow('service')"
                class="inline-flex items-center gap-1.5 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100"
              >
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                </svg>
                Dịch vụ
              </button>
            </div>
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
              <p class="mt-0.5 text-xs text-gray-400">Nhấn "+ Sản phẩm" hoặc "+ Dịch vụ" để thêm vào đơn.</p>
            </div>
          </div>

          <!-- Table -->
          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="border-b border-gray-100">
                <tr class="bg-gray-50/60">
                  <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-400">
                    Sản phẩm / Dịch vụ
                  </th>
                  <th class="w-14 px-2 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-400">ĐVT</th>
                  <th class="w-14 px-2 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-400">SL</th>
                  <th class="w-32 px-2 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-400">Đơn giá</th>
                  <th class="w-24 px-2 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-400">VAT</th>
                  <th class="w-16 px-2 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-400">CK%</th>
                  <th class="w-36 px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-400">Thành tiền</th>
                  <th class="w-8 px-2 py-3" />
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-50">
                <tr
                  v-for="(item, idx) in form.items"
                  :key="idx"
                  class="group transition-colors hover:bg-blue-50/20"
                >
                  <!-- Sản phẩm / Dịch vụ -->
                  <td class="px-4 py-2.5">
                    <template v-if="item._type === 'product'">
                      <ProductSearch
                        v-model="item.product_id"
                        :display-text="item._productDisplay"
                        @select="p => onProductSelect(idx, p)"
                      />
                    </template>
                    <template v-else>
                      <RemoteSearchSelect
                        v-model="item.service_id"
                        :search-url="route('search.services')"
                        :display-text="item._serviceDisplay"
                        placeholder="— Tìm dịch vụ —"
                        @change="opt => onServiceSelect(idx, opt)"
                      />
                    </template>
                  </td>

                  <!-- ĐVT -->
                  <td class="px-2 py-2.5">
                    <input
                      :value="item.unit"
                      type="text"
                      readonly
                      tabindex="-1"
                      class="w-full rounded-lg border border-gray-100 bg-gray-50 px-2.5 py-1.5 text-xs text-gray-500 outline-none"
                    />
                  </td>

                  <!-- Số lượng -->
                  <td class="px-2 py-2.5">
                    <input
                      v-model.number="item.quantity"
                      type="number"
                      min="1"
                      step="any"
                      class="w-full rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs text-right outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
                    />
                  </td>

                  <!-- Đơn giá -->
                  <td class="px-2 py-2.5">
                    <input
                      v-model.number="item.unit_price"
                      type="number"
                      min="0"
                      step="any"
                      class="w-full rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs text-right outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
                    />
                  </td>

                  <!-- VAT -->
                  <td class="px-2 py-2.5">
                    <select
                      v-model.number="item.vat_rate"
                      class="w-full rounded-lg border px-2.5 py-1.5 text-xs outline-none transition focus:ring-2"
                      :class="item.vat_rate === null
                        ? 'border-amber-400 bg-amber-50 text-amber-800 focus:border-amber-400 focus:ring-amber-100'
                        : 'border-gray-200 bg-white focus:border-primary-500 focus:ring-primary-100'"
                    >
                      <option :value="null">—</option>
                      <option :value="0">0%</option>
                      <option :value="5">5%</option>
                      <option :value="8">8%</option>
                      <option :value="10">10%</option>
                    </select>
                    <p v-if="item.vat_rate === null" class="mt-0.5 text-center text-xs text-amber-600">chưa chọn</p>
                  </td>

                  <!-- Chiết khấu % -->
                  <td class="px-2 py-2.5">
                    <input
                      v-model.number="item.discount_percent"
                      type="number"
                      min="0"
                      max="100"
                      step="0.01"
                      placeholder="0"
                      class="w-full rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs text-right outline-none transition placeholder:text-gray-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
                    />
                  </td>

                  <!-- Thành tiền -->
                  <td class="px-3 py-2.5 text-right">
                    <p class="text-sm font-semibold text-gray-800">{{ formatVnd(itemTotalWithVat(item)) }}</p>
                    <p v-if="item.discount_percent > 0" class="text-xs text-green-600">–{{ item.discount_percent }}% CK</p>
                    <p v-if="item.vat_rate" class="text-xs text-blue-500">+{{ item.vat_rate }}% VAT</p>
                  </td>

                  <!-- Xóa -->
                  <td class="px-2 py-2.5 text-center">
                    <button
                      type="button"
                      @click="removeRow(idx)"
                      class="rounded-lg p-1.5 text-gray-300 transition hover:bg-red-50 hover:text-red-500 focus:text-red-500 focus:outline-none"
                      title="Xóa dòng"
                    >
                      <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                      </svg>
                    </button>
                  </td>
                </tr>
              </tbody>

              <!-- Totals -->
              <tfoot class="border-t-2 border-gray-100 bg-gray-50/40">
                <tr>
                  <td colspan="6" class="px-4 py-2 text-right text-xs text-gray-400">Cộng hàng (chưa VAT)</td>
                  <td class="px-3 py-2 text-right text-sm text-gray-600">{{ formatVnd(subtotal) }}</td>
                  <td />
                </tr>
                <tr v-if="totalVat > 0">
                  <td colspan="6" class="px-4 py-2 text-right text-xs text-gray-400">Thuế VAT</td>
                  <td class="px-3 py-2 text-right text-sm font-medium text-blue-600">{{ formatVnd(totalVat) }}</td>
                  <td />
                </tr>
                <tr>
                  <td colspan="6" class="px-4 py-3.5 text-right text-sm font-bold text-gray-700">Tổng cộng</td>
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

        <!-- VAT warning -->
        <div
          v-if="hasNullVat"
          class="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4"
        >
          <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-amber-100">
            <svg class="h-4 w-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
            </svg>
          </div>
          <div>
            <p class="text-sm font-semibold text-amber-900">
              {{ form.items.filter(i => i.vat_rate === null).length }} mặt hàng chưa chọn VAT
            </p>
            <p class="mt-0.5 text-xs text-amber-700">
              Vui lòng chọn mức thuế VAT cho các ô đang hiện màu vàng trước khi lưu.
            </p>
          </div>
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
            {{ form.processing ? 'Đang lưu...' : (order ? 'Cập nhật đơn hàng' : 'Tạo đơn hàng') }}
          </button>
          <Link
            :href="order ? route('sales.orders.show', order.id) : route('sales.orders.index')"
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
import { computed, ref, onMounted } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import FormField from '@/Components/Shared/FormField.vue';
import ProductSearch from '@/Components/Shared/ProductSearch.vue';
import RemoteSearchSelect from '@/Components/Shared/RemoteSearchSelect.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  nextCode:             String,
  order:                Object,
  quotations:           { type: Array, default: () => [] },
  fromQuotationId:      { type: [Number, String], default: null },
  supplementaryFor:     { type: Object, default: null },
  initialCustomerName:  { type: String, default: '' },
  initialCustomerCode:  { type: String, default: '' },
  initialCustomerFdi:   { type: Boolean, default: false },
});

// Track FDI status reactively (from selected customer)
const customerIsFdi = ref(props.initialCustomerFdi)

const initialCustomerDisplay = computed(() =>
  props.order
    ? [props.initialCustomerCode, props.initialCustomerName].filter(Boolean).join(' - ')
    : props.supplementaryFor
      ? props.supplementaryFor.customer_name ?? ''
      : ''
)

const selectedCustomerIsFdi = computed(() => customerIsFdi.value)

function onCustomerChange(opt) {
  customerIsFdi.value = opt?.is_fdi ?? false
}

// Map items for edit mode — items already have name/unit (snapshot)
const initItems = () =>
  (props.order?.items ?? []).map(i => ({
    ...i,
    _productDisplay: i.product_id && i.name ? `${i.name}` : '',
    _serviceDisplay: i.service_id && i.name ? `${i.name}` : '',
  }))

const form = useForm({
  code:                       props.order?.code ?? props.nextCode ?? '',
  customer_id:                props.order?.customer_id ?? props.supplementaryFor?.customer_id ?? '',
  quotation_id:               props.order?.quotation_id ?? null,
  supplementary_for_order_id: props.order ? null : (props.supplementaryFor?.id ?? null),
  order_date:                 props.order?.order_date ?? new Date().toISOString().slice(0, 10),
  expected_delivery:          props.order?.expected_delivery ?? '',
  notes:                      props.order?.notes ?? '',
  items:                      initItems(),
});

const onQuotationChange = () => {
  if (!form.quotation_id) return;
  const q = props.quotations.find(q => q.id === form.quotation_id);
  if (!q) return;
  form.customer_id = q.customer_id;
  form.items = q.items.map(i => ({ ...i, _productDisplay: '', _serviceDisplay: '' }));
};

onMounted(() => {
  if (props.fromQuotationId && !props.order) {
    form.quotation_id = Number(props.fromQuotationId);
    onQuotationChange();
  }
});

const addRow = (type) => {
  form.items.push({
    _type:            type,
    product_id:       type === 'product' ? null : null,
    service_id:       type === 'service' ? null : null,
    _productDisplay:  '',
    _serviceDisplay:  '',
    name:             '',
    unit:             '',
    quantity:         1,
    unit_price:       0,
    vat_rate:         10,
    discount_percent: 0,
  });
};

const removeRow = (idx) => form.items.splice(idx, 1);

const onProductSelect = (idx, opt) => {
  const item = form.items[idx];
  if (opt) {
    item.name            = opt.label;
    item.unit            = opt.unit ?? opt.meta ?? '';
    item.unit_price      = opt.sell_price ?? opt.cost_price ?? 0;
    item._productDisplay = `${opt.code} - ${opt.label}`;
  } else {
    item.name = ''; item.unit = ''; item._productDisplay = '';
  }
};

const onServiceSelect = (idx, opt) => {
  const item = form.items[idx];
  if (opt) {
    item.name            = opt.label;
    item.unit            = opt.unit ?? opt.meta ?? '';
    item.unit_price      = opt.price ?? 0;
    item._serviceDisplay = `${opt.code} - ${opt.label}`;
  } else {
    item.name = ''; item.unit = ''; item._serviceDisplay = '';
  }
};

const itemLineTotal = (item) => {
  const lineAmt = (item.quantity || 0) * (item.unit_price || 0);
  const disc    = item.discount_percent || 0;
  return lineAmt - Math.round(lineAmt * disc / 100);
};

const itemVatAmount    = (item) => Math.round(itemLineTotal(item) * (item.vat_rate || 0) / 100);
const itemTotalWithVat = (item) => itemLineTotal(item) + itemVatAmount(item);

const subtotal   = computed(() => form.items.reduce((s, i) => s + itemLineTotal(i), 0));
const totalVat   = computed(() => form.items.reduce((s, i) => s + itemVatAmount(i), 0));
const grandTotal = computed(() => subtotal.value + totalVat.value);
const hasNullVat = computed(() => form.items.some(i => i.vat_rate === null));

const { formatVnd } = useCurrency();

const submit = () => {
  const payload = form.items.map(({ _type, _productDisplay, _serviceDisplay, ...rest }) => ({
    ...rest,
    vat_rate:        rest.vat_rate ?? null,
    discount_amount: Math.round((rest.quantity || 0) * (rest.unit_price || 0) * (rest.discount_percent || 0) / 100),
  }));
  if (props.order) {
    form.transform(data => ({ ...data, items: payload })).put(route('sales.orders.update', props.order.id));
  } else {
    form.transform(data => ({ ...data, items: payload })).post(route('sales.orders.store'));
  }
};
</script>
