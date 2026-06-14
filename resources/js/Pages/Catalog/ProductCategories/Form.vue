<template>
  <AppLayout>
    <div class="max-w-2xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('catalog.product-categories.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ category ? 'Sửa danh mục' : 'Thêm danh mục mới' }}</h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tên danh mục <span class="text-red-500">*</span></label>
            <input v-model="form.name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.name }" />
            <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Danh mục cha</label>
            <select v-model="form.parent_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.parent_id }">
              <option :value="null">-- Không có --</option>
              <option v-for="parent in parents" :key="parent.id" :value="parent.id">{{ parent.name }}</option>
            </select>
            <p v-if="form.errors.parent_id" class="mt-1 text-xs text-red-600">{{ form.errors.parent_id }}</p>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Mô tả</label>
          <textarea v-model="form.description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
            :class="{ 'border-red-500': form.errors.description }" />
          <p v-if="form.errors.description" class="mt-1 text-xs text-red-600">{{ form.errors.description }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">TK doanh thu mặc định cho danh mục</label>
          <select v-model="form.revenue_account_code"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
            :class="{ 'border-red-500': form.errors.revenue_account_code }">
            <option :value="null">-- Dùng mặc định hệ thống --</option>
            <option v-for="a in accounts" :key="a.code" :value="a.code">{{ a.code }} — {{ a.name }}</option>
          </select>
          <p v-if="form.errors.revenue_account_code" class="mt-1 text-xs text-red-600">{{ form.errors.revenue_account_code }}</p>
          <p class="mt-1 text-xs text-gray-400">Khi sản phẩm trong danh mục không có TK doanh thu riêng, hệ thống dùng TK này</p>
        </div>

        <div class="flex gap-3 pt-2">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : (category ? 'Cập nhật' : 'Thêm danh mục') }}
          </button>
          <Link :href="route('catalog.product-categories.index')"
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
  category: { type: Object, default: null },
  parents: Array,
  accounts: { type: Array, default: () => [] },
});

const form = useForm({
  name:                 props.category?.name ?? '',
  parent_id:            props.category?.parent_id ?? null,
  description:          props.category?.description ?? '',
  revenue_account_code: props.category?.revenue_account_code ?? null,
});

const submit = () => {
  if (props.category) {
    form.put(route('catalog.product-categories.update', props.category.id));
  } else {
    form.post(route('catalog.product-categories.store'));
  }
};
</script>
