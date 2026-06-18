<template>
  <AppLayout>
    <div class="max-w-4xl space-y-5">

      <!-- Page header -->
      <div class="flex items-center gap-3">
        <Link
          :href="route('warehouse.opening-balance.index')"
          class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
        >
          <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <div>
          <p class="mb-0.5 text-xs font-medium text-gray-400">Kho / Tồn kho đầu kỳ</p>
          <h1 class="text-xl font-bold text-gray-900">Nhập tồn kho đầu kỳ</h1>
        </div>
      </div>

      <form @submit.prevent="submit" class="space-y-5">

        <!-- ─── Section 1: Kỳ và kho ─── -->
        <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
          <div class="flex items-center gap-2.5 border-b border-gray-100 bg-gray-50/60 px-6 py-4">
            <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-100">
              <svg class="h-3.5 w-3.5 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
            </div>
            <h2 class="text-sm font-semibold text-gray-800">Thông tin kỳ kế toán</h2>
          </div>

          <div class="grid grid-cols-1 gap-5 p-6 sm:grid-cols-2">
            <FormField label="Kỳ" required :error="form.errors.period">
              <input
                v-model="form.period"
                type="month"
                class="w-full rounded-xl border px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow]"
                :class="form.errors.period
                  ? 'border-red-400 bg-red-50/40 focus:border-red-400 focus:ring-2 focus:ring-red-100'
                  : 'border-gray-200 bg-white focus:border-primary-500 focus:ring-2 focus:ring-primary-100'"
              />
            </FormField>

            <FormField label="Kho" required :error="form.errors.warehouse_id">
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
          </div>
        </div>

        <!-- ─── Section 2: Danh sách hàng hóa ─── -->
        <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
          <div class="flex items-center justify-between border-b border-gray-100 bg-gray-50/60 px-6 py-4">
            <div class="flex items-center gap-2.5">
              <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-100">
                <svg class="h-3.5 w-3.5 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                </svg>
              </div>
              <h2 class="text-sm font-semibold text-gray-800">Danh sách hàng hóa</h2>
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
              <p class="mt-0.5 text-xs text-gray-400">Nhấn "+ Thêm dòng" để nhập tồn kho đầu kỳ.</p>
            </div>
          </div>

          <!-- Table -->
          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="border-b border-gray-100">
                <tr class="bg-gray-50/60">
                  <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-400">Sản phẩm</th>
                  <th class="w-28 px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-400">Số lượng</th>
                  <th class="w-36 px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-400">Đơn giá vốn</th>
                  <th class="w-36 px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-400">Thành tiền</th>
                  <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-400">Ghi chú</th>
                  <th class="w-8 px-3 py-3" />
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-50">
                <tr
                  v-for="(item, idx) in form.items"
                  :key="idx"
                  class="transition-colors hover:bg-blue-50/20"
                >
                  <td class="px-4 py-2.5">
                    <ProductSearch
                      v-model="item.product_id"
                      @select="p => { if (p) item.unit_cost = p.cost_price ?? 0; }"
                    />
                  </td>
                  <td class="px-3 py-2.5">
                    <input
                      v-model.number="item.quantity"
                      type="number"
                      min="0"
                      step="any"
                      @input="calcTotal(idx)"
                      class="w-full rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs text-right outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
                    />
                  </td>
                  <td class="px-3 py-2.5">
                    <input
                      v-model.number="item.unit_cost"
                      type="number"
                      min="0"
                      step="any"
                      @input="calcTotal(idx)"
                      class="w-full rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs text-right outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
                    />
                  </td>
                  <td class="px-3 py-2.5 text-right">
                    <p class="text-sm font-semibold text-gray-800">{{ fv(item.quantity * item.unit_cost) }}</p>
                  </td>
                  <td class="px-3 py-2.5">
                    <input
                      v-model="item.note"
                      type="text"
                      placeholder="Ghi chú..."
                      class="w-full rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs outline-none transition placeholder:text-gray-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
                    />
                  </td>
                  <td class="px-3 py-2.5 text-center">
                    <button
                      type="button"
                      @click="removeRow(idx)"
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
                  <td colspan="3" class="px-4 py-3.5 text-right text-sm font-bold text-gray-700">Tổng giá trị</td>
                  <td class="px-3 py-3.5 text-right text-lg font-bold text-primary-700">
                    {{ fv(form.items.reduce((s, i) => s + (i.quantity * i.unit_cost || 0), 0)) }}
                  </td>
                  <td colspan="2" />
                </tr>
              </tfoot>
            </table>
          </div>
        </div>

        <!-- Action bar -->
        <div class="flex items-center gap-3 pb-2">
          <button
            type="submit"
            :disabled="form.processing || !form.items.length"
            class="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-60"
          >
            <svg v-if="form.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            {{ form.processing ? 'Đang lưu...' : 'Lưu & tạo bút toán kế toán' }}
          </button>
          <Link
            :href="route('warehouse.opening-balance.index')"
            class="rounded-xl border border-gray-200 px-5 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-50"
          >
            Huỷ
          </Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import FormField from '@/Components/Shared/FormField.vue';
import ProductSearch from '@/Components/Shared/ProductSearch.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ warehouses: Array });
const { formatVnd: fv } = useCurrency();

const today = new Date().toISOString().slice(0, 7);

const form = useForm({
  period:       today,
  warehouse_id: '',
  items: [],
});

function addRow() {
  form.items.push({ product_id: '', quantity: 0, unit_cost: 0, note: '' });
}

function removeRow(idx) {
  form.items.splice(idx, 1);
}

function calcTotal(idx) {
  // reactive — template handles display
}

function submit() {
  form.post(route('warehouse.opening-balance.store'));
}
</script>
