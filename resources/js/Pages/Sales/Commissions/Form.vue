<template>
  <AppLayout>
    <div class="max-w-2xl space-y-6">
      <div class="flex items-center gap-3">
        <Link :href="route('sales.commissions.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ commission ? 'Sửa đề xuất' : 'Tạo đề xuất hoa hồng' }}</h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <!-- Mã -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mã nội bộ <span class="text-red-500">*</span></label>
            <input v-model="form.code" :disabled="!!commission" type="text"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:bg-gray-50" />
            <p v-if="form.errors.code" class="text-red-500 text-xs mt-1">{{ form.errors.code }}</p>
          </div>

          <!-- Loại -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Loại chi phí <span class="text-red-500">*</span></label>
            <select v-model="form.type"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
              <option value="">-- Chọn loại --</option>
              <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
            <p v-if="form.errors.type" class="text-red-500 text-xs mt-1">{{ form.errors.type }}</p>
          </div>

          <!-- Người nhận -->
          <div class="col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Tên người nhận <span class="text-red-500">*</span></label>
            <input v-model="form.recipient_name" type="text"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            <p v-if="form.errors.recipient_name" class="text-red-500 text-xs mt-1">{{ form.errors.recipient_name }}</p>
          </div>

          <!-- Thông tin người nhận -->
          <div class="col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Thông tin người nhận (SĐT, STK...)</label>
            <input v-model="form.recipient_info" type="text"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>

          <!-- Số tiền -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Số tiền <span class="text-red-500">*</span></label>
            <input v-model.number="form.amount" type="number" min="0" step="any"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            <p v-if="form.errors.amount" class="text-red-500 text-xs mt-1">{{ form.errors.amount }}</p>
          </div>

          <!-- Tỷ lệ -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tỷ lệ (%) — tùy chọn</label>
            <input v-model.number="form.rate" type="number" min="0" max="100" step="0.01"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>

          <!-- Hình thức TT -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Hình thức thanh toán</label>
            <select v-model="form.payment_method"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
              <option value="bank_transfer">Chuyển khoản</option>
              <option value="cash">Tiền mặt</option>
              <option value="other">Khác</option>
            </select>
          </div>

          <!-- Ngày dự kiến chi -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày dự kiến chi</label>
            <input v-model="form.planned_date" type="date"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
        </div>

        <!-- Liên kết nghiệp vụ -->
        <div class="border-t border-gray-100 pt-4">
          <p class="text-sm font-medium text-gray-700 mb-3">Liên kết nghiệp vụ (tùy chọn)</p>
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div>
              <label class="block text-xs text-gray-500 mb-1">Khách hàng</label>
              <select v-model="form.customer_id"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                <option :value="null">-- Không --</option>
                <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Đơn hàng</label>
              <select v-model="form.order_id"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                <option :value="null">-- Không --</option>
                <option v-for="o in orders" :key="o.id" :value="o.id">{{ o.code }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Dự án</label>
              <select v-model="form.project_id"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                <option :value="null">-- Không --</option>
                <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Ghi chú -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Lý do / Ghi chú</label>
          <textarea v-model="form.notes" rows="3"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"></textarea>
        </div>

        <div class="flex justify-end gap-3 pt-2">
          <Link :href="route('sales.commissions.index')"
            class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium">
            Hủy
          </Link>
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded-lg text-sm font-medium disabled:opacity-50">
            {{ commission ? 'Cập nhật' : 'Tạo đề xuất' }}
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  commission: Object,
  nextCode:   String,
  types:      Array,
  customers:  Array,
  orders:     Array,
  projects:   Array,
});

const form = useForm({
  code:           props.commission?.code           ?? props.nextCode,
  type:           props.commission?.type           ?? '',
  customer_id:    props.commission?.customer_id    ?? null,
  order_id:       props.commission?.order_id       ?? null,
  project_id:     props.commission?.project_id     ?? null,
  recipient_name: props.commission?.recipient_name ?? '',
  recipient_info: props.commission?.recipient_info ?? '',
  amount:         props.commission?.amount         ?? 0,
  rate:           props.commission?.rate           ?? null,
  payment_method: props.commission?.payment_method ?? 'bank_transfer',
  planned_date:   props.commission?.planned_date   ?? '',
  notes:          props.commission?.notes          ?? '',
});

function submit() {
  if (props.commission) {
    form.put(route('sales.commissions.update', props.commission.id));
  } else {
    form.post(route('sales.commissions.store'));
  }
}
</script>
