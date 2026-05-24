<template>
  <AppLayout>
    <div class="max-w-2xl space-y-5">
      <!-- Header -->
      <div class="flex items-center gap-3">
        <Link :href="route('documents.documents.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ document ? 'Chỉnh sửa chứng từ' : 'Tải lên chứng từ mới' }}</h1>
      </div>

      <form @submit.prevent="submit" enctype="multipart/form-data" class="space-y-5">
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <!-- Mã -->
          <div v-if="!document">
            <label class="block text-sm font-medium text-gray-700 mb-1">Mã chứng từ</label>
            <input :value="next_code" disabled
              class="w-full border border-gray-200 bg-gray-50 rounded-lg px-3 py-2 text-sm text-gray-500" />
          </div>

          <!-- Tiêu đề -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tiêu đề <span class="text-red-500">*</span></label>
            <input v-model="form.title" type="text" placeholder="Vd: Hợp đồng mua bán máy chủ Dell 2025"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            <p v-if="form.errors.title" class="mt-1 text-xs text-red-600">{{ form.errors.title }}</p>
          </div>

          <!-- Loại + Trạng thái -->
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Loại chứng từ <span class="text-red-500">*</span></label>
              <select v-model="form.document_type_id"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                <option value="">-- Chọn loại --</option>
                <option v-for="t in document_types" :key="t.id" :value="t.id">{{ t.name }}</option>
              </select>
              <p v-if="form.errors.document_type_id" class="mt-1 text-xs text-red-600">{{ form.errors.document_type_id }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Trạng thái</label>
              <select v-model="form.status"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
              </select>
            </div>
          </div>

          <!-- Ngày phát hành + Hết hạn -->
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ngày phát hành</label>
              <input v-model="form.issued_date" type="date"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ngày hết hạn</label>
              <input v-model="form.expired_date" type="date"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            </div>
          </div>

          <!-- File -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">File đính kèm</label>
            <div v-if="document?.file_name" class="mb-2 text-sm text-gray-600 flex items-center gap-2">
              <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
              </svg>
              File hiện tại: <span class="font-medium">{{ document.file_name }}</span>
              <span class="text-gray-400">(tải lên file mới để thay thế)</span>
            </div>
            <input type="file" @change="onFileChange"
              class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100" />
            <p class="mt-1 text-xs text-gray-500">PDF, Word, Excel, hình ảnh — tối đa 20MB</p>
            <p v-if="form.errors.file" class="mt-1 text-xs text-red-600">{{ form.errors.file }}</p>
          </div>

          <!-- Ghi chú -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
            <textarea v-model="form.note" rows="2"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
              placeholder="Mô tả ngắn về chứng từ này..."></textarea>
          </div>
        </div>

        <!-- Gắn với nghiệp vụ (chỉ hiện khi tạo mới) -->
        <div v-if="!document" class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <h2 class="text-base font-semibold text-gray-800">Gắn với nghiệp vụ <span class="text-gray-400 font-normal text-sm">(tuỳ chọn)</span></h2>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Loại nghiệp vụ</label>
              <select v-model="form.related_type"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                <option value="">-- Không gắn --</option>
                <option v-for="r in related_types" :key="r.value" :value="r.value">{{ r.label }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">ID nghiệp vụ</label>
              <input v-model="form.related_id" type="number" min="1" placeholder="Nhập ID..."
                :disabled="!form.related_type"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:bg-gray-50 disabled:text-gray-400" />
              <p class="mt-1 text-xs text-gray-500">Nhập ID của đơn hàng/khách hàng/dự án...</p>
            </div>
          </div>
        </div>

        <div class="flex gap-3 justify-end">
          <Link :href="route('documents.documents.index')"
            class="border border-gray-300 text-gray-700 px-5 py-2 rounded-lg text-sm font-medium hover:bg-gray-50">
            Huỷ
          </Link>
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded-lg text-sm font-medium disabled:opacity-50">
            {{ form.processing ? 'Đang lưu...' : (document ? 'Cập nhật' : 'Tải lên') }}
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
  document:       Object,
  document_types: Array,
  statuses:       Array,
  related_types:  Array,
  next_code:      String,
});

const form = useForm({
  document_type_id: props.document?.document_type_id ?? '',
  title:            props.document?.title            ?? '',
  issued_date:      props.document?.issued_date      ?? '',
  expired_date:     props.document?.expired_date     ?? '',
  status:           props.document?.status           ?? 'active',
  note:             props.document?.note             ?? '',
  file:             null,
  related_type:     '',
  related_id:       '',
});

function onFileChange(e) {
  form.file = e.target.files[0] ?? null;
}

function submit() {
  if (props.document) {
    form.post(route('documents.documents.update', props.document.id), {
      method: 'put',
      forceFormData: true,
    });
  } else {
    form.post(route('documents.documents.store'), { forceFormData: true });
  }
}
</script>
