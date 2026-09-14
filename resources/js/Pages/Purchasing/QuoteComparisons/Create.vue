<template>
  <AppLayout>
    <div class="max-w-2xl space-y-5">
      <div class="flex items-center gap-3">
        <Link :href="route('purchasing.quote-comparisons.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">Tạo đợt so sánh báo giá</h1>
      </div>

      <form class="bg-white rounded-xl border border-gray-200 p-5 space-y-4" @submit.prevent="submit">
        <FormField label="Mã đợt">
          <input :value="nextCode" class="erp-input bg-gray-50" disabled />
        </FormField>

        <FormField label="Tên đợt so sánh" required :error="form.errors.name">
          <input v-model="form.name" class="erp-input" placeholder="VD: So sánh báo giá vật tư thi công CT ABC" />
        </FormField>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <FormField label="Ngày so sánh" required :error="form.errors.comparison_date">
            <input v-model="form.comparison_date" type="date" class="erp-input" />
          </FormField>
          <FormField label="Bộ phận yêu cầu" :error="form.errors.department">
            <input v-model="form.department" class="erp-input" placeholder="VD: Phòng kỹ thuật" />
          </FormField>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <FormField label="Dự án / công trình" :error="form.errors.project_id">
            <RemoteSearchSelect
              v-model="form.project_id"
              :search-url="route('search.projects')"
              placeholder="Tìm dự án..."
              :has-error="!!form.errors.project_id"
            />
          </FormField>
          <FormField label="Người phụ trách mua hàng" :error="form.errors.buyer_id">
            <select v-model="form.buyer_id" class="erp-input">
              <option :value="null">-- Chọn --</option>
              <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
          </FormField>
        </div>

        <FormField label="Ghi chú" :error="form.errors.note">
          <textarea v-model="form.note" rows="3" class="erp-input" />
        </FormField>

        <div class="flex justify-end gap-2 pt-2">
          <Link :href="route('purchasing.quote-comparisons.index')" class="erp-btn-secondary">Hủy</Link>
          <button type="submit" :disabled="form.processing" class="erp-btn-primary">Lưu & tiếp tục</button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import FormField from '@/Components/Shared/FormField.vue';
import RemoteSearchSelect from '@/Components/Shared/RemoteSearchSelect.vue';

defineProps({ nextCode: String, users: { type: Array, default: () => [] } });

const form = useForm({
  name: '',
  comparison_date: new Date().toISOString().slice(0, 10),
  department: '',
  project_id: null,
  buyer_id: null,
  note: '',
});

function submit() {
  form.post(route('purchasing.quote-comparisons.store'));
}
</script>
