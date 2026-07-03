<template>
  <AppLayout>
    <div class="max-w-3xl space-y-6">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <h1 class="text-2xl font-bold text-gray-900">Cài đặt công ty</h1>
      </div>

      <form @submit.prevent="submit" enctype="multipart/form-data" class="space-y-6">
        <!-- Logo -->
        <div class="bg-white rounded-xl border border-gray-200 p-6">
          <h2 class="text-base font-semibold text-gray-800 mb-4">Logo công ty</h2>
          <div class="flex items-start gap-6">
            <div class="flex-shrink-0">
              <div v-if="currentLogo" class="w-24 h-24 rounded-xl border border-gray-200 overflow-x-auto bg-gray-50 flex items-center justify-center">
                <img :src="currentLogo" alt="Logo" class="w-full h-full object-contain p-2" />
              </div>
              <div v-else class="w-24 h-24 rounded-xl border-2 border-dashed border-gray-300 flex items-center justify-center bg-gray-50">
                <svg class="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
              </div>
            </div>
            <div class="flex-1">
              <label class="block text-sm font-medium text-gray-700 mb-1">Tải lên logo mới</label>
              <input type="file" accept="image/png,image/jpeg,image/jpg,image/svg+xml"
                class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100"
                @change="onLogoChange" />
              <p class="mt-1 text-xs text-gray-500">PNG, JPG, SVG — tối đa 2MB. Khuyến nghị kích thước vuông (ví dụ 200×200px).</p>
              <p v-if="form.errors.logo" class="mt-1 text-xs text-red-600">{{ form.errors.logo }}</p>
              <div v-if="currentLogo" class="mt-3">
                <button type="button" @click="confirmDeleteLogo"
                  class="text-xs text-red-600 hover:text-red-800 underline">
                  Xoá logo hiện tại
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Thông tin công ty -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <h2 class="text-base font-semibold text-gray-800">Thông tin công ty</h2>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tên công ty <span class="text-red-500">*</span></label>
            <input v-model="form.company_name" type="text"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            <p v-if="form.errors.company_name" class="mt-1 text-xs text-red-600">{{ form.errors.company_name }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mô tả ngắn</label>
            <input v-model="form.company_description" type="text"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Mã số thuế</label>
              <input v-model="form.company_tax_code" type="text"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Điện thoại</label>
              <input v-model="form.company_phone" type="text"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
              <input v-model="form.company_email" type="email"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Website</label>
              <input v-model="form.company_website" type="text"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Địa chỉ</label>
            <textarea v-model="form.company_address" rows="2"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"></textarea>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Địa danh ký báo cáo</label>
            <input v-model="form.report_signing_place" type="text" placeholder="Vd: Hải Phòng"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            <p class="mt-1 text-xs text-gray-500">Dùng cho dòng "Địa danh, ngày ... tháng ... năm ..." ở phần chữ ký của báo cáo/PDF. Chỉ điền tên tỉnh/thành, không điền địa chỉ đầy đủ.</p>
          </div>
        </div>

        <!-- Thông tin ngân hàng -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <h2 class="text-base font-semibold text-gray-800">Thông tin ngân hàng</h2>
          <p class="text-xs text-gray-500">Thông tin này sẽ hiển thị trên hóa đơn và báo giá PDF.</p>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tên ngân hàng</label>
            <input v-model="form.company_bank_name" type="text"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
              placeholder="Vd: Vietcombank" />
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Số tài khoản</label>
              <input v-model="form.company_bank_account" type="text"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Chi nhánh</label>
              <input v-model="form.company_bank_branch" type="text"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            </div>
          </div>
        </div>

        <div class="flex justify-end">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded-lg text-sm font-medium disabled:opacity-50">
            {{ form.processing ? 'Đang lưu...' : 'Lưu cài đặt' }}
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  settings: Object,
});

const currentLogo = computed(() => props.settings.company_logo || null);

const form = useForm({
  company_name:         props.settings.company_name        ?? '',
  company_address:      props.settings.company_address     ?? '',
  company_phone:        props.settings.company_phone       ?? '',
  company_email:        props.settings.company_email       ?? '',
  company_tax_code:     props.settings.company_tax_code    ?? '',
  company_website:      props.settings.company_website     ?? '',
  company_description:  props.settings.company_description ?? '',
  company_bank_name:    props.settings.company_bank_name   ?? '',
  company_bank_account: props.settings.company_bank_account ?? '',
  company_bank_branch:  props.settings.company_bank_branch  ?? '',
  report_signing_place: props.settings.report_signing_place ?? '',
  logo: null,
});

function onLogoChange(e) {
  form.logo = e.target.files[0] ?? null;
}

function submit() {
  form.post(route('admin.settings.update'), {
    forceFormData: true,
    onSuccess: () => {
      form.logo = null;
    },
  });
}

function confirmDeleteLogo() {
  if (confirm('Bạn có chắc muốn xoá logo hiện tại không?')) {
    router.delete(route('admin.settings.logo.delete'));
  }
}
</script>
