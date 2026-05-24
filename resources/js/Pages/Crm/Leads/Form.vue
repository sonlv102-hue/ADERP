<template>
  <AppLayout>
    <div class="max-w-3xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('crm.leads.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ lead ? 'Sửa Lead' : 'Thêm Lead mới' }}</h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tên liên hệ <span class="text-red-500">*</span></label>
            <input v-model="form.full_name" type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.full_name }" />
            <p v-if="form.errors.full_name" class="mt-1 text-xs text-red-600">{{ form.errors.full_name }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Công ty</label>
            <input v-model="form.company_name" type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.company_name }" />
            <p v-if="form.errors.company_name" class="mt-1 text-xs text-red-600">{{ form.errors.company_name }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Số điện thoại</label>
            <input v-model="form.phone" type="tel"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.phone }" />
            <p v-if="form.errors.phone" class="mt-1 text-xs text-red-600">{{ form.errors.phone }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <input v-model="form.email" type="email"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.email }" />
            <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nguồn</label>
            <select v-model="form.source"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.source }">
              <option value="">-- Chọn nguồn --</option>
              <option value="website">Website</option>
              <option value="referral">Giới thiệu</option>
              <option value="cold-call">Cold Call</option>
              <option value="event">Sự kiện</option>
              <option value="other">Khác</option>
            </select>
            <p v-if="form.errors.source" class="mt-1 text-xs text-red-600">{{ form.errors.source }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Trạng thái <span class="text-red-500">*</span></label>
            <select v-model="form.status"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.status }">
              <option value="">-- Chọn trạng thái --</option>
              <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
            <p v-if="form.errors.status" class="mt-1 text-xs text-red-600">{{ form.errors.status }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Phụ trách</label>
            <select v-model="form.assigned_to"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.assigned_to }">
              <option :value="null">-- Không có --</option>
              <option v-for="user in sales_users" :key="user.id" :value="user.id">{{ user.name }}</option>
            </select>
            <p v-if="form.errors.assigned_to" class="mt-1 text-xs text-red-600">{{ form.errors.assigned_to }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày follow-up</label>
            <input v-model="form.next_follow_up" type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.next_follow_up }" />
            <p v-if="form.errors.next_follow_up" class="mt-1 text-xs text-red-600">{{ form.errors.next_follow_up }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Giá trị dự kiến (VNĐ)</label>
            <input v-model="form.expected_value" type="number" min="0" step="1000"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.expected_value }" />
            <p v-if="form.errors.expected_value" class="mt-1 text-xs text-red-600">{{ form.errors.expected_value }}</p>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
          <textarea v-model="form.notes" rows="3"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
            :class="{ 'border-red-500': form.errors.notes }" />
          <p v-if="form.errors.notes" class="mt-1 text-xs text-red-600">{{ form.errors.notes }}</p>
        </div>

        <div class="flex gap-3 pt-2">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : (lead ? 'Cập nhật' : 'Thêm Lead') }}
          </button>
          <Link :href="route('crm.leads.index')"
            class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  lead: { type: Object, default: null },
  nextCode: String,
  statuses: Array,
  sales_users: Array,
});

const form = useForm({
  full_name:      props.lead?.full_name ?? '',
  company_name:   props.lead?.company_name ?? '',
  phone:          props.lead?.phone ?? '',
  email:          props.lead?.email ?? '',
  source:         props.lead?.source ?? '',
  assigned_to:    props.lead?.assigned_to ?? null,
  status:         props.lead?.status ?? 'new',
  next_follow_up: props.lead?.next_follow_up ?? '',
  expected_value: props.lead?.expected_value ?? '',
  notes:          props.lead?.notes ?? '',
});

const submit = () => {
  if (props.lead) {
    form.put(route('crm.leads.update', props.lead.id));
  } else {
    form.post(route('crm.leads.store'));
  }
};
</script>
