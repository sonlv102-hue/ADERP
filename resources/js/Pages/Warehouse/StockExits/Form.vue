<template>
  <AppLayout>
    <div class="max-w-5xl space-y-5">

      <!-- Page header -->
      <div class="flex items-center gap-3">
        <Link
          :href="route('warehouse.stock-exits.index')"
          class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
        >
          <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <div>
          <p class="mb-0.5 text-xs font-medium text-gray-400">Kho / Xuất kho</p>
          <h1 class="text-xl font-bold text-gray-900">
            {{ exit ? 'Sửa phiếu xuất ' + exit.code : 'Tạo phiếu xuất kho' }}
          </h1>
        </div>
      </div>

      <form @submit.prevent="submit" class="space-y-5">

        <!-- ─── Section 1: Thông tin phiếu xuất ─── -->
        <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
          <div class="flex items-center gap-2.5 border-b border-gray-100 bg-gray-50/60 px-6 py-4">
            <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-100">
              <svg class="h-3.5 w-3.5 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
              </svg>
            </div>
            <h2 class="text-sm font-semibold text-gray-800">Thông tin phiếu xuất</h2>
          </div>

          <div class="p-6 space-y-5">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">

              <!-- Mã phiếu -->
              <FormField label="Mã phiếu" required :error="form.errors.code">
                <input
                  v-model="form.code"
                  type="text"
                  :readonly="!!exit"
                  placeholder="VD: XK-2024-001"
                  class="w-full rounded-xl border px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow] placeholder:text-gray-300"
                  :class="form.errors.code
                    ? 'border-red-400 bg-red-50/40 focus:border-red-400 focus:ring-2 focus:ring-red-100'
                    : exit
                      ? 'border-gray-200 bg-gray-50 text-gray-500 cursor-default'
                      : 'border-gray-200 bg-white focus:border-primary-500 focus:ring-2 focus:ring-primary-100'"
                />
              </FormField>

              <!-- Ngày xuất -->
              <FormField label="Ngày xuất" required :error="form.errors.exit_date">
                <input
                  v-model="form.exit_date"
                  type="date"
                  class="w-full rounded-xl border px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow]"
                  :class="form.errors.exit_date
                    ? 'border-red-400 bg-red-50/40 focus:border-red-400 focus:ring-2 focus:ring-red-100'
                    : 'border-gray-200 bg-white focus:border-primary-500 focus:ring-2 focus:ring-primary-100'"
                />
              </FormField>

              <!-- Kho -->
              <FormField label="Kho xuất" required :error="form.errors.warehouse_id">
                <select
                  v-model="form.warehouse_id"
                  @change="onWarehouseChange"
                  class="w-full rounded-xl border px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow]"
                  :class="form.errors.warehouse_id
                    ? 'border-red-400 bg-red-50/40 focus:border-red-400 focus:ring-2 focus:ring-red-100'
                    : 'border-gray-200 bg-white focus:border-primary-500 focus:ring-2 focus:ring-primary-100'"
                >
                  <option value="">— Chọn kho —</option>
                  <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                </select>
              </FormField>

              <!-- Mục đích xuất kho -->
              <FormField label="Mục đích xuất" required :error="form.errors.issue_purpose">
                <select
                  v-model="form.issue_purpose"
                  @change="onIssuePurposeChange"
                  class="w-full rounded-xl border px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow]"
                  :class="form.errors.issue_purpose
                    ? 'border-red-400 bg-red-50/40 focus:border-red-400 focus:ring-2 focus:ring-red-100'
                    : 'border-gray-200 bg-white focus:border-primary-500 focus:ring-2 focus:ring-primary-100'"
                >
                  <option value="">— Chọn mục đích —</option>
                  <option v-for="t in issuePurposes" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
              </FormField>

              <!-- Khách hàng -->
              <FormField label="Khách hàng" optional :error="form.errors.customer_id">
                <select
                  v-model="form.customer_id"
                  @change="onCustomerChange"
                  class="w-full rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow] focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
                >
                  <option :value="null">— Không liên kết —</option>
                  <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.code }} – {{ c.name }}</option>
                </select>
              </FormField>

              <!-- Đơn hàng liên kết -->
              <FormField label="Đơn hàng liên kết" optional :error="form.errors.order_id">
                <select
                  v-model="form.order_id"
                  @change="onOrderChange"
                  class="w-full rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow] focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
                >
                  <option :value="null">— Không liên kết —</option>
                  <option v-for="o in customerOrders" :key="o.id" :value="o.id">
                    {{ o.code }} ({{ o.status_label }})
                  </option>
                </select>
                <div v-if="selectedOrderItems.length" class="mt-2 rounded-xl border border-blue-100 bg-blue-50 p-3">
                  <p class="mb-1.5 text-xs font-semibold text-blue-700">Số lượng còn cần giao:</p>
                  <div v-for="i in selectedOrderItems" :key="i.product_id" class="flex justify-between text-xs">
                    <span class="text-gray-600">{{ i.product_name }}</span>
                    <span :class="i.remaining <= 0 ? 'font-semibold text-green-600' : 'font-semibold text-blue-700'">
                      {{ i.remaining <= 0 ? 'Đã giao đủ' : `còn ${i.remaining}` }}
                    </span>
                  </div>
                </div>
              </FormField>

              <!-- Dự án (project_cost only) -->
              <FormField v-if="form.issue_purpose === 'project_cost'" label="Dự án" required :error="form.errors.project_id">
                <select
                  v-model="form.project_id"
                  class="w-full rounded-xl border px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow]"
                  :class="form.errors.project_id
                    ? 'border-red-400 bg-red-50/40 focus:border-red-400 focus:ring-2 focus:ring-red-100'
                    : 'border-gray-200 bg-white focus:border-primary-500 focus:ring-2 focus:ring-primary-100'"
                >
                  <option :value="null">— Chọn dự án —</option>
                  <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
                </select>
              </FormField>

              <!-- Available lots panel -->
              <template v-if="form.issue_purpose === 'project_cost' && form.project_id && form.warehouse_id">
                <div class="sm:col-span-2">
                  <div v-if="lotsLoading" class="text-xs italic text-gray-400">Đang tải lô hàng...</div>
                  <div v-else-if="availableLots.length"
                    class="space-y-2 rounded-xl border border-emerald-100 bg-emerald-50/50 p-3">
                    <p class="text-xs font-semibold text-emerald-700">Lô hàng sẵn có (FIFO):</p>
                    <div v-for="lot in availableLots" :key="lot.product_id">
                      <div class="flex items-center justify-between text-xs">
                        <span class="font-medium text-gray-700">{{ lot.product_code }} — {{ lot.product_name }}</span>
                        <span class="font-semibold text-emerald-700">Sẵn có: {{ lot.available_qty }} {{ lot.unit }}</span>
                      </div>
                      <div class="mt-1 flex flex-wrap gap-1">
                        <span v-for="l in lot.lots" :key="l.id"
                          class="rounded-md border border-emerald-200 bg-white px-1.5 py-0.5 text-xs text-gray-600">
                          {{ l.stock_entry_code }}: {{ l.available_qty }}
                        </span>
                      </div>
                    </div>
                  </div>
                  <div v-else
                    class="rounded-xl border border-amber-100 bg-amber-50 px-4 py-2.5 text-xs text-amber-700">
                    Chưa có lô hàng nào trong kho cho dự án này.
                  </div>
                </div>
              </template>

              <!-- Lý do xuất -->
              <FormField label="Lý do xuất" optional :error="form.errors.reason"
                :wrapClass="form.issue_purpose === 'project_cost' ? '' : 'sm:col-span-2'">
                <input
                  v-model="form.reason"
                  type="text"
                  placeholder="Mô tả lý do xuất hàng..."
                  class="w-full rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow] placeholder:text-gray-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
                />
              </FormField>
            </div>

            <FormField label="Ghi chú" optional>
              <textarea
                v-model="form.notes"
                rows="2"
                placeholder="Ghi chú thêm..."
                class="w-full resize-none rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow] placeholder:text-gray-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
              />
            </FormField>
          </div>
        </div>

        <!-- No contract warning -->
        <div v-if="form.order_id && !hasOrderContract"
          class="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4">
          <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-amber-100">
            <svg class="h-4 w-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
            </svg>
          </div>
          <div>
            <p class="text-sm font-semibold text-amber-900">Đơn hàng chưa có hợp đồng bán</p>
            <p class="mt-0.5 text-xs text-amber-700">
              Đề nghị tạo hợp đồng bán trước khi xuất kho để đảm bảo đầy đủ chứng từ pháp lý.
              <Link :href="route('sales.contracts.create')" class="ml-1 font-medium underline">Tạo hợp đồng →</Link>
            </p>
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
                  d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
              </svg>
            </div>
            <div>
              <p class="text-sm font-medium text-gray-600">Chưa có hàng hóa nào</p>
              <p class="mt-0.5 text-xs text-gray-400">Nhấn "+ Thêm dòng" để thêm sản phẩm cần xuất.</p>
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
                  <th class="w-32 px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-400">Đơn giá</th>
                  <th class="w-32 px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-400">Thành tiền</th>
                  <th class="w-8 px-3 py-3" />
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-50">
                <template v-for="(item, index) in form.items" :key="index">
                  <tr class="transition-colors hover:bg-blue-50/20">
                    <td class="px-5 py-2.5">
                      <ProductSearch
                        :options="products"
                        v-model="item.product_id"
                        @select="p => onProductSelect(index, p)"
                        :has-error="!!form.errors[`items.${index}.product_id`]"
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
                        @change="onQuantityChange(index)"
                        class="w-full rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs text-right outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
                        :class="[
                          form.errors[`items.${index}.quantity`] && 'border-red-400 bg-red-50/40',
                          !form.errors[`items.${index}.quantity`] && productAvailableQty(item.product_id) !== null && item.quantity > productAvailableQty(item.product_id) && 'border-amber-400 bg-amber-50/40',
                        ]"
                      />
                    </td>
                    <td class="px-3 py-2.5">
                      <input
                        v-model.number="item.unit_price"
                        type="number"
                        min="0"
                        step="any"
                        class="w-full rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs text-right outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
                        :class="form.errors[`items.${index}.unit_price`] && 'border-red-400 bg-red-50/40'"
                      />
                    </td>
                    <td class="px-3 py-2.5 text-right">
                      <p class="text-sm font-semibold text-gray-800">
                        {{ formatVnd(item.quantity * item.unit_price) }}
                      </p>
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

                  <!-- Serial picker -->
                  <tr v-if="item.product_id && form.warehouse_id" class="bg-blue-50/40">
                    <td colspan="6" class="px-5 py-3">
                      <div class="flex items-start gap-3">
                        <span class="mt-0.5 shrink-0 text-xs font-semibold text-blue-700">
                          Serial <span class="font-normal text-gray-400">({{ item.serial_ids.length }}/{{ item.quantity }})</span>
                        </span>
                        <div v-if="availableSerials(index).length === 0" class="text-xs italic text-orange-600">
                          Không có serial nào trong kho cho sản phẩm này
                        </div>
                        <div v-else class="flex flex-wrap gap-1.5">
                          <label
                            v-for="s in availableSerials(index)"
                            :key="s.id"
                            class="flex cursor-pointer select-none items-center gap-1.5 rounded-lg border px-2 py-1 text-xs transition"
                            :class="item.serial_ids.includes(s.id)
                              ? 'border-blue-600 bg-blue-600 text-white'
                              : 'border-gray-200 bg-white text-gray-700 hover:border-blue-400'"
                          >
                            <input
                              type="checkbox"
                              :value="s.id"
                              :checked="item.serial_ids.includes(s.id)"
                              @change="toggleSerial(index, s.id)"
                              class="hidden"
                            />
                            {{ s.serial_number }}
                          </label>
                        </div>
                      </div>
                      <p v-if="form.errors[`items.${index}.serial_ids`]" class="mt-1 text-xs text-red-600">
                        {{ form.errors[`items.${index}.serial_ids`] }}
                      </p>
                    </td>
                  </tr>
                </template>
              </tbody>
              <tfoot class="border-t-2 border-gray-100 bg-gray-50/40">
                <tr>
                  <td colspan="4" class="px-5 py-3.5 text-right text-sm font-bold text-gray-700">Tổng cộng</td>
                  <td class="px-3 py-3.5 text-right text-lg font-bold text-primary-700">{{ formatVnd(grandTotal) }}</td>
                  <td />
                </tr>
              </tfoot>
            </table>
          </div>

          <p v-if="form.errors.items"
            class="border-t border-red-100 bg-red-50 px-5 py-2.5 text-xs text-red-600">
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
            {{ form.processing ? 'Đang lưu...' : (exit ? 'Cập nhật phiếu xuất' : 'Tạo phiếu xuất kho') }}
          </button>
          <Link
            :href="route('warehouse.stock-exits.index')"
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
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import FormField from '@/Components/Shared/FormField.vue';
import ProductSearch from '@/Components/Shared/ProductSearch.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  nextCode: String,
  warehouses: Array,
  customers: Array,
  products: Array,
  serials: Array,
  orders: { type: Array, default: () => [] },
  projects: { type: Array, default: () => [] },
  usageTypes: { type: Array, default: () => [] },
  issuePurposes: { type: Array, default: () => [] },
  exit: { type: Object, default: null },
});

const { formatVnd } = useCurrency();

const today = new Date().toISOString().slice(0, 10);

const form = useForm({
  code:             props.exit?.code             ?? props.nextCode ?? '',
  exit_date:        props.exit?.exit_date        ?? today,
  warehouse_id:     props.exit?.warehouse_id     ?? '',
  customer_id:      props.exit?.customer_id      ?? null,
  order_id:         props.exit?.order_id         ?? null,
  item_usage_type:  props.exit?.item_usage_type  ?? 'commercial',
  issue_purpose:    props.exit?.issue_purpose    ?? (props.exit?.item_usage_type === 'project' ? 'project_cost' : ''),
  project_id:       props.exit?.project_id       ?? null,
  reason:           props.exit?.reason           ?? '',
  notes:            props.exit?.notes            ?? '',
  items: props.exit?.items?.map(item => ({
    product_id: item.product_id,
    quantity:   item.quantity,
    unit_price: item.unit_price,
    serial_ids: item.serial_ids ?? [],
  })) ?? [],
});

const customerOrders = computed(() =>
  form.customer_id
    ? props.orders.filter(o => o.customer_id === form.customer_id)
    : props.orders
);

const selectedOrder = computed(() =>
  form.order_id ? props.orders.find(o => o.id === form.order_id) : null
);

const selectedOrderItems = computed(() => selectedOrder.value?.items ?? []);

const hasOrderContract = computed(() =>
  !form.order_id || (selectedOrder.value?.has_contract ?? true)
);

const onUsageTypeChange = () => {
  if (form.item_usage_type !== 'project') {
    form.project_id = null;
  }
};

const availableLots = ref([]);
const lotsLoading = ref(false);

const fetchAvailableLots = async () => {
  if (form.issue_purpose !== 'project_cost' || !form.project_id || !form.warehouse_id) {
    availableLots.value = [];
    return;
  }
  lotsLoading.value = true;
  try {
    const url = route('warehouse.stock-exits.available-lots') + `?project_id=${form.project_id}&warehouse_id=${form.warehouse_id}`;
    const res = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await res.json();
    availableLots.value = data.lots ?? [];
  } catch {
    availableLots.value = [];
  } finally {
    lotsLoading.value = false;
  }
};

watch([() => form.project_id, () => form.warehouse_id], fetchAvailableLots);

const productAvailableQty = (productId) => {
  if (form.issue_purpose !== 'project_cost') return null;
  return availableLots.value.find(l => l.product_id === productId)?.available_qty ?? null;
};

const onIssuePurposeChange = () => {
  form.item_usage_type = form.issue_purpose === 'project_cost' ? 'project' : 'commercial';
  if (form.issue_purpose !== 'project_cost') {
    form.project_id = null;
    availableLots.value = [];
  }
};

const onCustomerChange = () => {
  if (form.order_id) {
    const valid = customerOrders.value.some(o => o.id === form.order_id);
    if (!valid) { form.order_id = null; form.items = []; }
  }
};

const onOrderChange = () => {
  if (!form.order_id) return;
  const order = props.orders.find(o => o.id === form.order_id);
  if (!order) return;
  form.customer_id = order.customer_id;
  const filled = order.items
    .filter(i => i.remaining > 0)
    .map(i => ({ product_id: i.product_id, quantity: i.remaining, unit_price: i.unit_price, serial_ids: [] }));
  if (filled.length) form.items = filled;
};

const addRow = () => {
  form.items.push({ product_id: '', quantity: 1, unit_price: 0, serial_ids: [] });
};

const removeRow = (index) => {
  form.items.splice(index, 1);
};

const onWarehouseChange = () => {
  form.items.forEach(item => { item.serial_ids = []; });
};

const onProductSelect = (index, product) => {
  const order = form.order_id ? props.orders.find(o => o.id === form.order_id) : null;
  const orderItem = product ? order?.items?.find(i => i.product_id === product.id) : null;
  if (orderItem) {
    form.items[index].unit_price = orderItem.unit_price;
  } else {
    form.items[index].unit_price = Number(product?.sell_price ?? 0);
  }
  form.items[index].serial_ids = [];
};

const onQuantityChange = (index) => {
  const item = form.items[index];
  if (item.serial_ids.length > item.quantity) {
    item.serial_ids.splice(item.quantity);
  }
};

const availableSerials = (index) => {
  const item = form.items[index];
  if (!item.product_id || !form.warehouse_id) return [];
  const usedElsewhere = new Set(
    form.items.filter((_, i) => i !== index).flatMap(r => r.serial_ids)
  );
  return (props.serials ?? []).filter(
    s => s.product_id === item.product_id &&
         s.warehouse_id === form.warehouse_id &&
         !usedElsewhere.has(s.id)
  );
};

const toggleSerial = (index, serialId) => {
  const item = form.items[index];
  const pos = item.serial_ids.indexOf(serialId);
  if (pos >= 0) {
    item.serial_ids.splice(pos, 1);
  } else {
    item.serial_ids.push(serialId);
  }
};

const itemUnit = (productId) => props.products.find(p => p.id === productId)?.unit ?? '';

const grandTotal = computed(() =>
  form.items.reduce((sum, item) => sum + (item.quantity * item.unit_price), 0)
);

const submit = () => {
  if (props.exit) {
    form.put(route('warehouse.stock-exits.update', props.exit.id));
  } else {
    form.post(route('warehouse.stock-exits.store'));
  }
};
</script>
