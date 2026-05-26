<template>
  <AppLayout>
    <div class="max-w-6xl space-y-6">
      <!-- Title & period selector -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Kê khai thuế VAT</h1>
          <p class="text-sm text-gray-500">Đối chiếu hóa đơn mua vào/bán ra, tính toán thuế VAT và xuất tờ khai chuẩn HTKK.</p>
        </div>
        <div class="flex items-center space-x-3">
          <div>
            <label class="block text-[10px] uppercase font-bold text-gray-400 mb-0.5">Kỳ tính thuế</label>
            <input type="month" v-model="selectedPeriod" @change="changePeriod"
              class="border-gray-300 rounded-lg shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm font-semibold" />
          </div>
          <div class="pt-4">
            <a :href="route('accounting.taxes.export-xml', { period })"
              class="inline-flex items-center space-x-2 bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-semibold shadow-sm transition">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
              </svg>
              <span>Xuất XML HTKK</span>
            </a>
          </div>
        </div>
      </div>

      <!-- Financial widgets -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm">
          <p class="text-xs text-gray-400 font-semibold uppercase tracking-wider">Doanh thu bán ra</p>
          <p class="text-base font-extrabold text-gray-900 font-mono mt-1">{{ formatVnd(summary.total_sales_subtotal) }}</p>
        </div>

        <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm">
          <p class="text-xs text-gray-400 font-semibold uppercase tracking-wider">Thuế VAT đầu ra</p>
          <p class="text-base font-extrabold text-red-600 font-mono mt-1">{{ formatVnd(summary.total_sales_tax) }}</p>
        </div>

        <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm">
          <p class="text-xs text-gray-400 font-semibold uppercase tracking-wider">Giá trị mua vào</p>
          <p class="text-base font-extrabold text-gray-900 font-mono mt-1">{{ formatVnd(summary.total_purchase_subtotal) }}</p>
        </div>

        <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm">
          <p class="text-xs text-gray-400 font-semibold uppercase tracking-wider">Thuế VAT đầu vào</p>
          <p class="text-base font-extrabold text-green-600 font-mono mt-1">{{ formatVnd(summary.total_purchase_tax) }}</p>
        </div>

        <div :class="summary.net_tax_payable >= 0 ? 'bg-red-50 border-red-200' : 'bg-green-50 border-green-200'"
          class="p-4 rounded-xl border shadow-sm">
          <p :class="summary.net_tax_payable >= 0 ? 'text-red-700' : 'text-green-700'"
            class="text-xs font-semibold uppercase tracking-wider">
            {{ summary.net_tax_payable >= 0 ? 'Thuế VAT phải nộp' : 'Thuế VAT được khấu trừ' }}
          </p>
          <p :class="summary.net_tax_payable >= 0 ? 'text-red-800' : 'text-green-800'"
            class="text-lg font-extrabold font-mono mt-1">
            {{ formatVnd(Math.abs(summary.net_tax_payable)) }}
          </p>
        </div>
      </div>

      <!-- Detailed Tabs -->
      <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <!-- Tabs Header -->
        <div class="border-b border-gray-200 bg-gray-50 flex items-center justify-between px-5">
          <div class="flex space-x-6">
            <button @click="activeTab = 'sales'"
              :class="activeTab === 'sales' ? 'border-primary-600 text-primary-600 font-bold' : 'border-transparent text-gray-500 font-medium'"
              class="py-4 border-b-2 text-sm transition">
              Bảng kê Hóa đơn bán ra (VAT đầu ra)
            </button>
            <button @click="activeTab = 'purchases'"
              :class="activeTab === 'purchases' ? 'border-primary-600 text-primary-600 font-bold' : 'border-transparent text-gray-500 font-medium'"
              class="py-4 border-b-2 text-sm transition">
              Bảng kê Hóa đơn mua vào (VAT đầu vào)
            </button>
          </div>
          <span class="text-xs text-gray-400 font-medium font-mono">Kỳ: {{ period }}</span>
        </div>

        <!-- Sales Invoices Tab -->
        <div v-show="activeTab === 'sales'" class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50">
              <tr>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Số hóa đơn</th>
                <th class="text-center px-5 py-3 font-semibold text-gray-600">Ngày hóa đơn</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Khách hàng</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã số thuế</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Tiền trước thuế</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Tiền thuế VAT</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng cộng</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="inv in salesInvoices" :key="inv.id" class="hover:bg-gray-50">
                <td class="px-5 py-3 font-mono text-xs font-semibold text-primary-600">
                  <Link :href="route('accounting.invoices.show', inv.id)" class="hover:underline">
                    {{ inv.code }}
                  </Link>
                </td>
                <td class="px-5 py-3 text-center text-gray-600 font-mono">{{ inv.issue_date }}</td>
                <td class="px-5 py-3 font-semibold text-gray-800">{{ inv.customer_name }}</td>
                <td class="px-5 py-3 text-gray-500 font-mono text-xs">{{ inv.tax_code }}</td>
                <td class="px-5 py-3 text-right text-gray-700 font-mono">{{ formatVnd(inv.subtotal) }}</td>
                <td class="px-5 py-3 text-right text-red-500 font-mono">{{ formatVnd(inv.tax_amount) }}</td>
                <td class="px-5 py-3 text-right font-bold text-gray-900 font-mono">{{ formatVnd(inv.total) }}</td>
              </tr>
              <tr v-if="!salesInvoices.length">
                <td colspan="7" class="px-5 py-10 text-center text-gray-400 text-sm">
                  Không tìm thấy hóa đơn đầu ra trong kỳ kê khai này.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Purchase Invoices Tab -->
        <div v-show="activeTab === 'purchases'" class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50">
              <tr>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã phiếu</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Số hóa đơn NCC</th>
                <th class="text-center px-5 py-3 font-semibold text-gray-600">Ngày hóa đơn</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Nhà cung cấp</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã số thuế</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Tiền trước thuế</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Tiền thuế VAT</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng cộng</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="inv in purchaseInvoices" :key="inv.id" class="hover:bg-gray-50">
                <td class="px-5 py-3 font-mono text-xs font-semibold text-primary-600">
                  <Link :href="route('purchasing.purchase-invoices.show', inv.id)" class="hover:underline">
                    {{ inv.code }}
                  </Link>
                </td>
                <td class="px-5 py-3 font-mono text-xs font-semibold text-gray-800">{{ inv.invoice_number }}</td>
                <td class="px-5 py-3 text-center text-gray-600 font-mono">{{ inv.invoice_date || '-' }}</td>
                <td class="px-5 py-3 font-semibold text-gray-800">{{ inv.supplier_name }}</td>
                <td class="px-5 py-3 text-gray-500 font-mono text-xs">{{ inv.tax_code }}</td>
                <td class="px-5 py-3 text-right text-gray-700 font-mono">{{ formatVnd(inv.subtotal) }}</td>
                <td class="px-5 py-3 text-right text-green-600 font-mono">{{ formatVnd(inv.tax_amount) }}</td>
                <td class="px-5 py-3 text-right font-bold text-gray-900 font-mono">{{ formatVnd(inv.total) }}</td>
              </tr>
              <tr v-if="!purchaseInvoices.length">
                <td colspan="8" class="px-5 py-10 text-center text-gray-400 text-sm">
                  Không tìm thấy hóa đơn đầu vào trong kỳ kê khai này.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  period: String,
  salesInvoices: Array,
  purchaseInvoices: Array,
  summary: Object,
});

const { formatVnd } = useCurrency();

const selectedPeriod = ref(props.period);
const activeTab = ref('sales');

const changePeriod = () => {
  router.get(route('accounting.taxes.index'), { period: selectedPeriod.value }, { preserveState: true });
};
</script>
