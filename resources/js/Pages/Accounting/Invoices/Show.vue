<template>
  <AppLayout>
    <div class="space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <div class="flex items-center gap-3">
          <Link :href="route('accounting.invoices.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
          </Link>
          <h1 class="text-2xl font-bold text-gray-900">{{ invoice.code }}</h1>
          <StatusBadge :color="invoice.status_color">{{ invoice.status_label }}</StatusBadge>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
          <a :href="route('accounting.invoices.pdf', invoice.id)" target="_blank"
            class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg text-sm font-medium">
            PDF
          </a>
          <Link v-if="invoice.allowed_actions.includes('edit') && can('accounting.view')"
            :href="route('accounting.invoices.edit', invoice.id)"
            class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg text-sm font-medium">
            Sửa
          </Link>
          <button v-if="invoice.allowed_actions.includes('mark_sent')"
            @click="action('mark-sent')"
            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Gửi hóa đơn
          </button>
          <button v-if="invoice.allowed_actions.includes('mark_overdue') && can('accounting.manage')"
            @click="action('mark-overdue')"
            class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Đánh dấu quá hạn
          </button>
          <button v-if="invoice.allowed_actions.includes('mark_paid') && can('accounting.manage')"
            @click="action('mark-paid')"
            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Đánh dấu đã TT
          </button>
          <button v-if="invoice.allowed_actions.includes('cancel') && can('accounting.manage')"
            @click="cancelInvoice"
            class="bg-orange-50 hover:bg-orange-100 text-orange-700 px-3 py-2 rounded-lg text-sm font-medium border border-orange-200">
            Hủy hóa đơn
          </button>
          <button v-if="invoice.allowed_actions.includes('delete') && can('accounting.manage')"
            @click="deleteInvoice"
            class="bg-red-50 hover:bg-red-100 text-red-700 px-3 py-2 rounded-lg text-sm font-medium">
            Xóa
          </button>
          <button v-if="invoice.status === 'cancelled' && can('accounting.manage')"
            @click="deleteInvoice"
            class="bg-red-50 hover:bg-red-100 text-red-700 px-3 py-2 rounded-lg text-sm font-medium">
            Xóa
          </button>
        </div>
      </div>

      <!-- Banner: có ứng trước chưa dùng -->
      <div v-if="canAdvanceAllocate"
        class="bg-amber-50 border border-amber-200 rounded-xl p-4 flex items-center gap-3">
        <svg class="w-5 h-5 text-amber-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <p class="text-sm text-amber-800 flex-1">
          Khách hàng này còn
          <strong>{{ formatVnd(available_advances.reduce((s, a) => s + a.remaining_amount, 0)) }}</strong>
          ứng trước chưa đối trừ.
        </p>
        <button @click="showAdvanceForm = !showAdvanceForm"
          class="flex-shrink-0 px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-white rounded-lg text-sm font-medium">
          Đối trừ ứng trước
        </button>
      </div>

      <!-- Banner: giá vốn chưa ghi nhận -->
      <div v-if="invoice.cogs_status === 'cogs_missing'"
        class="bg-orange-50 border border-orange-200 rounded-xl p-4 flex items-start gap-3">
        <svg class="w-5 h-5 text-orange-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
        </svg>
        <div class="flex-1">
          <p class="text-sm font-semibold text-orange-900">Giá vốn (TK 632) chưa được ghi nhận</p>
          <p class="text-sm text-orange-700 mt-0.5">
            Hóa đơn đã ghi doanh thu nhưng chưa có phiếu xuất kho xác nhận cho đơn hàng này.
            Tạo và xác nhận phiếu xuất kho (XK-) để hệ thống tự ghi <strong>Nợ 632 / Có 1561</strong>.
          </p>
        </div>
        <Link v-if="invoice.order"
          :href="route('sales.orders.show', invoice.order.id)"
          class="flex-shrink-0 px-3 py-1.5 bg-orange-500 hover:bg-orange-600 text-white rounded-lg text-sm font-medium whitespace-nowrap">
          Đến đơn hàng
        </Link>
      </div>

      <div class="grid grid-cols-3 gap-5">
        <!-- Info -->
        <div class="col-span-2 space-y-5">
          <!-- Invoice details -->
          <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Thông tin hóa đơn</h2>
            <dl class="grid grid-cols-2 gap-3 text-sm">
              <div>
                <dt class="text-gray-500">Khách hàng</dt>
                <dd class="font-medium text-gray-900 mt-0.5">{{ invoice.customer.name }}</dd>
              </div>
              <div>
                <dt class="text-gray-500">Ngày phát hành</dt>
                <dd class="font-medium text-gray-900 mt-0.5">{{ invoice.issue_date }}</dd>
              </div>
              <div v-if="invoice.due_date">
                <dt class="text-gray-500">Hạn thanh toán</dt>
                <dd class="font-medium text-gray-900 mt-0.5">{{ invoice.due_date }}</dd>
              </div>
              <div v-if="invoice.order">
                <dt class="text-gray-500">Đơn hàng</dt>
                <dd class="mt-0.5">
                  <Link :href="route('sales.orders.show', invoice.order.id)" class="text-primary-600 hover:underline font-mono">
                    {{ invoice.order.code }}
                  </Link>
                </dd>
              </div>
              <div v-if="invoice.contract">
                <dt class="text-gray-500">Hợp đồng</dt>
                <dd class="mt-0.5">
                  <Link :href="route('sales.contracts.show', invoice.contract.id)" class="text-primary-600 hover:underline font-mono">
                    {{ invoice.contract.code }}
                  </Link>
                </dd>
              </div>
              <div>
                <dt class="text-gray-500">Người tạo</dt>
                <dd class="font-medium text-gray-900 mt-0.5">{{ invoice.creator }}</dd>
              </div>
            </dl>
            <div v-if="invoice.notes" class="mt-4 pt-4 border-t border-gray-100">
              <dt class="text-xs text-gray-500 uppercase tracking-wide mb-1">Ghi chú</dt>
              <dd class="text-sm text-gray-700 whitespace-pre-wrap">{{ invoice.notes }}</dd>
            </div>
          </div>

          <!-- Line items -->
          <div v-if="invoice.items?.length" class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
            <div class="px-5 py-4 border-b border-gray-100">
              <h2 class="text-base font-semibold text-gray-900">Chi tiết hàng hóa / dịch vụ</h2>
            </div>
            <table class="min-w-full text-sm">
              <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th class="text-left px-4 py-3 font-semibold text-gray-600 w-8">#</th>
                  <th class="text-left px-4 py-3 font-semibold text-gray-600">Diễn giải</th>
                  <th class="text-right px-4 py-3 font-semibold text-gray-600 w-20">SL</th>
                  <th class="text-right px-4 py-3 font-semibold text-gray-600 w-32">Đơn giá</th>
                  <th class="text-right px-4 py-3 font-semibold text-gray-600 w-24">Thành tiền</th>
                  <th class="text-center px-4 py-3 font-semibold text-gray-600 w-20">Thuế suất</th>
                  <th class="text-right px-4 py-3 font-semibold text-gray-600 w-28">Tiền thuế</th>
                  <th class="text-right px-4 py-3 font-semibold text-gray-600 w-32">Tổng dòng</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="(item, idx) in invoice.items" :key="idx" class="hover:bg-gray-50">
                  <td class="px-4 py-3 text-gray-400 text-xs">{{ idx + 1 }}</td>
                  <td class="px-4 py-3 text-gray-800">{{ item.description }}</td>
                  <td class="px-4 py-3 text-right text-gray-700">{{ item.quantity }}</td>
                  <td class="px-4 py-3 text-right text-gray-700">{{ formatVnd(item.unit_price) }}</td>
                  <td class="px-4 py-3 text-right text-gray-700">{{ formatVnd(Math.round(item.quantity * item.unit_price)) }}</td>
                  <td class="px-4 py-3 text-center">
                    <span class="text-xs font-medium px-2 py-0.5 rounded-full"
                      :class="item.vat_rate === 0 ? 'bg-gray-100 text-gray-500' : 'bg-blue-50 text-blue-700'">
                      {{ item.vat_rate === 0 ? 'KCT' : item.vat_rate + '%' }}
                    </span>
                  </td>
                  <td class="px-4 py-3 text-right text-gray-700">{{ formatVnd(item.tax_amount) }}</td>
                  <td class="px-4 py-3 text-right font-medium text-gray-900">{{ formatVnd(Math.round(item.quantity * item.unit_price) + item.tax_amount) }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- E-Invoice section -->
          <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex justify-between items-center mb-4">
              <h2 class="text-base font-semibold text-gray-900">Hóa đơn điện tử (HĐDT)</h2>
              <div class="flex gap-2">
                <a v-if="invoice.e_inv_status === 'issued'"
                  :href="route('accounting.invoices.e-invoice-pdf', invoice.id)"
                  target="_blank"
                  class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-lg font-medium">
                  Tải PDF
                </a>
              </div>
            </div>

            <template v-if="invoice.e_inv_status === 'issued' || invoice.e_inv_status === 'cancelled'">
              <dl class="grid grid-cols-3 gap-3 text-sm mb-4">
                <div>
                  <dt class="text-xs text-gray-500">Mẫu số</dt>
                  <dd class="font-mono font-medium mt-0.5">{{ invoice.e_inv_template }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-gray-500">Ký hiệu</dt>
                  <dd class="font-mono font-medium mt-0.5">{{ invoice.e_inv_series }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-gray-500">Số</dt>
                  <dd class="font-bold text-primary-600 text-lg mt-0.5">{{ String(invoice.e_inv_number).padStart(7,'0') }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-gray-500">Ngày phát hành</dt>
                  <dd class="font-medium mt-0.5">{{ invoice.e_inv_issued_at }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-gray-500">Trạng thái</dt>
                  <dd class="mt-0.5">
                    <span :class="invoice.e_inv_status === 'issued' ? 'badge-green' : 'badge-red'">
                      {{ invoice.e_inv_status === 'issued' ? 'Đã phát hành' : 'Đã hủy' }}
                    </span>
                  </dd>
                </div>
              </dl>
              <div v-if="invoice.e_inv_cancel_reason" class="bg-red-50 p-3 rounded text-xs text-red-700">
                <strong>Lý do hủy:</strong> {{ invoice.e_inv_cancel_reason }}
              </div>
              <div v-if="invoice.e_inv_status === 'issued' && can('accounting.manage')" class="mt-3 border-t pt-3">
                <form @submit.prevent="cancelEInvoice" class="flex gap-3 items-end">
                  <div class="flex-1">
                    <label class="form-label text-xs">Lý do hủy <span class="text-red-500">*</span></label>
                    <input v-model="cancelReason" class="form-input text-sm" placeholder="Nhập lý do hủy..." required />
                  </div>
                  <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                    Hủy HĐDT
                  </button>
                </form>
              </div>
            </template>

            <template v-else>
              <p class="text-sm text-gray-500 mb-4">Chưa phát hành hóa đơn điện tử cho hóa đơn này.</p>
              <form v-if="can('accounting.manage')" @submit.prevent="issueEInvoice" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label class="form-label text-xs">Mẫu số <span class="text-red-500">*</span></label>
                  <input v-model="eInvForm.e_inv_template" class="form-input text-sm font-mono" placeholder="01GTKT0/001" required />
                </div>
                <div>
                  <label class="form-label text-xs">Ký hiệu <span class="text-red-500">*</span></label>
                  <input v-model="eInvForm.e_inv_series" class="form-input text-sm font-mono" placeholder="AA/24E" required />
                </div>
                <div class="col-span-2">
                  <button type="submit" :disabled="eInvForm.processing" class="btn-primary text-sm">
                    {{ eInvForm.processing ? 'Đang phát hành...' : 'Phát hành HĐDT' }}
                  </button>
                </div>
              </form>
            </template>
          </div>

          <!-- Payments -->
          <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
              <h2 class="text-base font-semibold text-gray-900">Lịch sử thanh toán</h2>
              <button v-if="invoice.allowed_actions.includes('add_payment') && can('accounting.manage')"
                @click="showPaymentForm = !showPaymentForm"
                class="bg-primary-600 hover:bg-primary-700 text-white px-3 py-1.5 rounded-lg text-sm font-medium">
                + Thêm thanh toán
              </button>
            </div>

            <!-- Payment form -->
            <div v-if="showPaymentForm" class="p-5 border-b border-gray-100 bg-gray-50 space-y-4">
              <!-- Payment type selector (chỉ hiện khi có advances) -->
              <div v-if="available_advances?.length > 0">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Hình thức thanh toán</p>
                <div class="flex gap-2">
                  <button v-for="pt in paymentTypes" :key="pt.value" type="button"
                    @click="paymentType = pt.value"
                    :class="paymentType === pt.value
                      ? 'bg-primary-600 text-white border-primary-600 shadow-sm'
                      : 'bg-white text-gray-600 border-gray-300 hover:border-primary-400 hover:text-primary-600'"
                    class="px-4 py-2 rounded-lg text-sm font-medium border transition-all">
                    {{ pt.label }}
                  </button>
                </div>
              </div>

              <!-- Advance selection (offset / combined) -->
              <div v-if="paymentType !== 'cash' && available_advances?.length"
                class="border border-amber-200 rounded-lg bg-amber-50 p-3 space-y-2">
                <p class="text-xs font-semibold text-amber-800">Khoản ứng trước có thể đối trừ</p>
                <div v-for="adv in available_advances" :key="adv.id" class="flex items-center gap-3">
                  <input type="checkbox" :id="'adv-'+adv.id"
                    :checked="isAdvanceSelected(adv.id)"
                    @change="toggleAdvance(adv)"
                    class="rounded border-gray-300 text-amber-500 focus:ring-amber-400" />
                  <label :for="'adv-'+adv.id" class="flex-1 text-xs text-gray-700 cursor-pointer">
                    <span class="font-medium">{{ adv.reference_no || ('ADV-' + adv.id) }}</span>
                    <span class="ml-1 text-amber-600 bg-amber-100 px-1 rounded text-xs">{{ adv.type_label }}</span>
                    <span class="ml-1">— còn <strong>{{ formatVnd(adv.remaining_amount) }}</strong></span>
                  </label>
                  <input v-if="isAdvanceSelected(adv.id)"
                    type="number" min="1" :max="adv.remaining_amount" step="1"
                    :value="getAdvanceAmount(adv.id)"
                    @input="setAdvanceAmount(adv.id, +$event.target.value)"
                    class="w-32 border border-amber-300 rounded px-2 py-1 text-xs text-right focus:outline-none focus:ring-1 focus:ring-amber-400" />
                </div>
                <div class="flex items-center gap-3 pt-1">
                  <label class="text-xs font-medium text-gray-600">Ngày đối trừ <span class="text-red-500">*</span></label>
                  <input v-model="payForm.allocation_date" type="date"
                    class="border border-gray-300 rounded-lg px-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-amber-400" />
                  <span v-if="totalOffsetAmount > 0" class="text-xs font-semibold text-amber-800 ml-2">
                    Tổng đối trừ: {{ formatVnd(totalOffsetAmount) }}
                  </span>
                </div>
                <p class="text-xs text-blue-700 bg-blue-50 border border-blue-200 rounded px-2 py-1">
                  Đối trừ không tạo phiếu thu — không ghi Nợ 1111/1121 thêm lần nữa.
                </p>
              </div>

              <!-- Cash fields (cash / combined) -->
              <div v-if="paymentType !== 'offset'" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">
                    {{ paymentType === 'combined' ? 'Số tiền thu thêm' : 'Số tiền' }} <span class="text-red-500">*</span>
                  </label>
                  <input v-model.number="payForm.amount" type="number" @invalid.prevent
                    :max="invoice.amount_due - totalOffsetAmount"
                    :class="payAmountError ? 'border-red-400 focus:ring-red-400' : 'border-gray-300 focus:ring-primary-500'"
                    class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                  <p v-if="payAmountError" class="mt-1 text-xs text-red-600">{{ payAmountError }}</p>
                  <p class="text-xs text-green-700 font-medium mt-0.5">{{ formatVnd(payForm.amount || 0) }}</p>
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Ngày thanh toán <span class="text-red-500">*</span></label>
                  <input v-model="payForm.payment_date" type="date"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Phương thức</label>
                  <select v-model="payForm.method"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                    <option v-for="m in methods" :key="m.value" :value="m.value">{{ m.label }}</option>
                  </select>
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Quỹ / Tài khoản <span class="text-red-500">*</span></label>
                  <select v-model="payForm.fund_id"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                    <option value="">-- Chọn quỹ --</option>
                    <option v-for="f in funds" :key="f.id" :value="f.id">{{ f.name }}</option>
                  </select>
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Mã tham chiếu</label>
                  <input v-model="payForm.reference" type="text"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Ghi chú</label>
                  <input v-model="payForm.notes" type="text"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
                </div>
              </div>

              <!-- Combined summary -->
              <div v-if="paymentType === 'combined' && totalOffsetAmount > 0"
                class="flex gap-4 text-xs text-gray-700 bg-gray-100 rounded-lg px-3 py-2">
                <span>Đối trừ: <strong class="text-amber-700">{{ formatVnd(totalOffsetAmount) }}</strong></span>
                <span>Thu thêm: <strong class="text-green-700">{{ formatVnd(payForm.amount || 0) }}</strong></span>
                <span>Tổng: <strong class="text-blue-700">{{ formatVnd(totalOffsetAmount + (payForm.amount || 0)) }}</strong></span>
              </div>

              <div class="flex justify-end gap-2">
                <button type="button" @click="resetPayForm"
                  class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm">Hủy</button>
                <button type="button" @click="submitPayment" :disabled="payForm.processing"
                  class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50">
                  {{ payForm.processing ? 'Đang xử lý...' : (paymentType === 'offset' ? 'Đối trừ' : 'Ghi nhận') }}
                </button>
              </div>
            </div>

            <table class="min-w-full text-sm">
              <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày</th>
                  <th class="text-left px-5 py-3 font-semibold text-gray-600">Phương thức</th>
                  <th class="text-left px-5 py-3 font-semibold text-gray-600">Tham chiếu</th>
                  <th class="text-right px-5 py-3 font-semibold text-gray-600">Số tiền</th>
                  <th class="px-5 py-3"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="p in invoice.payments" :key="p.id" class="hover:bg-gray-50">
                  <td class="px-5 py-3 text-gray-600">{{ p.payment_date }}</td>
                  <td class="px-5 py-3">{{ p.method_label }}</td>
                  <td class="px-5 py-3 text-gray-500">{{ p.reference ?? '—' }}</td>
                  <td class="px-5 py-3 text-right font-medium text-green-700">{{ formatVnd(p.amount) }}</td>
                  <td class="px-5 py-3 text-right">
                    <button v-if="can('accounting.manage')"
                      @click="deletePayment(p.id)"
                      class="text-red-400 hover:text-red-600 text-xs">Xóa</button>
                  </td>
                </tr>
                <tr v-if="!invoice.payments?.length">
                  <td colspan="5" class="px-5 py-8 text-center text-gray-400">Chưa có thanh toán nào</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Advance allocations section -->
          <div v-if="invoice.advance_allocations?.length || canAdvanceAllocate"
            class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
            <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
              <h2 class="text-base font-semibold text-gray-800">Đối trừ ứng trước khách hàng</h2>
              <button v-if="canAdvanceAllocate" @click="showAdvanceForm = !showAdvanceForm"
                class="bg-amber-500 hover:bg-amber-600 text-white px-3 py-1.5 rounded-lg text-sm font-medium">
                + Đối trừ ứng trước
              </button>
            </div>

            <!-- Form đối trừ -->
            <div v-if="showAdvanceForm" class="px-5 py-4 border-b border-gray-100 bg-amber-50">
              <form @submit.prevent="submitAdvanceAllocation" class="grid grid-cols-2 sm:grid-cols-4 gap-3 items-start">
                <div class="col-span-2">
                  <label class="block text-xs font-medium text-gray-600 mb-1">Khoản ứng trước <span class="text-red-500">*</span></label>
                  <select v-model="advanceForm.opening_advance_id" @change="onAdvanceSelect"
                    class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400">
                    <option value="">-- Chọn khoản ứng trước --</option>
                    <option v-for="adv in available_advances" :key="adv.id" :value="adv.id">
                      {{ adv.reference_no || ('ADV-' + adv.id) }} ({{ adv.type_label }}) — còn {{ formatVnd(adv.remaining_amount) }}
                    </option>
                  </select>
                  <p v-if="selectedAdvance" class="text-xs text-amber-700 mt-0.5">
                    Ứng trước còn lại: <strong>{{ formatVnd(selectedAdvance.remaining_amount) }} đ</strong>
                  </p>
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Số tiền đối trừ <span class="text-red-500">*</span></label>
                  <input v-model.number="advanceForm.allocated_amount" type="number" min="1" :max="maxAllocatable" step="1"
                    class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400" />
                  <p class="text-xs text-amber-700 font-medium mt-0.5">{{ formatVnd(advanceForm.allocated_amount || 0) }} đ</p>
                  <button type="button" @click="advanceForm.allocated_amount = maxAllocatable"
                    class="mt-1 text-xs text-amber-600 hover:text-amber-800 underline">
                    Dùng tối đa ({{ formatVnd(maxAllocatable) }} đ)
                  </button>
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Ngày đối trừ <span class="text-red-500">*</span></label>
                  <input v-model="advanceForm.allocation_date" type="date"
                    class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400" />
                </div>
                <div class="col-span-2 sm:col-span-3">
                  <label class="block text-xs font-medium text-gray-600 mb-1">Diễn giải</label>
                  <input v-model="advanceForm.reason" type="text" placeholder="Đối trừ ứng trước KH..."
                    class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400" />
                </div>
                <div class="flex items-end gap-2">
                  <button type="submit" :disabled="!advanceForm.opening_advance_id || advanceProcessing"
                    class="bg-amber-500 hover:bg-amber-600 text-white px-4 py-1.5 rounded-lg text-sm font-medium disabled:opacity-50">
                    {{ advanceProcessing ? 'Đang xử lý...' : 'Đối trừ' }}
                  </button>
                  <button type="button" @click="showAdvanceForm = false"
                    class="bg-gray-100 hover:bg-gray-200 text-gray-600 px-3 py-1.5 rounded-lg text-sm">
                    Hủy
                  </button>
                </div>
              </form>
              <div class="mt-3 p-3 bg-blue-50 border border-blue-200 rounded-lg text-xs text-blue-800">
                <strong>Lưu ý kế toán:</strong> Đối trừ ứng trước không tạo phiếu thu — không ghi Nợ 1111/1121.
                Bút toán: Nợ 131UT / Có 1311.
              </div>
            </div>

            <!-- Danh sách đối trừ -->
            <table class="min-w-full text-sm">
              <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                  <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày</th>
                  <th class="text-left px-5 py-3 font-semibold text-gray-600">Ứng trước</th>
                  <th class="text-right px-5 py-3 font-semibold text-gray-600">Số đối trừ</th>
                  <th class="text-left px-5 py-3 font-semibold text-gray-600">Diễn giải</th>
                  <th class="text-left px-5 py-3 font-semibold text-gray-600">Người tạo</th>
                  <th class="px-5 py-3"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="a in invoice.advance_allocations" :key="a.id"
                  :class="a.status === 'reversed' ? 'bg-gray-50 opacity-60' : 'hover:bg-gray-50'">
                  <td class="px-5 py-3 text-gray-700" :class="a.status === 'reversed' ? 'line-through' : ''">{{ a.allocation_date }}</td>
                  <td class="px-5 py-3 font-mono text-xs text-gray-600">{{ a.advance_ref }}</td>
                  <td class="px-5 py-3 text-right font-medium"
                    :class="a.status === 'reversed' ? 'text-gray-400 line-through' : 'text-orange-700'">
                    {{ formatVnd(a.allocated_amount) }}
                  </td>
                  <td class="px-5 py-3 text-gray-500">{{ a.reason || '—' }}</td>
                  <td class="px-5 py-3 text-gray-600">{{ a.creator }}</td>
                  <td class="px-5 py-3 text-right">
                    <button v-if="can('accounting.manage') && a.status === 'active'"
                      @click="confirmReverseAllocation(a.id)"
                      class="text-red-500 hover:text-red-700 text-xs">
                      Thu hồi
                    </button>
                  </td>
                </tr>
                <tr v-if="!invoice.advance_allocations?.length">
                  <td colspan="6" class="px-5 py-6 text-center text-gray-400 text-sm">Chưa có đối trừ ứng trước</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Summary sidebar -->
        <div class="space-y-4">
          <FileAttachments
            :attachments="invoice.attachments ?? []"
            :upload-url="route('attachments.store', { type: 'invoice', id: invoice.id })"
          />

          <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Tổng kết</h2>
            <dl class="space-y-2 text-sm">
              <div class="flex justify-between">
                <dt class="text-gray-500">Tổng trước thuế</dt>
                <dd class="font-medium">{{ formatVnd(invoice.subtotal) }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-gray-500">Thuế VAT</dt>
                <dd class="font-medium">{{ formatVnd(invoice.tax_amount) }}</dd>
              </div>
              <div class="flex justify-between border-t border-gray-100 pt-2 mt-2">
                <dt class="font-semibold text-gray-900">Tổng cộng</dt>
                <dd class="font-bold text-lg text-primary-700">{{ formatVnd(invoice.total) }}</dd>
              </div>
              <div class="flex justify-between text-green-700">
                <dt>Đã thu tiền</dt>
                <dd class="font-medium">{{ formatVnd(invoice.amount_paid) }}</dd>
              </div>
              <div v-if="invoice.advance_allocated_amount > 0" class="flex justify-between text-orange-700">
                <dt>Đối trừ ứng trước</dt>
                <dd class="font-medium">{{ formatVnd(invoice.advance_allocated_amount) }}</dd>
              </div>
              <div class="flex justify-between border-t border-gray-100 pt-2 mt-2"
                :class="invoice.amount_due > 0 ? 'text-red-700' : 'text-green-700'">
                <dt class="font-semibold">Còn lại</dt>
                <dd class="font-bold">{{ formatVnd(invoice.amount_due) }}</dd>
              </div>
            </dl>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal: Thu hồi đối trừ -->
    <Teleport to="body">
      <div v-if="showReverseModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md">
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="font-bold text-gray-900">Thu hồi đối trừ ứng trước</h3>
          </div>
          <div class="p-6 space-y-4">
            <p class="text-sm text-gray-600">
              Thu hồi chứng từ đối trừ sẽ hoàn lại số ứng trước cho khoản ứng trước khách hàng và cập nhật lại số còn phải thu của hóa đơn.
            </p>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">
                Lý do thu hồi <span class="text-red-500">*</span>
              </label>
              <textarea v-model="reverseReason" rows="2" placeholder="Nhập lý do..."
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400" />
            </div>
          </div>
          <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-3">
            <button @click="showReverseModal = false"
              class="px-4 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Hủy</button>
            <button @click="submitReverseAllocation" :disabled="!reverseReason.trim()"
              class="px-5 py-2 text-sm font-medium bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-40">
              Xác nhận thu hồi
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import FileAttachments from '@/Components/Shared/FileAttachments.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  invoice: Object,
  methods: Array,
  funds: Array,
  available_advances: Array,
});

const { hasPermission } = usePermission();
const can = hasPermission;
const { formatVnd } = useCurrency();

const today = new Date().toISOString().split('T')[0];

// ── Payment form ──────────────────────────────────────────────────────────────
const showPaymentForm = ref(false);
const payAmountError  = ref('');
const paymentType     = ref('cash');
const paymentTypes    = [
  { value: 'cash',     label: 'Thu tiền mới' },
  { value: 'offset',   label: 'Đối trừ ứng trước' },
  { value: 'combined', label: 'Đối trừ + Thu thêm' },
];

const selectedAdvances = ref([]);
const totalOffsetAmount = computed(() =>
  selectedAdvances.value.reduce((sum, a) => sum + (a.amount || 0), 0)
);

function isAdvanceSelected(advId) {
  return selectedAdvances.value.some(a => a.advance_id === advId);
}

function toggleAdvance(adv) {
  const idx = selectedAdvances.value.findIndex(a => a.advance_id === adv.id);
  if (idx >= 0) {
    selectedAdvances.value.splice(idx, 1);
  } else {
    const maxAmt = Math.min(adv.remaining_amount, props.invoice.amount_due - totalOffsetAmount.value);
    selectedAdvances.value.push({ advance_id: adv.id, amount: Math.max(1, Math.floor(maxAmt)) });
  }
}

function getAdvanceAmount(advId) {
  return selectedAdvances.value.find(a => a.advance_id === advId)?.amount ?? 0;
}

function setAdvanceAmount(advId, val) {
  const item = selectedAdvances.value.find(a => a.advance_id === advId);
  if (item) item.amount = val;
}

const payForm = useForm({
  amount:           0,
  payment_date:     today,
  allocation_date:  today,
  method:           'cash',
  fund_id:          '',
  reference:        '',
  notes:            '',
  advance_allocations: [],
});

function submitPayment() {
  payAmountError.value = '';

  if (paymentType.value === 'offset') {
    // Chỉ đối trừ — không cần cash validation
    if (selectedAdvances.value.length === 0) {
      payAmountError.value = 'Vui lòng chọn ít nhất một khoản ứng trước.';
      return;
    }
    // Submit từng advance allocation riêng lẻ
    submitOffsetAllocations();
    return;
  }

  if (paymentType.value === 'combined') {
    // Đối trừ trước, rồi thu tiền
    if (selectedAdvances.value.length === 0) {
      payAmountError.value = 'Vui lòng chọn ít nhất một khoản ứng trước.';
      return;
    }
    submitOffsetAllocations(true);
    return;
  }

  // Cash only
  if (!payForm.amount || payForm.amount <= 0) {
    payAmountError.value = 'Vui lòng nhập số tiền hợp lệ.';
    return;
  }
  if (payForm.amount > props.invoice.amount_due) {
    payAmountError.value = `Số tiền không được vượt quá số còn lại (${new Intl.NumberFormat('vi-VN').format(props.invoice.amount_due)} ₫).`;
    return;
  }
  if (!payForm.fund_id) {
    payAmountError.value = 'Vui lòng chọn quỹ / tài khoản.';
    return;
  }

  payForm.post(route('accounting.invoices.payments.store', props.invoice.id), {
    onSuccess: () => resetPayForm(),
  });
}

function submitOffsetAllocations(thenCash = false) {
  // Submit allocations tuần tự, sau đó submit cash nếu combined
  let pending = [...selectedAdvances.value];
  function doNext() {
    if (pending.length === 0) {
      if (thenCash && payForm.amount > 0 && payForm.fund_id) {
        payForm.post(route('accounting.invoices.payments.store', props.invoice.id), {
          onSuccess: () => resetPayForm(),
        });
      } else {
        resetPayForm();
        router.reload();
      }
      return;
    }
    const alloc = pending.shift();
    router.post(
      route('accounting.invoices.advance-allocations.store', props.invoice.id),
      {
        opening_advance_id: alloc.advance_id,
        allocated_amount:   alloc.amount,
        allocation_date:    payForm.allocation_date,
        reason:             null,
      },
      {
        preserveScroll: true,
        onSuccess: () => doNext(),
      }
    );
  }
  doNext();
}

function resetPayForm() {
  showPaymentForm.value = false;
  paymentType.value     = 'cash';
  selectedAdvances.value = [];
  payAmountError.value  = '';
  payForm.reset();
}

function deletePayment(paymentId) {
  if (confirm('Xóa thanh toán này?')) {
    router.delete(route('accounting.invoices.payments.destroy', [props.invoice.id, paymentId]));
  }
}

// ── Actions ──────────────────────────────────────────────────────────────────
function action(act) {
  router.post(route(`accounting.invoices.${act}`, props.invoice.id));
}

function cancelInvoice() {
  if (confirm('Hủy hóa đơn này? Bút toán hạch toán sẽ được đảo ngược tự động.')) {
    action('cancel');
  }
}

function deleteInvoice() {
  if (confirm('Xóa vĩnh viễn hóa đơn này?')) {
    router.delete(route('accounting.invoices.destroy', props.invoice.id));
  }
}

// ── Advance allocation (separate section) ────────────────────────────────────
const showAdvanceForm   = ref(false);
const advanceProcessing = ref(false);
const advanceForm = ref({
  opening_advance_id: '',
  allocated_amount:   0,
  allocation_date:    today,
  reason:             '',
});

const canAdvanceAllocate = computed(() =>
  can('accounting.manage') &&
  ['sent', 'overdue'].includes(props.invoice.status) &&
  (props.available_advances?.length ?? 0) > 0 &&
  (props.invoice.amount_due ?? 0) > 0
);

const selectedAdvance = computed(() =>
  props.available_advances?.find(a => a.id === Number(advanceForm.value.opening_advance_id))
);

const maxAllocatable = computed(() => {
  const adv = selectedAdvance.value;
  if (!adv) return props.invoice.amount_due ?? 0;
  return Math.min(adv.remaining_amount, props.invoice.amount_due ?? 0);
});

function onAdvanceSelect() {
  advanceForm.value.allocated_amount = maxAllocatable.value;
}

function submitAdvanceAllocation() {
  if (advanceProcessing.value) return;
  advanceProcessing.value = true;
  router.post(
    route('accounting.invoices.advance-allocations.store', props.invoice.id),
    advanceForm.value,
    {
      onSuccess: () => {
        showAdvanceForm.value = false;
        advanceForm.value = {
          opening_advance_id: '',
          allocated_amount:   0,
          allocation_date:    today,
          reason:             '',
        };
      },
      onFinish: () => { advanceProcessing.value = false; },
    }
  );
}

// ── Reverse allocation ────────────────────────────────────────────────────────
const showReverseModal        = ref(false);
const reverseReason           = ref('');
const reversingAllocationId   = ref(null);

function confirmReverseAllocation(allocationId) {
  reversingAllocationId.value = allocationId;
  reverseReason.value         = '';
  showReverseModal.value      = true;
}

function submitReverseAllocation() {
  if (!reverseReason.value.trim()) return;
  router.delete(
    route('accounting.invoice-advance-allocations.destroy', reversingAllocationId.value),
    { data: { reason: reverseReason.value } },
    {
      onSuccess: () => { showReverseModal.value = false; },
    }
  );
}

// ── E-invoice ─────────────────────────────────────────────────────────────────
const eInvForm    = useForm({ e_inv_template: '01GTKT0/001', e_inv_series: '' });
const cancelReason = ref('');

function issueEInvoice() {
  eInvForm.post(route('accounting.invoices.issue-einvoice', props.invoice.id));
}

function cancelEInvoice() {
  if (!cancelReason.value) return;
  router.post(route('accounting.invoices.cancel-einvoice', props.invoice.id), { e_inv_cancel_reason: cancelReason.value });
}
</script>
