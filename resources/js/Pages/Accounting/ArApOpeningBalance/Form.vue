<template>
  <AppLayout>
    <div class="max-w-5xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('accounting.ar-ap-opening-balance.index')" class="text-gray-500 hover:text-gray-700">
          ← Danh sách
        </Link>
        <h1 class="text-xl font-bold text-gray-900">Nhập công nợ đầu kỳ</h1>
      </div>

      <form @submit.prevent="submit" class="space-y-6">
        <!-- Header -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="form-label">Loại <span class="text-red-500">*</span></label>
              <div class="flex gap-3">
                <label class="flex items-center gap-2 cursor-pointer">
                  <input type="radio" v-model="form.type" value="ar" class="text-primary-600" />
                  <span class="text-sm">Phải thu (AR) — TK 131</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                  <input type="radio" v-model="form.type" value="ap" class="text-primary-600" />
                  <span class="text-sm">Phải trả (AP) — TK 331</span>
                </label>
              </div>
            </div>
            <div>
              <label class="form-label">Kỳ <span class="text-red-500">*</span></label>
              <input v-model="form.period" type="month" required class="form-input"
                :class="{ 'border-red-400': form.errors.period }" />
              <p v-if="form.errors.period" class="text-red-500 text-xs mt-1">{{ form.errors.period }}</p>
            </div>
          </div>
        </div>

        <!-- Items -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-700">
              Danh sách {{ form.type === 'ar' ? 'khách hàng' : 'nhà cung cấp' }}
            </h3>
            <button type="button" @click="addRow" class="btn-secondary text-sm">+ Thêm dòng</button>
          </div>

          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 w-40">
                    {{ form.type === 'ar' ? 'Khách hàng' : 'Nhà cung cấp' }}
                  </th>
                  <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 w-28">Số HĐ/CT</th>
                  <th class="px-3 py-2 text-center text-xs font-semibold text-gray-600 w-28">Ngày HĐ</th>
                  <th class="px-3 py-2 text-center text-xs font-semibold text-gray-600 w-28">Hạn TT</th>
                  <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 w-32">Giá trị HĐ</th>
                  <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 w-32">Còn phải {{ form.type === 'ar' ? 'thu' : 'trả' }}</th>
                  <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600">Ghi chú</th>
                  <th class="w-8" />
                </tr>
              </thead>
              <tbody>
                <tr v-if="!form.items.length">
                  <td colspan="8" class="px-3 py-4 text-center text-gray-400 text-xs">Chưa có dòng nào. Nhấn "Thêm dòng".</td>
                </tr>
                <tr v-for="(item, idx) in form.items" :key="idx" class="border-b border-gray-100">
                  <td class="px-3 py-2">
                    <select v-if="form.type === 'ar'" v-model="item.customer_id" class="w-full form-input text-xs py-1">
                      <option value="">-- Chọn KH --</option>
                      <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}</option>
                    </select>
                    <select v-else v-model="item.supplier_id" class="w-full form-input text-xs py-1">
                      <option value="">-- Chọn NCC --</option>
                      <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.code }} - {{ s.name }}</option>
                    </select>
                  </td>
                  <td class="px-3 py-2">
                    <input v-model="item.invoice_ref" type="text" placeholder="Số HĐ..."
                      class="w-full form-input text-xs py-1" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model="item.invoice_date" type="date" class="w-full form-input text-xs py-1" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model="item.due_date" type="date" class="w-full form-input text-xs py-1" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model.number="item.amount" type="number" step="0.01"
                      class="w-full form-input text-xs py-1 text-right" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model.number="item.remaining_amount" type="number" step="0.01"
                      class="w-full form-input text-xs py-1 text-right" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model="item.note" type="text" placeholder="Ghi chú..."
                      class="w-full form-input text-xs py-1" />
                  </td>
                  <td class="px-3 py-2 text-center">
                    <button type="button" @click="removeRow(idx)"
                      class="text-red-400 hover:text-red-600 text-xs">✕</button>
                  </td>
                </tr>
              </tbody>
              <tfoot v-if="form.items.length" class="bg-gray-50">
                <tr>
                  <td colspan="4" class="px-3 py-2 text-right text-sm font-semibold text-gray-600">Tổng cộng:</td>
                  <td class="px-3 py-2 text-right font-mono font-bold text-gray-800">
                    {{ fv(form.items.reduce((s, i) => s + (i.amount || 0), 0)) }}
                  </td>
                  <td class="px-3 py-2 text-right font-mono font-bold text-orange-700">
                    {{ fv(form.items.reduce((s, i) => s + (i.remaining_amount || 0), 0)) }}
                  </td>
                  <td colspan="2" />
                </tr>
              </tfoot>
            </table>
          </div>

          <p class="text-xs text-gray-400 mt-3">
            TK 131 và 331 là tài khoản lưỡng tính. Số dương = Dư Nợ (AR) / Dư Có (AP). Số âm = Dư ngược chiều.
            <br/>Bút toán tự động:
            <span v-if="form.type === 'ar'">dương → Nợ 131 / Có 411 · âm → Có 131 / Nợ 411</span>
            <span v-else>dương → Nợ 411 / Có 331 · âm → Có 411 / Nợ 331</span>
          </p>
        </div>

        <div class="flex gap-3">
          <button type="submit" :disabled="form.processing || !form.items.length" class="btn-primary">
            Lưu & tạo bút toán kế toán
          </button>
          <Link :href="route('accounting.ar-ap-opening-balance.index')" class="btn-secondary">Huỷ</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ customers: Array, suppliers: Array, defaultType: String });
const { formatDecimalVnd: fv } = useCurrency();

const today = new Date().toISOString().slice(0, 7);

const form = useForm({
  type:   props.defaultType ?? 'ar',
  period: today,
  items:  [],
});

function addRow() {
  form.items.push({
    customer_id:      '',
    supplier_id:      '',
    invoice_ref:      '',
    invoice_date:     '',
    due_date:         '',
    amount:           0,
    remaining_amount: 0,
    note:             '',
  });
}

function removeRow(idx) {
  form.items.splice(idx, 1);
}

function submit() {
  form.post(route('accounting.ar-ap-opening-balance.store'));
}
</script>
