<template>
  <AppLayout>
    <div class="max-w-2xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('warehouse.suppliers.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ supplier ? 'Sửa nhà cung cấp' : 'Thêm nhà cung cấp mới' }}</h1>
      </div>

      <form @submit.prevent="submit" class="space-y-5">

        <!-- Thông tin cơ bản -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
          <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Thông tin cơ bản</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Mã nhà cung cấp</label>
              <input v-model="form.code" type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.code }" />
              <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tên nhà cung cấp <span class="text-red-500">*</span></label>
              <input v-model="form.name" type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.name }" />
              <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Mã số thuế</label>
              <input v-model="form.tax_code" type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.tax_code }" />
              <p v-if="form.errors.tax_code" class="mt-1 text-xs text-red-600">{{ form.errors.tax_code }}</p>
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
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Địa chỉ</label>
            <textarea v-model="form.address" rows="2"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.address }" />
            <p v-if="form.errors.address" class="mt-1 text-xs text-red-600">{{ form.errors.address }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
            <textarea v-model="form.notes" rows="2"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.notes }" />
            <p v-if="form.errors.notes" class="mt-1 text-xs text-red-600">{{ form.errors.notes }}</p>
          </div>

          <div v-if="supplier" class="flex items-center gap-2">
            <input v-model="form.is_active" id="is_active" type="checkbox"
              class="h-4 w-4 text-primary-600 rounded border-gray-300" />
            <label for="is_active" class="text-sm text-gray-700">Nhà cung cấp đang hoạt động</label>
          </div>
        </div>

        <!-- Điều khoản thanh toán -->
        <div class="bg-white rounded-xl border border-gray-200 p-6">
          <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-4">Điều khoản</h2>
          <div class="max-w-xs">
            <label class="block text-sm font-medium text-gray-700 mb-1">Điều khoản thanh toán</label>
            <select v-model="form.payment_term_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none">
              <option :value="null">-- Không có --</option>
              <option v-for="pt in payment_terms" :key="pt.id" :value="pt.id">{{ pt.name }}</option>
            </select>
          </div>
        </div>

        <!-- Tài khoản công nợ phải trả -->
        <div class="bg-white rounded-xl border border-gray-200 p-6">
          <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-1">Tài khoản công nợ phải trả</h2>
          <p class="text-xs text-gray-400 mb-4">Dùng để hạch toán bút toán Cr khi nhập kho, thanh toán NCC. Chỉ chọn tài khoản chi tiết.</p>
          <div class="max-w-xs">
            <label class="block text-sm font-medium text-gray-700 mb-1">
              Tài khoản phải trả <span class="text-red-500">*</span>
            </label>
            <select v-model="form.payable_account_code"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.payable_account_code }">
              <option :value="null" disabled>-- Chọn tài khoản --</option>
              <option v-for="ac in payable_accounts" :key="ac.code" :value="ac.code">{{ ac.label }}</option>
            </select>
            <p v-if="form.errors.payable_account_code" class="mt-1 text-xs text-red-600">{{ form.errors.payable_account_code }}</p>
            <p v-if="form.payable_account_code" class="mt-1 text-xs text-green-600">
              Bút toán sẽ dùng TK {{ form.payable_account_code }}
            </p>
          </div>
        </div>

        <!-- Thông tin ngân hàng -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
          <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Tài khoản ngân hàng</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tên ngân hàng</label>
              <input v-model="form.bank_name" type="text" placeholder="VD: Vietcombank, Techcombank..."
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.bank_name }" />
              <p v-if="form.errors.bank_name" class="mt-1 text-xs text-red-600">{{ form.errors.bank_name }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Số tài khoản</label>
              <input v-model="form.bank_account" type="text" placeholder="VD: 1234567890"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none font-mono"
                :class="{ 'border-red-500': form.errors.bank_account }" />
              <p v-if="form.errors.bank_account" class="mt-1 text-xs text-red-600">{{ form.errors.bank_account }}</p>
            </div>

            <div class="sm:col-span-2">
              <label class="block text-sm font-medium text-gray-700 mb-1">Tên chủ tài khoản</label>
              <input v-model="form.bank_account_name" type="text" placeholder="VD: CONG TY TNHH ABC"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none uppercase"
                :class="{ 'border-red-500': form.errors.bank_account_name }" />
              <p v-if="form.errors.bank_account_name" class="mt-1 text-xs text-red-600">{{ form.errors.bank_account_name }}</p>
            </div>

            <div class="sm:col-span-2">
              <label class="block text-sm font-medium text-gray-700 mb-1">Chi nhánh ngân hàng</label>
              <input v-model="form.bank_branch" type="text" placeholder="VD: Chi nhánh TP.HCM"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.bank_branch }" />
              <p v-if="form.errors.bank_branch" class="mt-1 text-xs text-red-600">{{ form.errors.bank_branch }}</p>
            </div>
          </div>
        </div>

        <!-- Tài khoản NH đăng ký (chỉ khi edit) -->
        <div v-if="supplier" class="bg-white rounded-xl border border-gray-200 p-6">
          <div class="flex items-center justify-between mb-4">
            <div>
              <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Tài khoản NH đăng ký (đối chiếu tự động)</h2>
              <p class="text-xs text-gray-400 mt-0.5">Dùng để nhận dạng giao dịch thanh toán NCC khi import sao kê ngân hàng.</p>
            </div>
            <button type="button" @click="openBankAdd" class="text-sm text-primary-600 hover:text-primary-800 font-medium flex items-center gap-1">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
              Thêm TK
            </button>
          </div>
          <div v-if="bankAccounts.length" class="space-y-2">
            <div v-for="ba in bankAccounts" :key="ba.id"
              class="flex items-center gap-3 p-3 rounded-lg border"
              :class="ba.is_primary ? 'border-primary-200 bg-primary-50' : 'border-gray-100 bg-gray-50'">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <span class="font-mono font-semibold text-sm text-gray-800">{{ ba.account_number }}</span>
                  <span v-if="ba.is_primary" class="text-xs bg-primary-100 text-primary-700 px-1.5 py-0.5 rounded font-medium">Mặc định</span>
                  <span v-if="!ba.is_active" class="text-xs bg-gray-200 text-gray-500 px-1.5 py-0.5 rounded">Ngưng</span>
                </div>
                <div class="text-xs text-gray-500 mt-0.5">{{ ba.bank_name }} <span v-if="ba.account_name">· {{ ba.account_name }}</span></div>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <button v-if="!ba.is_primary" type="button" @click="setPrimaryBank(ba)"
                  class="text-xs text-gray-400 hover:text-primary-600">Đặt mặc định</button>
                <button type="button" @click="openBankEdit(ba)" class="text-xs text-blue-600 hover:text-blue-800">Sửa</button>
                <button type="button" @click="deleteBank(ba)" class="text-xs text-red-500 hover:text-red-700">Xóa</button>
              </div>
            </div>
          </div>
          <p v-else class="text-sm text-gray-400 text-center py-4">Chưa có tài khoản nào đăng ký</p>
        </div>

        <div class="flex gap-3">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : (supplier ? 'Cập nhật' : 'Thêm nhà cung cấp') }}
          </button>
          <Link :href="route('warehouse.suppliers.index')"
            class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</Link>
        </div>
      </form>
    </div>

    <!-- Bank account modal -->
    <div v-if="showBankForm" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md">
        <h3 class="text-lg font-bold text-gray-900 mb-4">{{ editingBank ? 'Sửa tài khoản NH' : 'Thêm tài khoản NH' }}</h3>
        <div class="space-y-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngân hàng <span class="text-red-500">*</span></label>
            <input v-model="bankForm.bank_name" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500" placeholder="Techcombank, VCB..." />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Số tài khoản <span class="text-red-500">*</span></label>
            <input v-model="bankForm.account_number" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none font-mono focus:ring-2 focus:ring-primary-500" placeholder="19036130647011" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tên chủ tài khoản</label>
            <input v-model="bankForm.account_name" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500 uppercase" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Chi nhánh</label>
            <input v-model="bankForm.branch" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
          <div class="flex items-center gap-2">
            <input type="checkbox" v-model="bankForm.is_primary" id="bank_primary" class="rounded" />
            <label for="bank_primary" class="text-sm text-gray-700">Đặt làm tài khoản mặc định</label>
          </div>
        </div>
        <div class="flex gap-3 mt-5">
          <button @click="submitBankForm" class="bg-primary-600 text-white px-5 py-2 rounded-lg text-sm font-medium flex-1">
            {{ editingBank ? 'Lưu' : 'Thêm' }}
          </button>
          <button @click="showBankForm = false" class="border border-gray-300 px-5 py-2 rounded-lg text-sm text-gray-700">Hủy</button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  supplier:         { type: Object, default: null },
  nextCode:         String,
  payment_terms:    { type: Array, default: () => [] },
  payable_accounts: { type: Array, default: () => [] },
  bankAccounts:     { type: Array, default: () => [] },
});

const form = useForm({
  code:              props.supplier?.code              ?? props.nextCode ?? '',
  name:              props.supplier?.name              ?? '',
  tax_code:          props.supplier?.tax_code          ?? '',
  phone:             props.supplier?.phone             ?? '',
  email:             props.supplier?.email             ?? '',
  address:           props.supplier?.address           ?? '',
  bank_name:             props.supplier?.bank_name             ?? '',
  bank_account:          props.supplier?.bank_account          ?? '',
  bank_account_name:     props.supplier?.bank_account_name     ?? '',
  bank_branch:           props.supplier?.bank_branch           ?? '',
  notes:                 props.supplier?.notes                 ?? '',
  is_active:             props.supplier?.is_active             ?? true,
  payment_term_id:       props.supplier?.payment_term_id       ?? null,
  payable_account_code:  props.supplier?.payable_account_code  ?? null,
});

const submit = () => {
  if (props.supplier) {
    form.put(route('warehouse.suppliers.update', props.supplier.id));
  } else {
    form.post(route('warehouse.suppliers.store'));
  }
};

// ─── Supplier Bank Accounts ───────────────────────────────────────────────
const showBankForm = ref(false);
const editingBank  = ref(null);
const emptyBankForm = () => ({ bank_name: '', account_number: '', account_name: '', branch: '', is_primary: false });
const bankForm = ref(emptyBankForm());

function openBankAdd() { editingBank.value = null; bankForm.value = emptyBankForm(); showBankForm.value = true; }
function openBankEdit(ba) { editingBank.value = ba; bankForm.value = { ...ba }; showBankForm.value = true; }

function submitBankForm() {
  const url = editingBank.value
    ? route('warehouse.suppliers.bank-accounts.update', [props.supplier.id, editingBank.value.id])
    : route('warehouse.suppliers.bank-accounts.store', props.supplier.id);
  const method = editingBank.value ? 'put' : 'post';
  router[method](url, bankForm.value, { onSuccess: () => { showBankForm.value = false; } });
}

function setPrimaryBank(ba) {
  router.post(route('warehouse.suppliers.bank-accounts.set-primary', [props.supplier.id, ba.id]));
}

function deleteBank(ba) {
  if (!confirm(`Xóa TK ${ba.account_number}?`)) return;
  router.delete(route('warehouse.suppliers.bank-accounts.destroy', [props.supplier.id, ba.id]));
}
</script>
