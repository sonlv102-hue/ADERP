<template>
  <AppLayout>
    <div class="max-w-3xl space-y-6">
      <!-- Header -->
      <div class="flex items-center gap-3">
        <Link :href="route('accounting.invoices.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ invoice ? 'Sửa hóa đơn' : 'Tạo hóa đơn' }}</h1>
      </div>

      <form @submit.prevent="submit" class="space-y-6">
        <!-- Thông tin chung -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <!-- Mã hóa đơn -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Mã hóa đơn <span class="text-red-500">*</span></label>
              <input v-model="form.code" :disabled="!!invoice" type="text"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:bg-gray-50" />
              <p v-if="form.errors.code" class="text-red-500 text-xs mt-1">{{ form.errors.code }}</p>
            </div>

            <!-- Khách hàng -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Khách hàng <span class="text-red-500">*</span></label>
              <select v-model="form.customer_id"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                <option value="">-- Chọn khách hàng --</option>
                <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
              <p v-if="form.errors.customer_id" class="text-red-500 text-xs mt-1">{{ form.errors.customer_id }}</p>
            </div>

            <!-- Ngày phát hành -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ngày phát hành <span class="text-red-500">*</span></label>
              <input v-model="form.issue_date" type="date"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
              <p v-if="form.errors.issue_date" class="text-red-500 text-xs mt-1">{{ form.errors.issue_date }}</p>
            </div>

            <!-- Hạn thanh toán -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Hạn thanh toán</label>
              <input v-model="form.due_date" type="date"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
              <p v-if="form.errors.due_date" class="text-red-500 text-xs mt-1">{{ form.errors.due_date }}</p>
            </div>

            <!-- Liên kết đơn hàng -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Đơn hàng liên kết</label>
              <select v-model="form.order_id"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                <option :value="null">-- Không chọn --</option>
                <option v-for="o in orders" :key="o.id" :value="o.id">
                  {{ o.code }} — {{ formatVnd(o.total) }}
                </option>
              </select>
              <p v-if="form.order_id && autoFilledFrom === 'order'" class="text-xs text-blue-500 mt-1">
                ✓ Đã tự điền dòng hàng từ đơn hàng
              </p>
            </div>

            <!-- Liên kết hợp đồng -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Hợp đồng liên kết</label>
              <select v-model="form.contract_id"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                <option :value="null">-- Không chọn --</option>
                <option v-for="c in contracts" :key="c.id" :value="c.id">
                  {{ c.code }} — {{ c.title }} ({{ formatVnd(c.value) }})
                </option>
              </select>
              <p v-if="form.contract_id && autoFilledFrom === 'contract'" class="text-xs text-blue-500 mt-1">
                ✓ Đã tự điền dòng hàng từ hợp đồng
              </p>
            </div>
          </div>

          <!-- Tài khoản doanh thu — chỉ cho standalone invoice -->
          <div v-if="!form.order_id">
            <label class="block text-sm font-medium text-gray-700 mb-1">
              Tài khoản doanh thu
              <span class="text-orange-500 text-xs ml-1">(cần điền khi không có đơn hàng liên kết)</span>
            </label>
            <select v-model="form.revenue_account_code"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
              <option :value="null">-- Chưa chọn (fallback 5111) --</option>
              <option v-for="a in revenueAccounts" :key="a.code" :value="a.code">{{ a.label }}</option>
            </select>
            <p v-if="form.errors.revenue_account_code" class="text-red-500 text-xs mt-1">{{ form.errors.revenue_account_code }}</p>
          </div>

          <!-- Ghi chú -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
            <textarea v-model="form.notes" rows="2"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"></textarea>
          </div>
        </div>

        <!-- Dòng hàng hóa / dịch vụ -->
        <div class="bg-white rounded-xl border border-gray-200 p-6">
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-semibold text-gray-800">Dòng hàng hóa / dịch vụ</h2>
            <button type="button" @click="addLine"
              class="text-sm text-primary-600 hover:text-primary-700 font-medium">+ Thêm dòng</button>
          </div>

          <p v-if="form.errors.items" class="text-red-500 text-xs mb-3">{{ form.errors.items }}</p>

          <!-- Table — scroll on small screens -->
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead>
                <tr class="text-xs text-gray-500 border-b border-gray-200">
                  <th class="text-left pb-2 pr-2 font-medium">Mô tả</th>
                  <th class="text-right pb-2 pr-2 font-medium w-20">SL</th>
                  <th class="text-right pb-2 pr-2 font-medium w-28">Đơn giá</th>
                  <th class="text-right pb-2 pr-2 font-medium w-24">Thành tiền</th>
                  <th class="text-center pb-2 pr-2 font-medium w-24">Thuế suất</th>
                  <th class="text-right pb-2 pr-2 font-medium w-24">Tiền thuế</th>
                  <th class="w-8"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, idx) in form.items" :key="idx" class="border-b border-gray-50 last:border-0">
                  <td class="py-1.5 pr-2">
                    <input v-model="line.description" type="text" placeholder="Tên hàng / dịch vụ"
                      class="w-full border border-gray-200 rounded-md px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-primary-400" />
                  </td>
                  <td class="py-1.5 pr-2">
                    <input v-model.number="line.quantity" type="number" min="0" step="any"
                      @input="recomputeLine(idx)"
                      class="w-full border border-gray-200 rounded-md px-2 py-1 text-sm text-right focus:outline-none focus:ring-1 focus:ring-primary-400" />
                  </td>
                  <td class="py-1.5 pr-2">
                    <input v-model.number="line.unit_price" type="number" min="0" step="any"
                      @input="recomputeLine(idx)"
                      class="w-full border border-gray-200 rounded-md px-2 py-1 text-sm text-right focus:outline-none focus:ring-1 focus:ring-primary-400" />
                  </td>
                  <td class="py-1.5 pr-2 text-right text-gray-700 tabular-nums whitespace-nowrap">
                    {{ formatVnd(lineSubtotal(line)) }}
                  </td>
                  <td class="py-1.5 pr-2">
                    <select v-model.number="line.vat_rate" @change="recomputeLine(idx)"
                      class="w-full border border-gray-200 rounded-md px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-primary-400">
                      <option :value="0">0%</option>
                      <option :value="5">5%</option>
                      <option :value="8">8%</option>
                      <option :value="10">10%</option>
                    </select>
                  </td>
                  <td class="py-1.5 pr-2 text-right text-gray-600 tabular-nums whitespace-nowrap">
                    {{ formatVnd(line.tax_amount) }}
                  </td>
                  <td class="py-1.5">
                    <button type="button" @click="removeLine(idx)"
                      :disabled="form.items.length <= 1"
                      class="text-gray-300 hover:text-red-500 disabled:opacity-30 text-lg leading-none">×</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Totals -->
          <div class="mt-4 flex justify-end">
            <div class="w-64 space-y-1.5 text-sm">
              <div class="flex justify-between text-gray-600">
                <span>Tổng trước thuế:</span>
                <span class="tabular-nums">{{ formatVnd(totals.subtotal) }}</span>
              </div>
              <div class="flex justify-between text-gray-600">
                <span>Tổng thuế VAT:</span>
                <span class="tabular-nums">{{ formatVnd(totals.tax) }}</span>
              </div>
              <div class="flex justify-between font-bold text-gray-900 border-t border-gray-200 pt-2 text-base">
                <span>Tổng cộng:</span>
                <span class="tabular-nums">{{ formatVnd(totals.total) }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Actions -->
        <div class="flex justify-end gap-3">
          <Link :href="route('accounting.invoices.index')"
            class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium">
            Hủy
          </Link>
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded-lg text-sm font-medium disabled:opacity-50">
            {{ invoice ? 'Cập nhật' : 'Tạo hóa đơn' }}
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  invoice:         Object,
  nextCode:        String,
  customers:       Array,
  orders:          Array,
  contracts:       Array,
  methods:         Array,
  revenueAccounts: Array,
});

const today = new Date().toISOString().split('T')[0];
const autoFilledFrom = ref(null);
const skipContractWatch = ref(false);

function newLine(overrides = {}) {
  return { description: '', quantity: 1, unit_price: 0, vat_rate: 0, tax_amount: 0, ...overrides };
}

function lineSubtotal(line) {
  return (line.quantity || 0) * (line.unit_price || 0);
}

// Khởi tạo items từ invoice (edit) hoặc 1 dòng trống (create)
const initialItems = props.invoice?.items?.length
  ? props.invoice.items.map(i => ({
      description: i.description ?? '',
      quantity:    parseFloat(i.quantity) || 1,
      unit_price:  parseFloat(i.unit_price) || 0,
      vat_rate:    parseFloat(i.vat_rate) || 0,
      tax_amount:  parseInt(i.tax_amount) || 0,
    }))
  : [newLine()];

const form = useForm({
  code:                 props.invoice?.code                 ?? props.nextCode,
  customer_id:          props.invoice?.customer_id          ?? '',
  order_id:             props.invoice?.order_id             ?? null,
  contract_id:          props.invoice?.contract_id          ?? null,
  issue_date:           props.invoice?.issue_date           ?? today,
  due_date:             props.invoice?.due_date             ?? '',
  notes:                props.invoice?.notes                ?? '',
  revenue_account_code: props.invoice?.revenue_account_code ?? null,
  items:                initialItems,
});

// Computed totals — không dùng computed() để tránh circular ref với useForm reactive
// Thay vào đó: recompute mỗi khi cần thông qua totals object
const totals = computed(() => {
  const sub = form.items.reduce((s, l) => s + lineSubtotal(l), 0);
  const tax = form.items.reduce((s, l) => s + (l.tax_amount || 0), 0);
  return { subtotal: sub, tax, total: sub + tax };
});

function recomputeLine(idx) {
  const line = form.items[idx];
  const sub  = lineSubtotal(line);
  line.tax_amount = Math.round(sub * (line.vat_rate || 0) / 100);
}

function addLine() {
  form.items.push(newLine());
}

function removeLine(idx) {
  if (form.items.length > 1) form.items.splice(idx, 1);
}

// Khi chọn order → auto-populate items từ order_items (kèm vat_rate per-line)
watch(() => form.order_id, (id) => {
  if (!id) { autoFilledFrom.value = null; return; }
  form.revenue_account_code = null;
  const order = props.orders.find(o => o.id === id);
  if (order?.items?.length) {
    form.items = order.items.map(i => ({ ...newLine(), ...i }));
    autoFilledFrom.value = 'order';
  }
  // Liên kết hợp đồng nếu có
  const contract = props.contracts.find(c => c.order_id === id);
  if (contract) {
    skipContractWatch.value = true;
    form.contract_id = contract.id;
  }
});

// Khi chọn hợp đồng (không qua order) → 1 dòng tóm tắt giá trị hợp đồng
watch(() => form.contract_id, (id) => {
  if (skipContractWatch.value) { skipContractWatch.value = false; return; }
  if (!id) { autoFilledFrom.value = null; return; }
  const contract = props.contracts.find(c => c.id === id);
  if (contract) {
    form.items = [newLine({
      description: contract.title ? `${contract.code} — ${contract.title}` : contract.code,
      quantity:    1,
      unit_price:  parseFloat(contract.value) || 0,
      vat_rate:    0,
    })];
    recomputeLine(0);
    autoFilledFrom.value = 'contract';
  }
});

function formatVnd(value) {
  return new Intl.NumberFormat('vi-VN').format(Math.round(value || 0)) + ' ₫';
}

function submit() {
  const t = totals.value;
  // Gửi subtotal/tax_amount/total lên server để validate — server sẽ tính lại từ items
  form.transform(data => ({
    ...data,
    subtotal:   t.subtotal,
    tax_amount: t.tax,
    total:      t.total,
  }));

  if (props.invoice) {
    form.put(route('accounting.invoices.update', props.invoice.id));
  } else {
    form.post(route('accounting.invoices.store'));
  }
}
</script>
