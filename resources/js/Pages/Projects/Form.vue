<template>
  <AppLayout>
    <div class="max-w-3xl mx-auto space-y-6">
      <div class="flex items-center gap-3">
        <Link :href="route('projects.projects.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ isEdit ? 'Sửa dự án' : 'Tạo dự án mới' }}</h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <!-- Thông tin cơ bản -->
        <div class="grid grid-cols-2 gap-5">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mã dự án</label>
            <input v-model="form.code" :disabled="isEdit" type="text"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm disabled:bg-gray-50" />
            <p v-if="form.errors.code" class="text-red-500 text-xs mt-1">{{ form.errors.code }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tên dự án <span class="text-red-500">*</span></label>
            <input v-model="form.name" type="text"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
            <p v-if="form.errors.name" class="text-red-500 text-xs mt-1">{{ form.errors.name }}</p>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-5">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Khách hàng <span class="text-red-500">*</span></label>
            <select v-model="form.customer_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
              <option value="">-- Chọn khách hàng --</option>
              <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <p v-if="form.errors.customer_id" class="text-red-500 text-xs mt-1">{{ form.errors.customer_id }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Hợp đồng liên kết</label>
            <select v-model="form.contract_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
              <option :value="null">-- Không --</option>
              <option v-for="c in contracts" :key="c.id" :value="c.id">{{ c.code }} — {{ c.title }}</option>
            </select>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Địa điểm thi công</label>
          <input v-model="form.location" type="text"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Người phụ trách</label>
          <select v-model="form.manager_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <option :value="null">-- Chọn người phụ trách --</option>
            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
          </select>
        </div>

        <div class="grid grid-cols-3 gap-5">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày bắt đầu</label>
            <input v-model="form.start_date" type="date"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày dự kiến HT</label>
            <input v-model="form.expected_end_date" type="date"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngân sách (đ)</label>
            <input v-model="form.budget" type="number" min="0" step="1000"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
          <textarea v-model="form.notes" rows="3"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
        </div>

        <div class="flex gap-3 pt-2">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white px-6 py-2 rounded-lg text-sm font-medium">
            {{ isEdit ? 'Lưu thay đổi' : 'Tạo dự án' }}
          </button>
          <Link :href="route('projects.projects.index')"
            class="border border-gray-300 text-gray-700 hover:bg-gray-50 px-6 py-2 rounded-lg text-sm font-medium">
            Hủy
          </Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  project: Object,
  nextCode: String,
  customers: Array,
  contracts: Array,
  users: Array,
  statuses: Array,
});

const isEdit = computed(() => !!props.project?.id);

const form = useForm({
  code:              props.project?.code ?? props.nextCode,
  name:              props.project?.name ?? '',
  customer_id:       props.project?.customer_id ?? '',
  contract_id:       props.project?.contract_id ?? null,
  location:          props.project?.location ?? '',
  manager_id:        props.project?.manager_id ?? null,
  start_date:        props.project?.start_date ?? '',
  expected_end_date: props.project?.expected_end_date ?? '',
  budget:            props.project?.budget ?? 0,
  notes:             props.project?.notes ?? '',
});

const submit = () => {
  if (isEdit.value) {
    form.put(route('projects.projects.update', props.project.id));
  } else {
    form.post(route('projects.projects.store'));
  }
};
</script>
