<template>
  <AppLayout>
    <div class="max-w-2xl mx-auto space-y-6">
      <div class="flex items-center gap-3">
        <Link :href="route('support.tickets.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ ticket ? 'Sửa ticket' : 'Tạo ticket' }}</h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mã ticket</label>
            <input v-model="form.code" type="text" :disabled="!!ticket"
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

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Tiêu đề <span class="text-red-500">*</span></label>
          <input v-model="form.title" type="text" placeholder="Mô tả ngắn gọn vấn đề..."
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500" />
          <p v-if="errors.title" class="text-red-500 text-xs mt-1">{{ errors.title }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Mô tả chi tiết</label>
          <textarea v-model="form.description" rows="4" placeholder="Mô tả chi tiết sự cố, lỗi..."
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500" />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mức độ ưu tiên</label>
            <select v-model="form.priority" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500">
              <option v-for="p in priorities" :key="p.value" :value="p.value">{{ p.label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Danh mục</label>
            <select v-model="form.category" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500">
              <option value="">-- Chọn danh mục --</option>
              <option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Người phụ trách</label>
            <select v-model="form.assigned_to" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500">
              <option value="">-- Chưa phân công --</option>
              <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Hạn xử lý</label>
            <input v-model="form.due_date" type="date"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500" />
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Đơn hàng liên quan</label>
            <select v-model="form.order_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500">
              <option value="">-- Không liên kết --</option>
              <option v-for="o in orders" :key="o.id" :value="o.id">{{ o.code }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Hợp đồng liên quan</label>
            <select v-model="form.contract_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500">
              <option value="">-- Không liên kết --</option>
              <option v-for="c in contracts" :key="c.id" :value="c.id">{{ c.code }} – {{ c.title }}</option>
            </select>
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-2">
          <Link :href="route('support.tickets.index')"
            class="border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium">
            Hủy
          </Link>
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 text-white px-5 py-2 rounded-lg text-sm font-medium disabled:opacity-60">
            {{ ticket ? 'Lưu thay đổi' : 'Tạo ticket' }}
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
  ticket: Object,
  nextCode: String,
  customers: Array,
  users: Array,
  orders: Array,
  contracts: Array,
  statuses: Array,
  priorities: Array,
  categories: Array,
});

const form = useForm({
  code:        props.ticket?.code        ?? props.nextCode,
  title:       props.ticket?.title       ?? '',
  description: props.ticket?.description ?? '',
  customer_id: props.ticket?.customer_id ?? '',
  order_id:    props.ticket?.order_id    ?? '',
  contract_id: props.ticket?.contract_id ?? '',
  assigned_to: props.ticket?.assigned_to ?? '',
  priority:    props.ticket?.priority    ?? 'medium',
  category:    props.ticket?.category    ?? '',
  due_date:    props.ticket?.due_date    ?? '',
});

const errors = form.errors;

function submit() {
  if (props.ticket) {
    form.put(route('support.tickets.update', props.ticket.id));
  } else {
    form.post(route('support.tickets.store'));
  }
}
</script>
