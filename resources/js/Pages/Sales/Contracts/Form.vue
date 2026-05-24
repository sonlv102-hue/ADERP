<template>
  <AppLayout>
    <div class="max-w-3xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('sales.contracts.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ isEdit ? 'Sửa hợp đồng' : 'Tạo hợp đồng' }}</h1>
      </div>

      <form @submit.prevent="submit" class="space-y-5">
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Mã hợp đồng <span class="text-red-500">*</span></label>
              <input v-model="form.code" type="text" :readonly="isEdit"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.code, 'bg-gray-50': isEdit }" />
              <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Khách hàng <span class="text-red-500">*</span></label>
              <select v-model="form.customer_id"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.customer_id }">
                <option value="">-- Chọn khách hàng --</option>
                <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}</option>
              </select>
              <p v-if="form.errors.customer_id" class="mt-1 text-xs text-red-600">{{ form.errors.customer_id }}</p>
            </div>

            <div class="sm:col-span-2">
              <label class="block text-sm font-medium text-gray-700 mb-1">Tiêu đề hợp đồng <span class="text-red-500">*</span></label>
              <input v-model="form.title" type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.title }" />
              <p v-if="form.errors.title" class="mt-1 text-xs text-red-600">{{ form.errors.title }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Đơn hàng liên kết</label>
              <select v-model="form.order_id"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :disabled="!form.customer_id">
                <option :value="null">-- Không có --</option>
                <option v-for="o in filteredOrders" :key="o.id" :value="o.id">{{ o.code }}</option>
              </select>
              <p v-if="!form.customer_id" class="mt-1 text-xs text-gray-400">Chọn khách hàng trước</p>
              <p v-else-if="filteredOrders.length === 0" class="mt-1 text-xs text-gray-400">Khách hàng này chưa có đơn hàng</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Giá trị hợp đồng <span class="text-red-500">*</span></label>
              <input v-model.number="form.value" type="number" min="0"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.value }" />
              <p v-if="form.errors.value" class="mt-1 text-xs text-red-600">{{ form.errors.value }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ngày bắt đầu</label>
              <input v-model="form.start_date" type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ngày kết thúc</label>
              <input v-model="form.end_date" type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.end_date }" />
              <p v-if="form.errors.end_date" class="mt-1 text-xs text-red-600">{{ form.errors.end_date }}</p>
            </div>

            <div class="sm:col-span-2">
              <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
              <textarea v-model="form.notes" rows="3"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
            </div>
          </div>
        </div>

        <div class="flex gap-3">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : (isEdit ? 'Cập nhật' : 'Tạo hợp đồng') }}
          </button>
          <Link :href="route('sales.contracts.index')"
            class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  contract: Object,
  nextCode: String,
  customers: Array,
  orders: Array,
});

const isEdit = !!props.contract;

const form = useForm({
  code:        props.contract?.code ?? props.nextCode ?? '',
  customer_id: props.contract?.customer_id ?? '',
  order_id:    props.contract?.order_id ?? null,
  title:       props.contract?.title ?? '',
  value:       props.contract?.value ?? 0,
  start_date:  props.contract?.start_date ?? '',
  end_date:    props.contract?.end_date ?? '',
  notes:       props.contract?.notes ?? '',
});

const filteredOrders = computed(() => {
  if (!form.customer_id) return [];
  return props.orders.filter(o => o.customer_id == form.customer_id);
});

watch(() => form.customer_id, () => {
  const belongs = filteredOrders.value.some(o => o.id === form.order_id);
  if (!belongs) {
    form.order_id = null;
  }
});

watch(() => form.order_id, (newId) => {
  if (newId) {
    const order = props.orders.find(o => o.id === newId);
    if (order?.total !== undefined) {
      form.value = order.total;
    }
  }
});

const submit = () => {
  if (isEdit) {
    form.put(route('sales.contracts.update', props.contract.id));
  } else {
    form.post(route('sales.contracts.store'));
  }
};
</script>
