<template>
  <AppLayout>
    <div class="max-w-2xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('catalog.products.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ product ? 'Sửa sản phẩm' : 'Thêm sản phẩm mới' }}</h1>
      </div>

      <form @submit.prevent="submit" class="space-y-5">

        <!-- Thông tin cơ bản -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
          <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Thông tin cơ bản</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Mã sản phẩm</label>
              <input v-model="form.code" type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.code }" />
              <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tên sản phẩm <span class="text-red-500">*</span></label>
              <input v-model="form.name" type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.name }" />
              <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Danh mục</label>
              <select v-model="form.category_id"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.category_id }">
                <option :value="null">-- Không có --</option>
                <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
              </select>
              <p v-if="form.errors.category_id" class="mt-1 text-xs text-red-600">{{ form.errors.category_id }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Đơn vị tính</label>
              <input v-model="form.unit" type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.unit }" />
              <p v-if="form.errors.unit" class="mt-1 text-xs text-red-600">{{ form.errors.unit }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Bảo hành (tháng)</label>
              <input v-model="form.warranty_months" type="number" min="0"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.warranty_months }" />
              <p v-if="form.errors.warranty_months" class="mt-1 text-xs text-red-600">{{ form.errors.warranty_months }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tồn kho tối thiểu</label>
              <input v-model="form.min_stock" type="number" min="0"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.min_stock }" />
              <p v-if="form.errors.min_stock" class="mt-1 text-xs text-red-600">{{ form.errors.min_stock }}</p>
            </div>
          </div>

          <div class="flex items-center gap-2">
            <input v-model="form.has_serial" id="has_serial" type="checkbox"
              class="h-4 w-4 text-primary-600 rounded border-gray-300" />
            <label for="has_serial" class="text-sm text-gray-700">Quản lý theo serial</label>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mô tả</label>
            <textarea v-model="form.description" rows="2"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.description }" />
            <p v-if="form.errors.description" class="mt-1 text-xs text-red-600">{{ form.errors.description }}</p>
          </div>

          <div v-if="product" class="flex items-center gap-2">
            <input v-model="form.is_active" id="is_active" type="checkbox"
              class="h-4 w-4 text-primary-600 rounded border-gray-300" />
            <label for="is_active" class="text-sm text-gray-700">Sản phẩm đang hoạt động</label>
          </div>
        </div>

        <!-- Giá nhập -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Giá nhập</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Giá nhập (đã gồm VAT) <span class="text-red-500">*</span></label>
              <div class="relative">
                <input v-model.number="form.cost_price" type="number" min="0" step="any"
                  class="w-full px-3 py-2 pr-14 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                  :class="{ 'border-red-500': form.errors.cost_price }" />
                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">VNĐ</span>
              </div>
              <p v-if="form.errors.cost_price" class="mt-1 text-xs text-red-600">{{ form.errors.cost_price }}</p>
              <p class="mt-1 text-xs text-gray-400">Tổng tiền thực trả nhà cung cấp, đã bao gồm thuế GTGT</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Chi phí kinh doanh</label>
              <div class="relative">
                <input v-model.number="form.business_cost" type="number" min="0" step="any"
                  class="w-full px-3 py-2 pr-14 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                  :class="{ 'border-red-500': form.errors.business_cost }"
                  placeholder="0" />
                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">VNĐ</span>
              </div>
              <p v-if="form.errors.business_cost" class="mt-1 text-xs text-red-600">{{ form.errors.business_cost }}</p>
              <p class="mt-1 text-xs text-gray-400">Vận chuyển, hải quan, overhead nội bộ...</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Thuế GTGT đầu vào</label>
              <div class="flex gap-2">
                <div class="relative flex-1">
                  <input v-model.number="form.vat_percent" type="number" min="0" max="100" step="0.1"
                    class="w-full px-3 py-2 pr-8 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                    :class="{ 'border-red-500': form.errors.vat_percent }"
                    placeholder="0" />
                  <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">%</span>
                </div>
                <!-- Quick-select buttons -->
                <div class="flex gap-1">
                  <button type="button" v-for="rate in [0, 5, 8, 10]" :key="rate"
                    @click="form.vat_percent = rate"
                    :class="form.vat_percent === rate
                      ? 'bg-primary-600 text-white border-primary-600'
                      : 'bg-white text-gray-600 border-gray-300 hover:border-primary-400'"
                    class="px-2 py-1 border rounded text-xs font-medium transition-colors">
                    {{ rate }}%
                  </button>
                </div>
              </div>
              <p v-if="form.errors.vat_percent" class="mt-1 text-xs text-red-600">{{ form.errors.vat_percent }}</p>
              <p class="mt-1 text-xs text-gray-400">
                Tiền thuế GTGT: {{ formatVnd(vatAmount) }}
                (= giá nhập × {{ form.vat_percent }}% / {{ 100 + form.vat_percent }}%)
              </p>
            </div>

            <!-- Tổng giá nhập — computed, readonly -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Giá vốn</label>
              <div class="relative">
                <input :value="formatVnd(totalCost)" type="text" readonly
                  class="w-full px-3 py-2 pr-3 border border-gray-200 rounded-lg bg-gray-50 text-gray-800 font-semibold outline-none cursor-default" />
              </div>
              <p class="mt-1 text-xs text-gray-400">= Giá nhập (đã VAT) + Chi phí KD — cơ sở tính giá bán</p>
            </div>
          </div>
        </div>

        <!-- Giá bán -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Giá bán</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Biên lợi nhuận <span class="text-red-500">*</span></label>
              <div class="space-y-2">
                <div class="relative">
                  <input v-model.number="marginPercent" type="number" min="0" step="0.1"
                    class="w-full px-3 py-2 pr-8 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                    placeholder="0" />
                  <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">%</span>
                </div>
                <!-- Quick-select buttons -->
                <div class="flex gap-1 flex-wrap">
                  <button type="button" v-for="rate in [10, 15, 20, 25, 30, 40]" :key="rate"
                    @click="marginPercent = rate"
                    :class="marginPercent === rate
                      ? 'bg-primary-600 text-white border-primary-600'
                      : 'bg-white text-gray-600 border-gray-300 hover:border-primary-400'"
                    class="px-2 py-1 border rounded text-xs font-medium transition-colors">
                    {{ rate }}%
                  </button>
                </div>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Giá bán</label>
              <div class="relative">
                <input :value="formatVnd(form.sell_price)" type="text" readonly
                  class="w-full px-3 py-2 pr-3 border border-gray-200 rounded-lg bg-gray-50 text-gray-800 font-semibold outline-none cursor-default" />
              </div>
              <p class="mt-1 text-xs text-gray-400">
                Lãi: {{ formatVnd(form.sell_price - totalCost) }} / {{ form.unit || 'đvt' }}
              </p>
            </div>
          </div>
        </div>

        <!-- Tài khoản kế toán -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Tài khoản kế toán</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Loại sản phẩm</label>
              <select v-model="form.item_type"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none">
                <option :value="null">-- Chưa phân loại --</option>
                <option value="goods">Hàng hóa</option>
                <option value="service">Dịch vụ</option>
              </select>
              <p class="mt-1 text-xs text-gray-400">Ảnh hưởng đến TK doanh thu tự động chọn</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">TK doanh thu</label>
              <select v-model="form.revenue_account_code"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.revenue_account_code }">
                <option :value="null">-- Dùng mặc định hệ thống --</option>
                <option v-for="a in accounts" :key="a.code" :value="a.code">{{ a.code }} — {{ a.name }}</option>
              </select>
              <p v-if="form.errors.revenue_account_code" class="mt-1 text-xs text-red-600">{{ form.errors.revenue_account_code }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">TK hàng tồn kho</label>
              <select v-model="form.inventory_account"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.inventory_account }">
                <option :value="null">-- Dùng mặc định hệ thống --</option>
                <option v-for="a in accounts" :key="a.code" :value="a.code">{{ a.code }} — {{ a.name }}</option>
              </select>
              <p v-if="form.errors.inventory_account" class="mt-1 text-xs text-red-600">{{ form.errors.inventory_account }}</p>
            </div>
          </div>
        </div>

        <div class="flex gap-3">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : (product ? 'Cập nhật' : 'Thêm sản phẩm') }}
          </button>
          <Link :href="route('catalog.products.index')"
            class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  product: { type: Object, default: null },
  categories: Array,
  nextCode: String,
  accounts: { type: Array, default: () => [] },
});

const { formatVnd } = useCurrency();

const form = useForm({
  code:                 props.product?.code ?? props.nextCode ?? '',
  name:                 props.product?.name ?? '',
  category_id:          props.product?.category_id ?? null,
  unit:                 props.product?.unit ?? 'cái',
  cost_price:           props.product?.cost_price ?? 0,
  business_cost:        props.product?.business_cost ?? 0,
  vat_percent:          props.product?.vat_percent ?? 0,
  sell_price:           props.product?.sell_price ?? 0,
  warranty_months:      props.product?.warranty_months ?? 0,
  min_stock:            props.product?.min_stock ?? 0,
  has_serial:           props.product?.has_serial ?? false,
  description:          props.product?.description ?? '',
  is_active:            props.product?.is_active ?? true,
  item_type:            props.product?.item_type ?? null,
  revenue_account_code: props.product?.revenue_account_code ?? null,
  inventory_account:    props.product?.inventory_account ?? null,
});

// cost_price đã gồm VAT — back-calculate phần thuế từ giá inclusive
const vatAmount = computed(() => {
  const costPrice = Number(form.cost_price ?? 0);
  const vat = Number(form.vat_percent ?? 0);
  if (vat === 0) return 0;
  return Math.round(costPrice * vat / (100 + vat));
});

// Giá vốn = giá nhập (đã VAT) + chi phí KD nội bộ
// Dùng Number() vì PHP decimal:2 cast trả về string — tránh string concatenation
const totalCost = computed(() => Number(form.cost_price ?? 0) + Number(form.business_cost ?? 0));

// Tính margin ban đầu từ dữ liệu sản phẩm đã lưu
const initMargin = () => {
  const tc = Number(props.product?.total_cost ?? 0);
  const sp = Number(props.product?.sell_price ?? 0);
  if (tc > 0 && sp > 0) {
    return Math.round(((sp / tc) - 1) * 100 * 100) / 100;
  }
  return 0;
};

const marginPercent = ref(initMargin());

const recalcSellPrice = () => {
  form.sell_price = Math.round(totalCost.value * (1 + (marginPercent.value ?? 0) / 100));
};

// Khi margin thay đổi → cập nhật giá bán
watch(marginPercent, recalcSellPrice);

// Khi chi phí thay đổi → cập nhật lại giá bán theo margin hiện tại
watch(totalCost, recalcSellPrice);

const submit = () => {
  if (props.product) {
    form.put(route('catalog.products.update', props.product.id));
  } else {
    form.post(route('catalog.products.store'));
  }
};
</script>
