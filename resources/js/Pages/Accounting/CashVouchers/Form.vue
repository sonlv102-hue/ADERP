<template>
  <AppLayout>
    <div class="max-w-2xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('accounting.cash-vouchers.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">
          {{ voucher ? 'Sửa phiếu' : (form.type === 'receipt' ? 'Tạo phiếu thu' : 'Tạo phiếu chi') }}
        </h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mã phiếu</label>
            <input :value="form.code" readonly
              class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-500" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Loại phiếu</label>
            <div class="flex gap-3 mt-1">
              <label class="flex items-center gap-2 cursor-pointer">
                <input type="radio" v-model="form.type" value="receipt" :disabled="!!voucher"
                  class="text-green-600" />
                <span class="text-sm font-medium text-green-700">Phiếu thu</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer">
                <input type="radio" v-model="form.type" value="payment" :disabled="!!voucher"
                  class="text-red-600" />
                <span class="text-sm font-medium text-red-700">Phiếu chi</span>
              </label>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Quỹ <span class="text-red-500">*</span></label>
            <select v-model="form.fund_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.fund_id }">
              <option value="">-- Chọn quỹ --</option>
              <option v-for="f in funds" :key="f.id" :value="f.id">
                {{ f.name }} ({{ f.type === 'cash' ? 'Tiền mặt' : 'Ngân hàng' }})
              </option>
            </select>
            <p v-if="form.errors.fund_id" class="mt-1 text-xs text-red-600">{{ form.errors.fund_id }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày <span class="text-red-500">*</span></label>
            <input v-model="form.voucher_date" type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.voucher_date }" />
            <p v-if="form.errors.voucher_date" class="mt-1 text-xs text-red-600">{{ form.errors.voucher_date }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Số tiền <span class="text-red-500">*</span></label>
            <input v-model.number="form.amount" type="number" min="1" step="any"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.amount }" />
            <p v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</p>
          </div>

          <!-- Đối tác: combobox NCC + free text -->
          <div class="relative">
            <label class="block text-sm font-medium text-gray-700 mb-1">Đối tác</label>
            <div class="relative">
              <input
                v-model="counterpartyInput"
                type="text"
                placeholder="Nhập tên hoặc tìm nhà cung cấp..."
                autocomplete="off"
                class="w-full px-3 py-2 pr-8 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                @focus="showDropdown = true"
                @blur="closeDropdown"
              />
              <button v-if="form.supplier_id" type="button"
                class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 text-lg leading-none"
                @mousedown.prevent="clearSupplier"
                title="Bỏ liên kết NCC">×</button>
            </div>
            <!-- Dropdown danh sách NCC -->
            <ul v-if="showDropdown && filteredSuppliers.length"
              class="absolute z-20 w-full bg-white border border-gray-200 rounded-lg shadow-lg max-h-52 overflow-y-auto mt-1">
              <li v-for="s in filteredSuppliers" :key="s.id"
                @mousedown.prevent="selectSupplier(s)"
                class="px-3 py-2 cursor-pointer hover:bg-primary-50 flex items-center gap-2 text-sm">
                <span class="text-gray-400 font-mono text-xs w-20 shrink-0">{{ s.code }}</span>
                <span class="truncate">{{ s.name }}</span>
              </li>
            </ul>
            <!-- Indicator khi đã chọn NCC -->
            <p v-if="form.supplier_id" class="mt-1 text-xs text-primary-600">
              Đã liên kết NCC — bút toán sẽ dùng TK 331
            </p>
          </div>

          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Diễn giải <span class="text-red-500">*</span></label>
            <input v-model="form.description" type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.description }" />
            <p v-if="form.errors.description" class="mt-1 text-xs text-red-600">{{ form.errors.description }}</p>
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-2">
          <Link :href="route('accounting.cash-vouchers.index')"
            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
            Hủy
          </Link>
          <button type="submit" :disabled="form.processing"
            class="px-5 py-2 text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 rounded-lg disabled:opacity-50">
            {{ voucher ? 'Cập nhật' : 'Lưu phiếu' }}
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  voucher:     Object,
  funds:       Array,
  nextCode:    String,
  defaultType: String,
  suppliers:   Array,
});

const form = useForm({
  code:         props.voucher?.code         ?? props.nextCode,
  type:         props.voucher?.type         ?? props.defaultType ?? 'receipt',
  fund_id:      props.voucher?.fund_id      ?? '',
  amount:       props.voucher?.amount       ?? '',
  voucher_date: props.voucher?.voucher_date ?? new Date().toISOString().slice(0, 10),
  counterparty: props.voucher?.counterparty ?? '',
  supplier_id:  props.voucher?.supplier_id  ?? null,
  description:  props.voucher?.description  ?? '',
});

// ── Combobox NCC ──────────────────────────────────────────────────────────────

const counterpartyInput = ref(props.voucher?.counterparty ?? '');
const showDropdown = ref(false);
// Track selected supplier to detect manual text changes
const selectedSupplier = ref(
  props.suppliers?.find(s => s.id === props.voucher?.supplier_id) ?? null
);

watch(counterpartyInput, (val) => {
  form.counterparty = val;
  // Nếu user tự sửa text khác với tên NCC đã chọn → bỏ liên kết
  if (selectedSupplier.value && val !== selectedSupplier.value.name) {
    form.supplier_id = null;
    selectedSupplier.value = null;
  }
});

const filteredSuppliers = computed(() => {
  if (!props.suppliers?.length) return [];
  const q = counterpartyInput.value.toLowerCase().trim();
  if (!q) return props.suppliers.slice(0, 8);
  return props.suppliers.filter(s =>
    s.name.toLowerCase().includes(q) ||
    s.code.toLowerCase().includes(q)
  ).slice(0, 10);
});

function selectSupplier(s) {
  selectedSupplier.value = s;   // set trước để watch không clear
  form.supplier_id = s.id;
  form.counterparty = s.name;
  counterpartyInput.value = s.name;
  showDropdown.value = false;
}

function clearSupplier() {
  selectedSupplier.value = null;
  form.supplier_id = null;
  form.counterparty = '';
  counterpartyInput.value = '';
}

function closeDropdown() {
  setTimeout(() => { showDropdown.value = false; }, 150);
}

// ─────────────────────────────────────────────────────────────────────────────

function submit() {
  if (props.voucher) {
    form.put(route('accounting.cash-vouchers.update', props.voucher.id));
  } else {
    form.post(route('accounting.cash-vouchers.store'));
  }
}
</script>
