<template>
  <AppLayout>
    <div class="max-w-2xl mx-auto space-y-6">
      <div class="flex items-center gap-3">
        <Link :href="route('support.warranties.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ warranty ? 'Sửa bảo hành' : 'Tạo bảo hành' }}</h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mã bảo hành</label>
            <input v-model="form.code" type="text" :disabled="!!warranty"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 disabled:bg-gray-50" />
            <p v-if="errors.code" class="text-red-500 text-xs mt-1">{{ errors.code }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Khách hàng <span class="text-red-500">*</span></label>
            <select v-model="form.customer_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500">
              <option value="">-- Chọn khách hàng --</option>
              <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <p v-if="errors.customer_id" class="text-red-500 text-xs mt-1">{{ errors.customer_id }}</p>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Sản phẩm <span class="text-red-500">*</span></label>
            <select v-model="form.product_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500">
              <option value="">-- Chọn sản phẩm --</option>
              <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <p v-if="errors.product_id" class="text-red-500 text-xs mt-1">{{ errors.product_id }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Số serial</label>
            <input v-model="form.serial_number" type="text" placeholder="Serial number thiết bị"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500" />
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày bắt đầu <span class="text-red-500">*</span></label>
            <input v-model="form.start_date" type="date"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500" />
            <p v-if="errors.start_date" class="text-red-500 text-xs mt-1">{{ errors.start_date }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Thời hạn (tháng) <span class="text-red-500">*</span></label>
            <input v-model="form.duration_months" type="number" min="1" max="120"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500" />
            <p v-if="errors.duration_months" class="text-red-500 text-xs mt-1">{{ errors.duration_months }}</p>
            <p v-if="endDatePreview" class="text-xs text-gray-500 mt-1">Hết hạn: {{ endDatePreview }}</p>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Đơn hàng liên quan</label>
          <select v-model="form.order_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500">
            <option value="">-- Không liên kết --</option>
            <option v-for="o in orders" :key="o.id" :value="o.id">{{ o.code }}</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Điều khoản bảo hành</label>
          <textarea v-model="form.terms" rows="3" placeholder="Mô tả điều khoản, phạm vi bảo hành..."
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500" />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
          <textarea v-model="form.notes" rows="2"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500" />
        </div>

        <div class="flex justify-end gap-3 pt-2">
          <Link :href="route('support.warranties.index')"
            class="border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium">
            Hủy
          </Link>
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 text-white px-5 py-2 rounded-lg text-sm font-medium disabled:opacity-60">
            {{ warranty ? 'Lưu thay đổi' : 'Tạo bảo hành' }}
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  warranty: Object,
  nextCode: String,
  customers: Array,
  orders: Array,
  products: Array,
});

const form = useForm({
  code:            props.warranty?.code             ?? props.nextCode,
  customer_id:     props.warranty?.customer_id      ?? '',
  order_id:        props.warranty?.order_id         ?? '',
  product_id:      props.warranty?.product_id       ?? '',
  serial_number:   props.warranty?.serial_number    ?? '',
  start_date:      props.warranty?.start_date       ?? '',
  duration_months: props.warranty?.duration_months  ?? 12,
  terms:           props.warranty?.terms            ?? '',
  notes:           props.warranty?.notes            ?? '',
});

const errors = form.errors;

const endDatePreview = computed(() => {
  if (!form.start_date || !form.duration_months) return null;
  const d = new Date(form.start_date);
  d.setMonth(d.getMonth() + parseInt(form.duration_months));
  return d.toLocaleDateString('vi-VN');
});

function submit() {
  if (props.warranty) {
    form.put(route('support.warranties.update', props.warranty.id));
  } else {
    form.post(route('support.warranties.store'));
  }
}
</script>
