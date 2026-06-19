<template>
  <AppLayout :title="term ? 'Sửa điều khoản' : 'Thêm điều khoản'">
    <div class="max-w-xl mx-auto">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('accounting.payment-terms.index')" class="text-gray-500 hover:text-gray-700">←</Link>
        <h1 class="text-2xl font-bold text-gray-900">
          {{ term ? 'Sửa điều khoản thanh toán' : 'Thêm điều khoản thanh toán' }}
        </h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl shadow-sm p-6 space-y-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="form-label">Mã <span class="text-red-500">*</span></label>
            <input v-model="form.code" class="form-input font-mono uppercase"
              :class="{ 'border-red-500': form.errors.code }" placeholder="NET30" />
            <p v-if="form.errors.code" class="form-error">{{ form.errors.code }}</p>
          </div>
          <div>
            <label class="form-label">Số ngày thanh toán <span class="text-red-500">*</span></label>
            <input v-model.number="form.days" type="number" min="0" max="365" class="form-input"
              :class="{ 'border-red-500': form.errors.days }" />
            <p v-if="form.errors.days" class="form-error">{{ form.errors.days }}</p>
          </div>
        </div>

        <div>
          <label class="form-label">Tên <span class="text-red-500">*</span></label>
          <input v-model="form.name" class="form-input"
            :class="{ 'border-red-500': form.errors.name }" placeholder="Net 30 ngày" />
          <p v-if="form.errors.name" class="form-error">{{ form.errors.name }}</p>
        </div>

        <div>
          <label class="form-label">Mô tả</label>
          <input v-model="form.description" class="form-input" placeholder="Thanh toán trong vòng 30 ngày kể từ ngày xuất hoá đơn" />
        </div>

        <div class="flex items-center gap-2">
          <input type="checkbox" id="is_active" v-model="form.is_active" class="rounded" />
          <label for="is_active" class="text-sm text-gray-700">Đang hoạt động</label>
        </div>

        <div class="flex gap-3 pt-2">
          <button type="submit" :disabled="form.processing" class="btn-primary">
            {{ form.processing ? 'Đang lưu...' : (term ? 'Cập nhật' : 'Tạo mới') }}
          </button>
          <Link :href="route('accounting.payment-terms.index')" class="btn-secondary">Huỷ</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({ term: Object });

const form = useForm({
  code:        props.term?.code        ?? '',
  name:        props.term?.name        ?? '',
  days:        props.term?.days        ?? 30,
  description: props.term?.description ?? '',
  is_active:   props.term?.is_active   ?? true,
});

function submit() {
  if (props.term) {
    form.put(route('accounting.payment-terms.update', props.term.id));
  } else {
    form.post(route('accounting.payment-terms.store'));
  }
}
</script>
