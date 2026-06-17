<template>
  <AppLayout>
    <div class="max-w-4xl space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <Link :href="route('purchasing.purchase-invoices.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <h1 class="text-2xl font-bold text-gray-900">{{ invoice.code }}</h1>
          <StatusBadge :color="invoice.status_color">{{ invoice.status_label }}</StatusBadge>
        </div>
        <div class="flex gap-2 flex-wrap">
          <!-- Ghi nhận TSCĐ -->
          <Link v-if="canRecordAsset && hasPermission('accounting.manage')"
            :href="route('accounting.fixed-assets.create', { purchase_invoice_id: invoice.id })"
            class="border border-indigo-400 text-indigo-600 hover:bg-indigo-50 px-4 py-2 rounded-lg text-sm font-medium">
            Ghi nhận TSCĐ
          </Link>
          <!-- Thu hồi thanh toán -->
          <button v-if="canRecall && hasPermission('purchasing.approve')"
            @click="showRecallModal = true" :disabled="busy"
            class="border border-orange-400 text-orange-600 hover:bg-orange-50 px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60">
            Thu hồi thanh toán
          </button>
          <!-- FSM transition buttons -->
          <template v-for="tr in invoice.transitions" :key="tr.value">
            <button @click="doTransition(tr.value)" :disabled="busy"
              :class="tr.value === 'cancelled' ? 'border border-red-300 text-red-600 hover:bg-red-50' : 'bg-primary-600 hover:bg-primary-700 text-white'"
              class="px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60">
              {{ tr.label }}
            </button>
          </template>
          <Link v-if="canEditInvoice" :href="route('purchasing.purchase-invoices.edit', invoice.id)"
            class="border border-gray-300 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium">
            Sửa
          </Link>
          <button v-if="canDeleteInvoice" @click="showDeleteModal = true" :disabled="busy"
            class="border border-red-400 text-red-600 hover:bg-red-50 px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60">
            Xóa
          </button>
        </div>
      </div>

      <Teleport to="body">
        <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
            <h3 class="text-base font-semibold text-gray-900 mb-2">Xóa hóa đơn đầu vào</h3>
            <p class="text-sm text-gray-600 mb-5">
              Bạn có chắc muốn <strong class="text-red-600">xóa vĩnh viễn</strong> hóa đơn
              <strong>{{ invoice.code }}</strong>? Thao tác này không thể hoàn tác.
            </p>
            <div class="flex justify-end gap-2">
              <button @click="showDeleteModal = false"
                class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</button>
              <button @click="doDelete"
                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium">Xóa hóa đơn</button>
            </div>
          </div>
        </div>
      </Teleport>

      <!-- Banner: hàng hóa mua về bán -->
      <div v-if="invoice.is_goods_purchase && invoice.status === 'valid'"
        class="bg-green-50 border border-green-200 rounded-xl p-4 flex items-start gap-3">
        <svg class="w-5 h-5 text-green-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <p class="text-sm text-green-800">
          <strong>Hóa đơn mua hàng hóa</strong> — bút toán <strong>Nợ 1561, Nợ 1331 / Có 3311</strong> sẽ được tạo tự động khi xác nhận phiếu nhập kho.
          Vào <strong>Kho › Phiếu nhập kho</strong> để xác nhận nhập kho và tạo bút toán.
        </p>
      </div>

      <!-- Banner: hóa đơn dịch vụ (bút toán auto draft) -->
      <div v-if="invoice.is_service_purchase && invoice.status === 'valid'"
        class="bg-blue-50 border border-blue-200 rounded-xl p-4 flex items-start gap-3">
        <svg class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <p class="text-sm text-blue-800">
          <strong>Hóa đơn dịch vụ</strong> — bút toán nháp đã được tạo tự động (Nợ CP / Có 3311).
          Vào <strong>Kế toán › Bút toán</strong> để duyệt trước khi hạch toán chính thức.
        </p>
      </div>

      <!-- H1: posting job failure banner -->
      <div v-if="invoice.posting_job && invoice.posting_job.status === 'failed'"
        class="bg-red-50 border border-red-200 rounded-xl p-4 flex items-start gap-3">
        <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
        </svg>
        <div>
          <p class="text-sm font-medium text-red-800">Hạch toán tự động thất bại</p>
          <p class="text-xs text-red-600 mt-0.5">{{ invoice.posting_job.error_message }}</p>
          <p class="text-xs text-red-500 mt-1">Vào <strong>Kế toán › Bút toán › Thử lại</strong> (Job #{{ invoice.posting_job.job_id }}).</p>
        </div>
      </div>

      <!-- Thông tin chung -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-5 text-sm">
          <div>
            <p class="text-gray-500 mb-1">Nhà cung cấp</p>
            <p class="font-medium text-gray-900">{{ invoice.supplier }}</p>
          </div>
          <div>
            <p class="text-gray-500 mb-1">MST nhà cung cấp</p>
            <p class="font-medium text-gray-900">{{ invoice.supplier_tax_code ?? '—' }}</p>
          </div>
          <div>
            <p class="text-gray-500 mb-1">Đơn mua hàng</p>
            <Link v-if="invoice.purchase_order_id"
              :href="route('purchasing.purchase-orders.show', invoice.purchase_order_id)"
              class="font-mono text-primary-600 hover:underline font-medium">
              {{ invoice.purchase_order }}
            </Link>
            <p v-else class="font-medium text-gray-900">—</p>
          </div>
          <div>
            <p class="text-gray-500 mb-1">Số HĐ NCC</p>
            <p class="font-medium text-gray-900">{{ invoice.invoice_number ?? '—' }}</p>
          </div>
          <div>
            <p class="text-gray-500 mb-1">Ngày hóa đơn</p>
            <p class="font-medium text-gray-900">{{ invoice.invoice_date ?? '—' }}</p>
          </div>
          <div>
            <p class="text-gray-500 mb-1">Hạn thanh toán</p>
            <p class="font-medium" :class="invoice.remaining > 0 ? 'text-red-600' : 'text-gray-900'">
              {{ invoice.due_date ?? '—' }}
            </p>
          </div>
          <div>
            <p class="text-gray-500 mb-1">Người tạo</p>
            <p class="font-medium text-gray-900">{{ invoice.creator }}</p>
          </div>
          <div v-if="invoice.notes" class="col-span-2">
            <p class="text-gray-500 mb-1">Ghi chú</p>
            <p class="text-gray-800">{{ invoice.notes }}</p>
          </div>
        </div>
      </div>

      <!-- Dòng hàng từ đơn mua -->
      <div v-if="invoice.po_items?.length" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
          <h2 class="text-base font-semibold text-gray-800">Dòng hàng</h2>
          <span class="text-xs text-gray-400">Loại dòng hàng xác định tài khoản kế toán khi nhập kho</span>
        </div>
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-100">
            <tr>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Sản phẩm</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">SL</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Đơn giá</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">VAT%</th>
              <th class="px-4 py-3 font-semibold text-gray-600">Loại dòng hàng</th>
              <th class="px-4 py-3 font-semibold text-gray-600 text-center">TK Nợ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="item in invoice.po_items" :key="item.id" class="hover:bg-gray-50">
              <td class="px-4 py-3 text-gray-800">{{ item.product }}</td>
              <td class="px-4 py-3 text-right text-gray-700">{{ item.quantity }}</td>
              <td class="px-4 py-3 text-right text-gray-700">{{ formatVnd(item.unit_price) }}</td>
              <td class="px-4 py-3 text-right text-gray-500">{{ item.vat_rate }}%</td>
              <td class="px-4 py-3">
                <select v-if="hasPermission('purchasing.approve')"
                  :value="item.line_type"
                  @change="updateLineType(item, $event.target.value)"
                  class="border border-gray-300 rounded-md px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-primary-500">
                  <option v-for="lt in lineTypes" :key="lt.value" :value="lt.value">{{ lt.label }}</option>
                </select>
                <span v-else class="text-xs text-gray-700">{{ lineTypeLabel(item.line_type) }}</span>
              </td>
              <td class="px-4 py-3 text-center">
                <span class="font-mono text-xs px-2 py-0.5 rounded"
                  :class="lineTypeAccountClass(item.line_type)">
                  {{ lineTypeAccount(item.line_type) }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Tổng giá trị -->
      <div class="bg-white rounded-xl border border-gray-200 p-5">
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 text-center">
          <div class="p-3 bg-gray-50 rounded-lg">
            <p class="text-xs text-gray-500 mb-1">Trước thuế</p>
            <p class="font-semibold text-gray-900">{{ formatVnd(invoice.subtotal) }}</p>
          </div>
          <div class="p-3 bg-gray-50 rounded-lg">
            <p class="text-xs text-gray-500 mb-1">Thuế VAT</p>
            <p class="font-semibold text-gray-900">{{ formatVnd(invoice.tax_amount) }}</p>
          </div>
          <div class="p-3 bg-blue-50 rounded-lg">
            <p class="text-xs text-blue-600 mb-1">Tổng cộng</p>
            <p class="font-bold text-blue-700">{{ formatVnd(invoice.total) }}</p>
          </div>
          <div v-if="invoice.advance_allocated_amount > 0" class="p-3 bg-orange-50 rounded-lg">
            <p class="text-xs text-orange-600 mb-1">Đối trừ ứng trước</p>
            <p class="font-bold text-orange-700">{{ formatVnd(invoice.advance_allocated_amount) }}</p>
          </div>
          <div class="p-3 rounded-lg" :class="invoice.remaining > 0 ? 'bg-red-50' : 'bg-green-50'">
            <p class="text-xs mb-1" :class="invoice.remaining > 0 ? 'text-red-600' : 'text-green-600'">Còn lại</p>
            <p class="font-bold" :class="invoice.remaining > 0 ? 'text-red-700' : 'text-green-700'">{{ formatVnd(invoice.remaining) }}</p>
          </div>
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
          NCC này còn <strong>{{ formatVnd(available_advances.reduce((s, a) => s + a.remaining_amount, 0)) }} đ</strong>
          ứng trước đầu kỳ chưa đối trừ.
        </p>
        <button @click="showAdvanceForm = !showAdvanceForm"
          class="flex-shrink-0 px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-white rounded-lg text-sm font-medium">
          Đối trừ ứng trước
        </button>
      </div>

      <!-- Tài liệu đính kèm -->
      <FileAttachments
        :attachments="invoice.attachments ?? []"
        :upload-url="route('attachments.store', { type: 'purchase_invoice', id: invoice.id })"
      />

      <!-- Tab: Thanh toán -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
          <h2 class="text-base font-semibold text-gray-800">Lịch sử thanh toán</h2>
          <button v-if="canPay" @click="showPayForm = !showPayForm"
            class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-lg text-sm font-medium">
            + Ghi nhận TT
          </button>
        </div>

        <!-- Form thêm thanh toán -->
        <div v-if="showPayForm" class="px-5 py-4 border-b border-gray-100 bg-green-50">
          <form @submit.prevent="submitPayment" class="grid grid-cols-2 sm:grid-cols-4 gap-3 items-start">
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Số tiền <span class="text-red-500">*</span></label>
              <input v-model.number="payForm.amount" type="number" min="1" step="any" :max="invoice.remaining"
                class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-500" />
              <p class="text-xs text-green-700 font-medium mt-0.5">{{ formatVnd(payForm.amount || 0) }}</p>
              <div class="flex gap-1 mt-1.5">
                <button v-for="pct in [30, 50, 70, 100]" :key="pct" type="button"
                  @click="payForm.amount = Math.round(invoice.remaining * pct / 100)"
                  class="px-2 py-0.5 text-xs rounded border border-gray-300 text-gray-500 hover:bg-green-100 hover:border-green-400 hover:text-green-700 transition-colors">
                  {{ pct }}%
                </button>
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Ngày TT <span class="text-red-500">*</span></label>
              <input v-model="payForm.payment_date" type="date"
                class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-500" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Hình thức</label>
              <select v-model="payForm.method"
                class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                <option value="bank_transfer">Chuyển khoản</option>
                <option value="cash">Tiền mặt</option>
                <option value="other">Khác</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Mã GD / Số CT</label>
              <input v-model="payForm.reference" type="text"
                class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-500" />
            </div>
            <div class="col-span-2 sm:col-span-3">
              <label class="block text-xs font-medium text-gray-600 mb-1">Ghi chú</label>
              <input v-model="payForm.notes" type="text"
                class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-500" />
            </div>
            <div class="flex items-end gap-2">
              <button type="submit" :disabled="payForm.processing"
                class="bg-green-600 hover:bg-green-700 text-white px-4 py-1.5 rounded-lg text-sm font-medium disabled:opacity-50">
                Lưu
              </button>
              <button type="button" @click="showPayForm = false"
                class="bg-gray-100 hover:bg-gray-200 text-gray-600 px-3 py-1.5 rounded-lg text-sm">
                Hủy
              </button>
            </div>
          </form>
        </div>

        <!-- Danh sách thanh toán -->
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-100">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Hình thức</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã GD / Số CT</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Số tiền</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Người ghi</th>
              <th class="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="p in invoice.payments" :key="p.id"
              :class="p.status === 'voided' ? 'bg-gray-50 opacity-60' : 'hover:bg-gray-50'">
              <td class="px-5 py-3 text-gray-700" :class="p.status === 'voided' ? 'line-through' : ''">{{ p.payment_date }}</td>
              <td class="px-5 py-3 text-gray-700">{{ p.method_label }}</td>
              <td class="px-5 py-3 text-gray-600">{{ p.reference ?? '—' }}</td>
              <td class="px-5 py-3 text-right font-medium"
                :class="p.status === 'voided' ? 'text-gray-400 line-through' : 'text-green-700'">
                {{ formatVnd(p.amount) }}
              </td>
              <td class="px-5 py-3">
                <span v-if="p.status === 'voided'"
                  class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-gray-200 text-gray-600"
                  :title="p.void_reason">Đã thu hồi</span>
                <span v-else class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">Hợp lệ</span>
              </td>
              <td class="px-5 py-3 text-gray-600">{{ p.creator }}</td>
              <td class="px-5 py-3 text-right">
                <button v-if="canPay && p.status === 'active'" @click="deletePayment(p.id)"
                  class="text-red-500 hover:text-red-700 text-xs">Xóa</button>
              </td>
            </tr>
            <tr v-if="!invoice.payments?.length">
              <td colspan="7" class="px-5 py-6 text-center text-gray-400 text-sm">Chưa có thanh toán nào</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ── Đối trừ ứng trước ── -->
    <div v-if="invoice.advance_allocations?.length || canAdvanceAllocate"
      class="bg-white rounded-xl border border-gray-200 overflow-hidden">
      <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
        <h2 class="text-base font-semibold text-gray-800">Đối trừ ứng trước đầu kỳ</h2>
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
                {{ adv.reference_no || ('ADV-' + adv.id) }} — còn {{ formatVnd(adv.remaining_amount) }} đ ({{ adv.fiscal_year }})
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
            <input v-model="advanceForm.reason" type="text" placeholder="Đối trừ ứng trước đầu kỳ năm..."
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
          <strong>Lưu ý kế toán:</strong> Đối trừ ứng trước không tạo phiếu chi — không ghi Có 1111/1121.
          Bút toán gốc (Nợ 3311) đã được ghi nhận từ số dư đầu kỳ năm {{ new Date().getFullYear() - 1 }}.
        </div>
      </div>

      <!-- Danh sách đối trừ -->
      <table class="w-full text-sm">
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
          <tr v-for="a in invoice.advance_allocations" :key="a.id" class="hover:bg-gray-50">
            <td class="px-5 py-3 text-gray-700">{{ a.allocation_date }}</td>
            <td class="px-5 py-3 font-mono text-xs text-gray-600">{{ a.advance_ref }}</td>
            <td class="px-5 py-3 text-right font-medium text-orange-700">{{ formatVnd(a.allocated_amount) }}</td>
            <td class="px-5 py-3 text-gray-500">{{ a.reason || '—' }}</td>
            <td class="px-5 py-3 text-gray-600">{{ a.creator }}</td>
            <td class="px-5 py-3 text-right">
              <button v-if="hasPermission('purchasing.approve')"
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

    <!-- ── Modal: Thu hồi đối trừ ── -->
    <Teleport to="body">
      <div v-if="showReverseModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md">
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="font-bold text-gray-900">Thu hồi đối trừ ứng trước</h3>
          </div>
          <div class="p-6 space-y-4">
            <p class="text-sm text-gray-600">
              Thu hồi chứng từ đối trừ sẽ hoàn lại số ứng trước cho khoản ứng trước đầu kỳ và cập nhật lại số còn phải trả của hóa đơn.
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
            <button @click="showReverseModal = false" class="px-4 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Hủy</button>
            <button @click="submitReverseAllocation" :disabled="!reverseReason.trim() || busy"
              class="px-5 py-2 text-sm font-medium bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-40">
              Xác nhận thu hồi
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ── Modal: Thu hồi thanh toán ── -->
    <Teleport to="body">
      <div v-if="showRecallModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md">
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="font-bold text-gray-900 text-lg">Thu hồi thanh toán — {{ invoice.code }}</h3>
          </div>
          <div class="p-6 space-y-4">
            <div class="grid grid-cols-2 gap-2 text-sm bg-gray-50 p-3 rounded-lg">
              <div><span class="text-gray-500">Đã thanh toán:</span>
                <span class="font-semibold text-green-700 ml-1">{{ formatVnd(invoice.paid_amount) }}</span></div>
              <div><span class="text-gray-500">Số khoản TT:</span>
                <span class="font-semibold ml-1">{{ activePaymentCount }}</span></div>
            </div>
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800">
              <p class="font-semibold mb-1">⚠ Cảnh báo</p>
              <p>Thao tác này sẽ đảo toàn bộ bút toán thanh toán, cập nhật lại công nợ NCC, sổ cái và chuyển hóa đơn về trạng thái <strong>Hợp lệ</strong>.</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">
                Lý do thu hồi <span class="text-red-500">*</span>
              </label>
              <textarea v-model="recallReason" rows="2" placeholder="Nhập lý do..."
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400"
                :class="{ 'border-red-500': recallReasonError }" />
              <p v-if="recallReasonError" class="mt-1 text-xs text-red-600">{{ recallReasonError }}</p>
            </div>
            <label class="flex items-start gap-2 cursor-pointer text-sm text-gray-700">
              <input type="checkbox" v-model="recallConfirmed" class="mt-0.5 shrink-0" />
              <span>Tôi xác nhận thu hồi toàn bộ thanh toán của hóa đơn này</span>
            </label>
          </div>
          <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-3">
            <button @click="showRecallModal = false" class="px-4 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Hủy</button>
            <button @click="submitRecall" :disabled="!recallConfirmed || busy"
              class="px-5 py-2 text-sm font-medium bg-orange-600 text-white rounded-lg hover:bg-orange-700 disabled:opacity-40">
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

const props = defineProps({ invoice: Object, available_advances: Array });

const { hasPermission } = usePermission();
const { formatVnd } = useCurrency();

const lineTypes = [
  { value: 'goods',       label: 'Hàng hóa bán lại',  account: '1561' },
  { value: 'material',    label: 'Nguyên vật liệu',    account: '1521' },
  { value: 'tool',        label: 'Công cụ dụng cụ',    account: '1531' },
  { value: 'service',     label: 'Dịch vụ / chi phí',  account: '6421' },
  { value: 'fixed_asset', label: 'Tài sản cố định',    account: '2111' },
];

function lineTypeLabel(value) {
  return lineTypes.find(t => t.value === value)?.label ?? value;
}

function lineTypeAccount(value) {
  return lineTypes.find(t => t.value === value)?.account ?? '1561';
}

function lineTypeAccountClass(value) {
  const classes = {
    goods:       'bg-blue-50 text-blue-700',
    material:    'bg-purple-50 text-purple-700',
    tool:        'bg-yellow-50 text-yellow-700',
    service:     'bg-orange-50 text-orange-700',
    fixed_asset: 'bg-indigo-50 text-indigo-700',
  };
  return classes[value] ?? 'bg-gray-100 text-gray-600';
}

function updateLineType(item, newType) {
  router.patch(
    route('purchasing.purchase-invoices.items.line-type', { purchaseInvoice: props.invoice.id, item: item.id }),
    { line_type: newType },
    {
      preserveScroll: true,
      onSuccess: () => { item.line_type = newType; },
    }
  );
}
const busy = ref(false);
const showPayForm          = ref(false);
const showDeleteModal      = ref(false);
const showRecallModal      = ref(false);
const showAdvanceForm      = ref(false);
const advanceProcessing    = ref(false);
const advanceForm = ref({
  opening_advance_id: '',
  allocated_amount:   0,
  allocation_date:    new Date().toISOString().split('T')[0],
  reason:             '',
});
const reverseReason = ref('');
const showReverseModal = ref(false);
const reversingAllocationId = ref(null);
const recallReason      = ref('');
const recallConfirmed   = ref(false);
const recallReasonError = ref('');

const canRecall = computed(() =>
  ['paid', 'partial_paid'].includes(props.invoice.status)
);

const canRecordAsset = computed(() =>
  ['valid', 'paid', 'partial_paid'].includes(props.invoice.status)
);

const canEditInvoice = computed(() =>
  ['valid', 'pending', 'received', 'reviewing', 'need_supplement'].includes(props.invoice.status)
);

const canDeleteInvoice = computed(() =>
  ['cancelled', 'valid'].includes(props.invoice.status)
);

const activePaymentCount = computed(() =>
  props.invoice.payments?.filter(p => p.status === 'active').length ?? 0
);

const canPay = computed(() =>
  hasPermission('purchasing.create') &&
  !['cancelled', 'paid'].includes(props.invoice.status)
);

const canAdvanceAllocate = computed(() =>
  hasPermission('purchasing.approve') &&
  ['valid', 'partial_paid'].includes(props.invoice.status) &&
  (props.available_advances?.length ?? 0) > 0 &&
  (props.invoice.remaining ?? 0) > 0
);

const selectedAdvance = computed(() =>
  props.available_advances?.find(a => a.id === Number(advanceForm.value.opening_advance_id))
);

const maxAllocatable = computed(() => {
  const adv = selectedAdvance.value
  if (!adv) return props.invoice.remaining ?? 0
  return Math.min(adv.remaining_amount, props.invoice.remaining ?? 0)
});

const payForm = useForm({
  amount:       props.invoice.remaining ?? 0,
  payment_date: new Date().toISOString().split('T')[0],
  method:       'bank_transfer',
  reference:    '',
  notes:        '',
});

function doDelete() {
  showDeleteModal.value = false;
  router.delete(route('purchasing.purchase-invoices.destroy', props.invoice.id));
}

function doTransition(status) {
  if (busy.value) return;
  busy.value = true;
  router.post(route('purchasing.purchase-invoices.transition', props.invoice.id), { status }, {
    onFinish: () => { busy.value = false; },
  });
}

function submitPayment() {
  payForm.post(route('purchasing.purchase-invoices.payments.store', props.invoice.id), {
    onSuccess: () => {
      showPayForm.value = false;
      payForm.reset();
    },
  });
}

function deletePayment(paymentId) {
  if (!confirm('Xóa thanh toán này? Bút toán liên quan sẽ bị đảo.')) return;
  router.delete(route('purchasing.purchase-invoices.payments.destroy', [props.invoice.id, paymentId]));
}

function onAdvanceSelect() {
  advanceForm.value.allocated_amount = maxAllocatable.value;
}

function submitAdvanceAllocation() {
  if (advanceProcessing.value) return;
  advanceProcessing.value = true;
  router.post(
    route('purchasing.purchase-invoices.advance-allocations.store', props.invoice.id),
    advanceForm.value,
    {
      onSuccess: () => {
        showAdvanceForm.value = false;
        advanceForm.value = {
          opening_advance_id: '',
          allocated_amount: 0,
          allocation_date: new Date().toISOString().split('T')[0],
          reason: '',
        };
      },
      onFinish: () => { advanceProcessing.value = false; },
    }
  );
}

function confirmReverseAllocation(allocationId) {
  reversingAllocationId.value = allocationId;
  reverseReason.value = '';
  showReverseModal.value = true;
}

function submitReverseAllocation() {
  if (!reverseReason.value.trim() || busy.value) return;
  busy.value = true;
  router.delete(
    route('purchasing.advance-allocations.destroy', reversingAllocationId.value),
    { data: { reason: reverseReason.value } },
    {
      onSuccess: () => { showReverseModal.value = false; },
      onFinish:  () => { busy.value = false; },
    }
  );
}

function submitRecall() {
  recallReasonError.value = '';
  if (!recallReason.value.trim() || recallReason.value.trim().length < 5) {
    recallReasonError.value = 'Lý do thu hồi phải ít nhất 5 ký tự.';
    return;
  }
  if (!recallConfirmed.value || busy.value) return;
  busy.value = true;
  router.post(route('purchasing.purchase-invoices.recall-payments', props.invoice.id),
    { reason: recallReason.value.trim() },
    {
      onSuccess: () => { showRecallModal.value = false; recallReason.value = ''; recallConfirmed.value = false; },
      onFinish: ()  => { busy.value = false; },
    }
  );
}
</script>
