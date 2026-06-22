<template>
  <AppLayout>
    <div class="max-w-5xl space-y-6">
      <!-- Header -->
      <div class="flex items-center gap-3">
        <Link :href="route('projects.projects.show', project.id)" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </Link>
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Thêm chi phí phát sinh</h1>
          <p class="text-sm text-gray-500 mt-0.5">{{ project.code }} — {{ project.name }}</p>
        </div>
      </div>

      <!-- Section 1: Thông tin chung -->
      <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">1. Thông tin chung</h2>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Dự án</label>
            <div class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 text-gray-600 font-mono">
              {{ project.code }} — {{ project.name }}
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày chứng từ <span class="text-red-500">*</span></label>
            <input v-model="form.expense_date" type="date"
              :class="['w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500', form.errors.expense_date ? 'border-red-400' : 'border-gray-300']" />
            <p v-if="form.errors.expense_date" class="text-red-500 text-xs mt-1">{{ form.errors.expense_date }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Hình thức ghi nhận <span class="text-red-500">*</span></label>
            <select v-model="form.payment_method" @change="onPaymentMethodChange"
              :class="['w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500', form.errors.payment_method ? 'border-red-400' : 'border-gray-300']">
              <option v-for="(mode, key) in PAYMENT_MODES" :key="key" :value="key">{{ mode.label }}</option>
            </select>
            <p v-if="form.errors.payment_method" class="text-red-500 text-xs mt-1">{{ form.errors.payment_method }}</p>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Số hóa đơn / chứng từ</label>
            <input v-model="form.invoice_number" type="text" placeholder="Số HĐ NCC, biên lai..."
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Diễn giải chung</label>
            <input v-model="form.description" type="text" placeholder="Mô tả tổng quát cho toàn bộ chứng từ..."
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
        </div>
      </div>

      <!-- Section 2: Đối tượng liên quan -->
      <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">2. Đối tượng liên quan</h2>

        <!-- Ghi công nợ NCC / nhà thầu có hóa đơn -->
        <template v-if="form.payment_method === 'payable'">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Nhà cung cấp <span class="text-red-500">*</span></label>
              <RemoteSearchSelect
                v-model="form.supplier_id"
                :search-url="route('search.suppliers')"
                :display-text="form.supplier_name"
                placeholder="Tìm theo tên, mã NCC, MST..."
                :has-error="!!form.errors.supplier_id"
                @change="(opt) => { form.supplier_name = opt ? (opt.code ? opt.code + ' — ' + opt.label : opt.label) : '' }"
              />
              <p v-if="form.errors.supplier_id" class="text-red-500 text-xs mt-1">{{ form.errors.supplier_id }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">TK Có</label>
              <div class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 font-mono text-blue-700">
                3311 — Phải trả nhà cung cấp
              </div>
            </div>
          </div>
        </template>

        <!-- Chi tiền mặt -->
        <template v-else-if="form.payment_method === 'cash'">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Quỹ tiền mặt <span class="text-red-500">*</span></label>
              <select v-model="form.fund_id"
                :class="['w-full border rounded-lg px-3 py-2 text-sm', form.errors.fund_id ? 'border-red-400' : 'border-gray-300']">
                <option value="">-- Chọn quỹ --</option>
                <option v-for="f in funds" :key="f.id" :value="f.id">{{ f.name }} ({{ f.account_code }})</option>
              </select>
              <p v-if="form.errors.fund_id" class="text-red-500 text-xs mt-1">{{ form.errors.fund_id }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">TK Có</label>
              <div class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 font-mono text-blue-700">
                {{ selectedFundAccount || '1111' }} — Tiền mặt
              </div>
            </div>
          </div>
        </template>

        <!-- Chi ngân hàng -->
        <template v-else-if="form.payment_method === 'bank'">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tài khoản ngân hàng <span class="text-red-500">*</span></label>
              <select v-model="form.bank_account_id"
                :class="['w-full border rounded-lg px-3 py-2 text-sm', form.errors.bank_account_id ? 'border-red-400' : 'border-gray-300']">
                <option value="">-- Chọn TK ngân hàng --</option>
                <option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.bank_name }} - {{ b.account_number }} ({{ b.account_code }})</option>
              </select>
              <p v-if="form.errors.bank_account_id" class="text-red-500 text-xs mt-1">{{ form.errors.bank_account_id }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">TK Có</label>
              <div class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 font-mono text-blue-700">
                {{ selectedBankAccount || '1121' }} — Tiền gửi ngân hàng
              </div>
            </div>
          </div>
        </template>

        <!-- Quyết toán tạm ứng / Ghi nhận lương nội bộ -->
        <template v-else-if="form.payment_method === 'advance' || form.payment_method === 'salary'">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">
                Nhân viên
                <span v-if="form.payment_method === 'advance'" class="text-red-500">* (quyết toán tạm ứng)</span>
              </label>
              <select v-model="form.employee_id"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">-- Chọn nhân viên --</option>
                <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.code }} — {{ e.name }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">TK Có</label>
              <div class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 font-mono text-blue-700">
                {{ form.payment_method === 'salary' ? '3341 — Phải trả công nhân viên' : '141 — Tạm ứng' }}
              </div>
            </div>
          </div>
        </template>

        <!-- Thuê khoán / chưa trả (3388) -->
        <template v-else-if="form.payment_method === 'misc'">
          <div class="text-sm text-gray-500 bg-amber-50 rounded-lg px-4 py-3 border border-amber-200">
            TK Có: <span class="font-mono text-blue-700 font-semibold">3388 — Phải trả khác (chưa trả)</span>
            <span class="ml-2 text-amber-600 text-xs">· Dùng khi chưa thanh toán cho đội/người nhận khoán</span>
          </div>
          <!-- Thông tin đội/người nhận khoán -->
          <div class="border border-amber-200 rounded-lg bg-amber-50 p-4 space-y-3">
            <p class="text-xs font-semibold text-amber-700">Thông tin đội / người nhận khoán <span class="font-normal text-amber-600">(không bắt buộc)</span></p>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div>
                <label class="block text-xs text-gray-600 mb-1">Tên đội / người nhận khoán</label>
                <input v-model="form.contractor_name" type="text" placeholder="Đội thợ Nguyễn Văn A..."
                  class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm" />
              </div>
              <div>
                <label class="block text-xs text-gray-600 mb-1">Người đại diện</label>
                <input v-model="form.contractor_representative" type="text"
                  class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm" />
              </div>
              <div>
                <label class="block text-xs text-gray-600 mb-1">Số điện thoại</label>
                <input v-model="form.contractor_phone" type="text"
                  class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm" />
              </div>
              <div>
                <label class="block text-xs text-gray-600 mb-1">CCCD / MST cá nhân</label>
                <input v-model="form.contractor_id_number" type="text"
                  class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm" />
              </div>
              <div>
                <label class="block text-xs text-gray-600 mb-1">Số hợp đồng khoán</label>
                <input v-model="form.contract_number" type="text"
                  class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm" />
              </div>
            </div>
          </div>
        </template>

        <!-- Trích BHXH / KPCĐ (338 chi tiết) -->
        <template v-else-if="form.payment_method === 'insurance'">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Loại khoản trích <span class="text-red-500">*</span></label>
              <select v-model="form.credit_account"
                :class="['w-full border rounded-lg px-3 py-2 text-sm', form.errors.credit_account ? 'border-red-400' : 'border-gray-300']">
                <option value="">-- Chọn loại --</option>
                <option v-for="ins in INSURANCE_ACCOUNTS" :key="ins.value" :value="ins.value">{{ ins.label }}</option>
              </select>
              <p v-if="form.errors.credit_account" class="text-red-500 text-xs mt-1">{{ form.errors.credit_account }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">TK Có</label>
              <div class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 font-mono text-blue-700">
                {{ form.credit_account || '338xx' }} — {{ insuranceAccountLabel }}
              </div>
            </div>
          </div>
          <p class="text-xs text-gray-500">Không dùng TK 338 tổng hợp — phải chọn TK chi tiết ở trên.</p>
        </template>

        <!-- Khấu hao TSCĐ / máy thi công (214) -->
        <template v-else-if="form.payment_method === 'depreciation'">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">TSCĐ / máy thi công <span class="text-red-500">*</span></label>
              <select v-model="form.fixed_asset_id"
                :class="['w-full border rounded-lg px-3 py-2 text-sm', form.errors.fixed_asset_id ? 'border-red-400' : 'border-gray-300']">
                <option value="">-- Chọn TSCĐ --</option>
                <option v-for="fa in fixedAssets" :key="fa.id" :value="fa.id">{{ fa.code }} — {{ fa.name }}</option>
              </select>
              <p v-if="form.errors.fixed_asset_id" class="text-red-500 text-xs mt-1">{{ form.errors.fixed_asset_id }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">TK Có</label>
              <div class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 font-mono text-blue-700">
                214 — Hao mòn TSCĐ
              </div>
            </div>
          </div>
          <p class="text-xs text-gray-500">Bút toán: Nợ 154 / Có 214 — ghi nhận khấu hao TSCĐ hoặc máy thi công vào chi phí dự án.</p>
        </template>

        <!-- Thông tin đội/người nhận khoán cho freelance labor lines (khi method khác misc) -->
        <div v-if="hasFreelanceContractors && !['payable','misc'].includes(form.payment_method)"
          class="mt-3 border border-amber-200 rounded-lg bg-amber-50 p-4 space-y-3">
          <p class="text-xs font-semibold text-amber-700">Thông tin đội/người nhận khoán
            <span class="font-normal text-amber-600">(không bắt buộc)</span>
          </p>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
              <label class="block text-xs text-gray-600 mb-1">Tên đội/người nhận khoán</label>
              <input v-model="form.contractor_name" type="text" placeholder="Đội thợ Nguyễn Văn A..."
                class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm" />
            </div>
            <div>
              <label class="block text-xs text-gray-600 mb-1">Người đại diện</label>
              <input v-model="form.contractor_representative" type="text"
                class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm" />
            </div>
            <div>
              <label class="block text-xs text-gray-600 mb-1">Số điện thoại</label>
              <input v-model="form.contractor_phone" type="text"
                class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm" />
            </div>
            <div>
              <label class="block text-xs text-gray-600 mb-1">CCCD/MST cá nhân</label>
              <input v-model="form.contractor_id_number" type="text"
                class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm" />
            </div>
            <div>
              <label class="block text-xs text-gray-600 mb-1">Số hợp đồng khoán</label>
              <input v-model="form.contract_number" type="text"
                class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm" />
            </div>
          </div>
        </div>

        <!-- Cảnh báo: subcontractor_invoice mà không dùng payable -->
        <div v-if="hasSubcontractorLines && form.payment_method !== 'payable'"
          class="mt-3 border border-orange-200 rounded-lg bg-orange-50 px-4 py-2.5 text-xs text-orange-700">
          ⚠ Có dòng "Nhà thầu phụ có hóa đơn" — nên chọn hình thức <strong>Ghi công nợ NCC</strong> và chọn nhà cung cấp.
        </div>
      </div>

      <!-- Section 3: Bảng dòng chi phí -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="p-4 border-b border-gray-100 flex items-center justify-between flex-wrap gap-2">
          <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">3. Dòng chi phí</h2>
          <div class="flex items-center gap-3">
            <label class="flex items-center gap-1.5 text-xs text-gray-500 cursor-pointer select-none">
              <input type="checkbox" v-model="showAdvancedAccounts" class="rounded border-gray-300 text-primary-600" />
              Hiện TK Nợ / Nâng cao
            </label>
            <button type="button" @click="addLine"
              class="text-xs bg-primary-600 hover:bg-primary-700 text-white px-3 py-1.5 rounded-lg font-medium">
              + Thêm dòng
            </button>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="text-left px-3 py-2.5 font-semibold text-gray-600 w-32">Danh mục</th>
                <th class="text-left px-3 py-2.5 font-semibold text-gray-600 min-w-[200px]">Mô tả <span class="text-red-500">*</span></th>
                <th class="text-left px-3 py-2.5 font-semibold text-gray-600 w-40" v-if="showAdvancedAccounts">TK Nợ</th>
                <th class="text-left px-3 py-2.5 font-semibold text-gray-600 w-40" v-if="showLaborType">Loại NC</th>
                <th class="text-right px-3 py-2.5 font-semibold text-gray-600 w-36">Số tiền (excl VAT) <span class="text-red-500">*</span></th>
                <th class="text-right px-3 py-2.5 font-semibold text-gray-600 w-20">VAT %</th>
                <th class="text-right px-3 py-2.5 font-semibold text-gray-600 w-32">Tiền VAT</th>
                <th class="text-right px-3 py-2.5 font-semibold text-gray-600 w-36">Tổng cộng</th>
                <th class="px-3 py-2.5 w-8" />
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="(line, idx) in form.lines" :key="idx" class="hover:bg-gray-50">
                <!-- Danh mục -->
                <td class="px-3 py-2">
                  <select v-model="line.category" @change="onCategoryChange(line)"
                    class="w-full border border-gray-300 rounded px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-primary-500">
                    <option v-for="c in expenseCategories" :key="c.value" :value="c.value">{{ c.label }}</option>
                  </select>
                </td>
                <!-- Mô tả -->
                <td class="px-3 py-2">
                  <input v-model="line.description" type="text" placeholder="Mô tả chi phí..."
                    :class="['w-full border rounded px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-primary-500', !line.description ? 'border-red-300' : 'border-gray-300']" />
                </td>
                <!-- TK Nợ (advanced) -->
                <td class="px-3 py-2" v-if="showAdvancedAccounts">
                  <input v-model="line.debit_account" type="text" :placeholder="expenseCategoryDebit(line.category)"
                    :class="['w-full border rounded px-2 py-1.5 text-xs font-mono focus:outline-none focus:ring-1 focus:ring-primary-500',
                      line.debit_account && /^15[26]/.test(line.debit_account) ? 'border-red-400 bg-red-50' : 'border-gray-300']" />
                  <p v-if="line.debit_account && /^15[26]/.test(line.debit_account)" class="text-xs text-red-500 mt-0.5">Dùng phiếu xuất kho</p>
                  <p v-else-if="line.debit_account && line.debit_account.startsWith('154')" class="text-xs text-blue-600 mt-0.5">Hạch toán thẳng WIP 154</p>
                  <p v-else-if="line.debit_account" class="text-xs text-amber-600 mt-0.5">Cần kết chuyển →154</p>
                </td>
                <!-- Loại NC (nếu category=labor) -->
                <td class="px-3 py-2" v-if="showLaborType">
                  <select v-if="line.category === 'labor'" v-model="line.labor_type" @change="onLaborTypeChange(line)"
                    class="w-full border border-gray-300 rounded px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-primary-500">
                    <option value="">— Chọn loại —</option>
                    <option v-for="lt in LABOR_TYPES" :key="lt.value" :value="lt.value">{{ lt.label }}</option>
                  </select>
                  <span v-else class="text-xs text-gray-400">—</span>
                </td>
                <!-- Số tiền excl VAT -->
                <td class="px-3 py-2">
                  <input v-model="line.amount" type="number" min="0" step="1" placeholder="0"
                    :class="['w-full border rounded px-2 py-1.5 text-xs text-right font-mono focus:outline-none focus:ring-1 focus:ring-primary-500',
                      !line.amount ? 'border-red-300' : 'border-gray-300']"
                    @input="computeLineVat(line)" />
                </td>
                <!-- VAT % -->
                <td class="px-3 py-2">
                  <input v-model="line.vat_rate" type="number" min="0" max="100" step="1"
                    :placeholder="isVatDisabled(line) ? 'N/A' : '0'"
                    :disabled="isVatDisabled(line)"
                    :class="['w-full border rounded px-2 py-1.5 text-xs text-right focus:outline-none focus:ring-1 focus:ring-primary-500',
                      isVatDisabled(line) ? 'bg-gray-100 border-gray-200 text-gray-400 cursor-not-allowed' : 'border-gray-300']"
                    @input="computeLineVat(line)" />
                </td>
                <!-- Tiền VAT -->
                <td class="px-3 py-2">
                  <input v-model="line.vat_amount" type="number" min="0" step="1"
                    :placeholder="isVatDisabled(line) ? 'N/A' : '0'"
                    :disabled="isVatDisabled(line)"
                    :class="['w-full border rounded px-2 py-1.5 text-xs text-right font-mono focus:outline-none focus:ring-1 focus:ring-primary-500',
                      isVatDisabled(line) ? 'bg-gray-100 border-gray-200 text-gray-400 cursor-not-allowed' : 'border-gray-300']" />
                </td>
                <!-- Tổng cộng -->
                <td class="px-3 py-2 text-right font-mono text-sm font-medium text-gray-800 whitespace-nowrap">
                  {{ formatVnd(lineTotal(line)) }}
                </td>
                <!-- Xóa dòng -->
                <td class="px-3 py-2 text-center">
                  <button type="button" @click="removeLine(idx)"
                    class="text-gray-300 hover:text-red-500 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </td>
              </tr>

              <tr v-if="form.lines.length === 0">
                <td :colspan="colSpan" class="px-4 py-8 text-center text-gray-400 text-sm">
                  Chưa có dòng chi phí nào.
                  <button type="button" @click="addLine" class="text-primary-600 hover:underline ml-1">Thêm dòng đầu tiên</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Freelance detail section — has_vat_invoice + PIT -->
        <div v-if="hasFreelanceContractors" class="border-t border-amber-200 bg-amber-50 px-4 py-3">
          <p class="text-xs font-semibold text-amber-700 mb-2">Chi tiết dòng "Thuê khoán cá nhân/đội nhóm"</p>
          <div v-for="(line, idx) in freelanceLines" :key="idx"
            class="grid grid-cols-1 sm:grid-cols-5 gap-3 mb-2 pb-2 border-b border-amber-100 last:border-0 last:mb-0 last:pb-0">
            <div class="sm:col-span-1 text-xs text-gray-600 self-center truncate font-medium">
              {{ line.description || 'Dòng ' + (form.lines.indexOf(line) + 1) }}
            </div>
            <div class="self-center">
              <label class="flex items-center gap-1.5 text-xs cursor-pointer select-none">
                <input type="checkbox" v-model="line.has_vat_invoice" @change="onHasVatChange(line)"
                  class="rounded border-gray-300 text-blue-600" />
                Có hóa đơn VAT
              </label>
            </div>
            <div v-if="['cash', 'bank'].includes(form.payment_method)" class="self-center">
              <label class="flex items-center gap-1.5 text-xs cursor-pointer select-none">
                <input type="checkbox" v-model="line.pit_withholding_enabled"
                  class="rounded border-gray-300 text-amber-600" />
                Khấu trừ TNCN
              </label>
            </div>
            <div v-if="line.pit_withholding_enabled && ['cash', 'bank'].includes(form.payment_method)"
              class="flex items-center gap-2">
              <input v-model="line.pit_rate" type="number" min="0" max="100" step="0.1"
                class="w-20 border border-gray-300 rounded px-2 py-1 text-xs" placeholder="10" />
              <span class="text-xs text-gray-500">%</span>
            </div>
            <div v-if="line.pit_withholding_enabled && ['cash', 'bank'].includes(form.payment_method)"
              class="text-xs space-y-0.5 self-center">
              <div class="text-red-600">Thuế: <strong>{{ formatVnd(computePit(line)) }}</strong></div>
              <div class="text-green-700">Thực trả: <strong>{{ formatVnd(computeNet(line)) }}</strong></div>
            </div>
          </div>
        </div>

        <div class="px-4 py-3 border-t border-gray-100 text-xs text-gray-400">
          Mặc định TK Nợ = 154 → ghi thẳng vào chi phí dở dang dự án.
          Bật "Hiện TK Nợ" để hạch toán qua TK trung gian (6237, 6271...) → sau đó kết chuyển sang 154.
          Không được dùng TK 152/156.
        </div>
      </div>

      <!-- Section 4: Tổng hợp + nút hành động -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
          <!-- Tổng hợp -->
          <div class="space-y-1.5 text-sm">
            <div class="flex justify-between gap-8">
              <span class="text-gray-500">Tổng trước VAT:</span>
              <span class="font-mono font-medium text-gray-800">{{ formatVnd(totalBeforeVat) }}</span>
            </div>
            <div class="flex justify-between gap-8">
              <span class="text-gray-500">Tổng VAT:</span>
              <span class="font-mono font-medium text-gray-800">{{ formatVnd(totalVat) }}</span>
            </div>
            <div class="flex justify-between gap-8 border-t border-gray-200 pt-1.5 mt-1">
              <span class="font-semibold text-gray-700">Tổng cộng:</span>
              <span class="font-mono font-bold text-gray-900 text-base">{{ formatVnd(totalAmount) }}</span>
            </div>
            <div class="flex justify-between gap-8 text-xs">
              <span class="text-blue-600">→ Vào TK 154 (WIP):</span>
              <span class="font-mono font-medium text-blue-700">{{ formatVnd(totalTo154) }}</span>
            </div>
            <div v-if="totalNeedTransfer > 0" class="flex justify-between gap-8 text-xs">
              <span class="text-amber-600">→ Cần kết chuyển sang 154:</span>
              <span class="font-mono font-medium text-amber-700">{{ formatVnd(totalNeedTransfer) }}</span>
            </div>
          </div>

          <!-- Preview bút toán ngắn -->
          <div class="bg-gray-50 rounded-lg p-3 text-xs space-y-1 min-w-[220px]">
            <p class="font-semibold text-gray-600 mb-1">Bút toán dự kiến:</p>
            <template v-for="(line, i) in form.lines" :key="i">
              <div class="font-mono">
                <span class="text-blue-700">Nợ {{ line.debit_account || expenseCategoryDebit(line.category) }}</span>
                <span class="text-gray-500 ml-2">{{ formatVnd(Number(line.amount) || 0) }}</span>
              </div>
              <div v-if="Number(line.vat_amount) > 0" class="font-mono">
                <span class="text-blue-700">Nợ 1331</span>
                <span class="text-gray-500 ml-2">{{ formatVnd(Number(line.vat_amount)) }}</span>
              </div>
            </template>
            <div class="font-mono border-t border-gray-200 pt-1 mt-1">
              <span class="text-red-600">Có {{ creditAccountDisplay }}</span>
              <span class="text-gray-500 ml-2">{{ formatVnd(totalAmount) }}</span>
            </div>
          </div>
        </div>

        <!-- Lỗi submit -->
        <p v-if="submitError" class="text-red-600 text-xs mt-3">{{ submitError }}</p>

        <!-- Nút hành động -->
        <div class="flex flex-wrap justify-end gap-3 mt-5 pt-4 border-t border-gray-100">
          <Link :href="route('projects.projects.show', project.id)"
            class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
            Hủy
          </Link>
          <button type="button" @click="submit(false)"
            :disabled="form.processing || !canSubmit"
            class="px-4 py-2 border border-gray-400 rounded-lg text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-40">
            {{ form.processing ? 'Đang xử lý...' : 'Lưu nháp' }}
          </button>
          <button type="button" @click="submit(true)"
            :disabled="form.processing || !canSubmit"
            class="px-6 py-2 bg-primary-600 hover:bg-primary-700 disabled:bg-gray-300 text-white rounded-lg text-sm font-medium">
            {{ form.processing ? 'Đang xử lý...' : 'Ghi nhận' }}
          </button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import RemoteSearchSelect from '@/Components/Shared/RemoteSearchSelect.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  project:           Object,
  expenseCategories: Array,
  funds:             Array,
  bankAccounts:      Array,
  employees:         Array,
  fixedAssets:       Array,
});

const { formatVnd } = useCurrency();

const PAYMENT_MODES = {
  payable:      { label: 'Ghi công nợ NCC / nhà thầu có HĐ (Có 3311)', credit: '3311' },
  cash:         { label: 'Chi tiền mặt (Có 1111)',                       credit: '1111' },
  bank:         { label: 'Chi ngân hàng (Có 1121)',                      credit: '1121' },
  advance:      { label: 'Quyết toán tạm ứng (Có 141)',                  credit: '141'  },
  salary:       { label: 'Ghi nhận lương nội bộ (Có 3341)',              credit: '3341' },
  misc:         { label: 'Thuê khoán / chưa trả (Có 3388)',              credit: '3388' },
  insurance:    { label: 'Trích BHXH / KPCĐ (Có 338 chi tiết)',          credit: '338'  },
  depreciation: { label: 'Khấu hao TSCĐ / máy thi công (Có 214)',       credit: '214'  },
};

const INSURANCE_ACCOUNTS = [
  { value: '33831', label: '33831 — BHXH người sử dụng lao động (17.5%)' },
  { value: '33832', label: '33832 — BHXH người lao động (8%)' },
  { value: '33841', label: '33841 — BHYT người sử dụng lao động (3%)' },
  { value: '33842', label: '33842 — BHYT người lao động (1.5%)' },
  { value: '3385',  label: '3385 — BHTN (gộp NLĐ + NSDLĐ)' },
  { value: '33821', label: '33821 — KPCĐ người sử dụng lao động (2%)' },
];

const LABOR_TYPES = [
  { value: 'internal_employee',     label: 'Nhân công nội bộ' },
  { value: 'freelance_contractor',  label: 'Thuê khoán cá nhân/đội nhóm/thời vụ' },
  { value: 'subcontractor_invoice', label: 'Nhà thầu phụ có hóa đơn' },
  { value: 'insurance_allocation',  label: 'Trích BHXH/KPCĐ' },
];

// VAT is disabled for these payment methods regardless of labor type
const VAT_DISABLED_METHODS = new Set(['misc', 'insurance', 'depreciation', 'salary', 'advance']);

const showAdvancedAccounts = ref(false);
const submitError = ref('');

const form = useForm({
  expense_date:    '',
  invoice_number:  '',
  payment_method:  'payable',
  description:     '',
  supplier_id:     null,
  supplier_name:   '',
  fund_id:         '',
  bank_account_id: '',
  employee_id:     '',
  fixed_asset_id:  '',
  credit_account:  '',   // used for insurance 338 sub-TK
  // Contractor info (header-level, shared across all lines)
  contractor_name:           '',
  contractor_representative: '',
  contractor_phone:          '',
  contractor_id_number:      '',
  contract_number:           '',
  post_immediately: true,
  lines: [],
});

addLine();

function makeLine() {
  const defaultCat = props.expenseCategories.find(c => c.value === 'labor');
  return {
    category:              'labor',
    description:           '',
    debit_account:         defaultCat?.defaultDebitAccount ?? '6271',
    labor_type:            '',
    amount:                '',
    vat_rate:              '',
    vat_amount:            '',
    has_vat_invoice:       false,
    pit_withholding_enabled: false,
    pit_rate:              10,
  };
}

function addLine() {
  form.lines.push(makeLine());
}

function removeLine(idx) {
  form.lines.splice(idx, 1);
}

function onCategoryChange(line) {
  const cat = props.expenseCategories.find(c => c.value === line.category);
  if (cat && (!line.debit_account || line.debit_account === '154')) {
    line.debit_account = cat.defaultDebitAccount;
  }
  if (line.category !== 'labor') {
    line.labor_type = '';
    line.pit_withholding_enabled = false;
    line.has_vat_invoice = false;
  }
}

function onLaborTypeChange(line) {
  // Gợi ý payment_method khi chọn loại nhân công
  if (line.labor_type === 'subcontractor_invoice' && form.payment_method !== 'payable') {
    // không tự chuyển, chỉ hiện cảnh báo qua hasSubcontractorLines
  }
  if (line.labor_type === 'insurance_allocation' && form.payment_method !== 'insurance') {
    // gợi ý chuyển sang insurance nhưng không tự chuyển
  }
  if (line.labor_type !== 'freelance_contractor') {
    line.has_vat_invoice = false;
    line.pit_withholding_enabled = false;
  }
}

function onHasVatChange(line) {
  if (!line.has_vat_invoice) {
    line.vat_rate = '';
    line.vat_amount = '';
  }
}

function isVatDisabled(line) {
  if (VAT_DISABLED_METHODS.has(form.payment_method)) return true;
  return line.labor_type === 'freelance_contractor' && !line.has_vat_invoice;
}

function onPaymentMethodChange() {
  form.supplier_id    = null;
  form.supplier_name  = '';
  form.fund_id        = '';
  form.bank_account_id = '';
  form.employee_id    = '';
  form.fixed_asset_id = '';
  form.credit_account = '';
  submitError.value   = '';
  // Clear VAT for VAT-disabled methods
  if (VAT_DISABLED_METHODS.has(form.payment_method)) {
    form.lines.forEach(l => { l.vat_rate = ''; l.vat_amount = ''; });
  }
}

function expenseCategoryDebit(categoryValue) {
  const cat = props.expenseCategories.find(c => c.value === categoryValue);
  return cat?.defaultDebitAccount ?? '6271';
}

function computeLineVat(line) {
  const amount  = parseFloat(line.amount) || 0;
  const vatRate = parseFloat(line.vat_rate) || 0;
  if (vatRate > 0) {
    line.vat_amount = Math.round(amount * vatRate / 100);
  } else {
    line.vat_amount = '';
  }
}

function lineTotal(line) {
  return (parseFloat(line.amount) || 0) + (parseFloat(line.vat_amount) || 0);
}

function computePit(line) {
  if (!line.pit_withholding_enabled || !['cash', 'bank'].includes(form.payment_method)) return 0;
  const amount = parseFloat(line.amount) || 0;
  return Math.round(amount * (parseFloat(line.pit_rate) || 0) / 100);
}

function computeNet(line) {
  return Math.max(0, (parseFloat(line.amount) || 0) - computePit(line));
}

const freelanceLines = computed(() =>
  form.lines.filter(l => l.labor_type === 'freelance_contractor')
);
const hasFreelanceContractors = computed(() => freelanceLines.value.length > 0);
const hasSubcontractorLines   = computed(() =>
  form.lines.some(l => l.labor_type === 'subcontractor_invoice')
);

const totalBeforeVat = computed(() =>
  form.lines.reduce((s, l) => s + (parseFloat(l.amount) || 0), 0)
);
const totalVat = computed(() =>
  form.lines.reduce((s, l) => s + (parseFloat(l.vat_amount) || 0), 0)
);
const totalAmount = computed(() => totalBeforeVat.value + totalVat.value);

const totalTo154 = computed(() =>
  form.lines
    .filter(l => !l.debit_account || l.debit_account.startsWith('154'))
    .reduce((s, l) => s + (parseFloat(l.amount) || 0), 0)
);
const totalNeedTransfer = computed(() =>
  form.lines
    .filter(l => l.debit_account && !l.debit_account.startsWith('154') && !/^15[26]/.test(l.debit_account))
    .reduce((s, l) => s + (parseFloat(l.amount) || 0), 0)
);

const selectedFundAccount = computed(() => {
  if (!form.fund_id) return null;
  return props.funds.find(f => f.id === form.fund_id)?.account_code ?? '1111';
});
const selectedBankAccount = computed(() => {
  if (!form.bank_account_id) return null;
  return props.bankAccounts.find(b => b.id == form.bank_account_id)?.account_code ?? '1121';
});
const insuranceAccountLabel = computed(() => {
  if (!form.credit_account) return 'chọn loại khoản trích';
  return INSURANCE_ACCOUNTS.find(i => i.value === form.credit_account)?.label.split(' — ')[1] ?? form.credit_account;
});
const creditAccountDisplay = computed(() => {
  if (form.payment_method === 'insurance') return form.credit_account || '338xx';
  return PAYMENT_MODES[form.payment_method]?.credit ?? '3311';
});

const showLaborType = computed(() => form.lines.some(l => l.category === 'labor'));
const colSpan = computed(() => {
  let n = 7;
  if (showAdvancedAccounts.value) n++;
  if (showLaborType.value) n++;
  return n;
});

const canSubmit = computed(() => {
  if (!form.expense_date) return false;
  if (form.lines.length === 0) return false;
  if (form.lines.some(l => !l.description || !(parseFloat(l.amount) > 0))) return false;
  if (form.lines.some(l => l.debit_account && /^15[26]/.test(l.debit_account))) return false;
  if (form.payment_method === 'payable' && !form.supplier_id) return false;
  if (form.payment_method === 'cash' && !form.fund_id) return false;
  if (form.payment_method === 'bank' && !form.bank_account_id) return false;
  if (form.payment_method === 'insurance' && !form.credit_account) return false;
  if (form.payment_method === 'depreciation' && !form.fixed_asset_id) return false;
  return true;
});

function submit(postImmediately) {
  submitError.value = '';

  if (!canSubmit.value) {
    submitError.value = 'Vui lòng điền đủ thông tin bắt buộc (ngày, mô tả, số tiền) cho tất cả dòng.';
    return;
  }

  form.post_immediately = postImmediately;

  const cleanLines = form.lines.map(l => ({
    ...l,
    amount:     Math.round(parseFloat(l.amount) || 0),
    vat_amount: isVatDisabled(l) ? 0 : Math.round(parseFloat(l.vat_amount) || 0),
    vat_rate:   isVatDisabled(l) ? 0 : (parseFloat(l.vat_rate) || 0),
    pit_rate:   parseFloat(l.pit_rate) || 0,
    debit_account: l.debit_account || null,
    labor_type:    l.labor_type || null,
    has_vat_invoice: !!l.has_vat_invoice,
  }));

  form.transform(data => ({ ...data, lines: cleanLines }))
    .post(route('projects.projects.expenses.batch', props.project.id), {
      onError: (errors) => {
        const first = Object.values(errors)[0];
        submitError.value = first ?? 'Có lỗi xảy ra. Vui lòng kiểm tra lại.';
      },
    });
}
</script>
