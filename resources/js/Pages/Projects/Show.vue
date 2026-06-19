<template>
  <AppLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex items-start justify-between flex-wrap gap-y-3">
        <div class="flex items-center gap-3">
          <Link :href="route('projects.projects.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
          </Link>
          <div>
            <div class="flex items-center gap-3">
              <h1 class="text-2xl font-bold text-gray-900">{{ project.name }}</h1>
              <StatusBadge :color="project.status_color">{{ project.status_label }}</StatusBadge>
            </div>
            <p class="text-sm text-gray-500 mt-0.5">{{ project.code }} · {{ project.customer.name }}</p>
          </div>
        </div>
        <div class="flex gap-2 flex-wrap">
          <Link v-if="can('projects.manage')" :href="route('projects.projects.edit', project.id)"
            class="border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium">
            Sửa thông tin
          </Link>
          <template v-for="t in project.allowed_transitions" :key="t.value">
            <button @click="doTransition(t.value)"
              :class="['px-4 py-2 rounded-lg text-sm font-medium', t.value === 'cancelled' ? 'bg-red-600 hover:bg-red-700 text-white' : 'bg-primary-600 hover:bg-primary-700 text-white']">
              {{ t.label }}
            </button>
          </template>
          <button v-if="project.status === 'cancelled' && can('projects.delete')"
            @click="confirmDelete"
            class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Xóa dự án
          </button>
        </div>
      </div>

      <!-- Info cards -->
      <div class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Tiến độ</p>
          <div class="mt-2">
            <div class="flex items-center justify-between mb-1">
              <span class="text-2xl font-bold text-gray-900">{{ project.progress }}%</span>
              <span class="text-xs text-gray-400">{{ doneTasks }}/{{ project.tasks.length }} tasks</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2">
              <div class="bg-primary-500 h-2 rounded-full transition-all" :style="{ width: project.progress + '%' }" />
            </div>
          </div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Doanh thu (HĐ KH)</p>
          <p class="text-2xl font-bold text-gray-900 mt-2">{{ contract_value != null ? formatVnd(contract_value) : '—' }}</p>
          <p v-if="project.budget" class="text-xs text-gray-400 mt-1">NS dự phòng: {{ formatVnd(project.budget) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Vật tư xuất kho</p>
          <p class="text-2xl font-bold text-gray-900 mt-2">{{ formatVnd(stockExitTotal) }}</p>
          <p class="text-xs text-gray-400 mt-1">Phát sinh trực tiếp: {{ formatVnd(directMaterialTotal) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Thời gian</p>
          <p class="text-sm font-medium text-gray-800 mt-2">{{ project.start_date ?? '—' }}</p>
          <p class="text-xs text-gray-500">→ {{ project.expected_end_date ?? '—' }}</p>
          <p v-if="project.actual_end_date" class="text-xs text-green-600 mt-1">HT: {{ project.actual_end_date }}</p>
        </div>
      </div>

      <!-- Main content: tabs -->
      <div class="bg-white rounded-xl border border-gray-200">
        <!-- Tab nav -->
        <div class="flex border-b border-gray-200 overflow-x-auto">
          <button v-for="tab in tabs" :key="tab.id" @click="activeTab = tab.id"
            :class="['px-4 py-3 text-sm font-medium border-b-2 -mb-px whitespace-nowrap', activeTab === tab.id ? 'border-primary-600 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700']">
            {{ tab.label }}
            <span v-if="tab.count !== undefined" class="ml-1.5 bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded-full text-xs">{{ tab.count }}</span>
          </button>
        </div>

        <!-- Tasks tab -->
        <div v-if="activeTab === 'tasks'" class="p-5 space-y-4">
          <form v-if="can('projects.manage')" @submit.prevent="addTask" class="flex gap-3 flex-wrap">
            <input v-model="taskForm.title" type="text" placeholder="Tiêu đề công việc..."
              class="flex-1 min-w-48 border border-gray-300 rounded-lg px-3 py-2 text-sm" required />
            <select v-model="taskForm.assigned_to" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
              <option :value="null">Chưa giao</option>
              <option v-for="u in allUsers" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
            <select v-model="taskForm.priority" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
              <option value="low">Thấp</option>
              <option value="medium">Trung bình</option>
              <option value="high">Cao</option>
            </select>
            <input v-model="taskForm.due_date" type="date" class="border border-gray-300 rounded-lg px-3 py-2 text-sm" />
            <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
              Thêm
            </button>
          </form>

          <div class="space-y-2">
            <div v-for="task in project.tasks" :key="task.id"
              class="flex items-center gap-3 p-3 rounded-lg border border-gray-100 hover:bg-gray-50">
              <button @click="cycleStatus(task)"
                :class="['w-5 h-5 rounded-full border-2 flex-shrink-0 flex items-center justify-center', taskStatusClass(task.status)]">
                <svg v-if="task.status === 'done'" class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                </svg>
              </button>
              <div class="flex-1 min-w-0">
                <p :class="['text-sm font-medium', task.status === 'done' ? 'line-through text-gray-400' : 'text-gray-800']">
                  {{ task.title }}
                </p>
                <p v-if="task.assigned_to" class="text-xs text-gray-500">{{ task.assigned_to.name }}</p>
              </div>
              <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', priorityClass(task.priority)]">
                {{ task.priority === 'high' ? 'Cao' : task.priority === 'medium' ? 'TB' : 'Thấp' }}
              </span>
              <StatusBadge :color="task.status_color" class="text-xs">{{ task.status_label }}</StatusBadge>
              <span v-if="task.due_date" class="text-xs text-gray-400">{{ task.due_date }}</span>
              <button v-if="can('projects.manage')" @click="deleteTask(task.id)" class="text-gray-300 hover:text-red-500 ml-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            <p v-if="!project.tasks.length" class="text-center text-gray-400 py-8 text-sm">Chưa có công việc nào</p>
          </div>
        </div>

        <!-- Members tab -->
        <div v-if="activeTab === 'members'" class="p-5 space-y-4">
          <form v-if="can('projects.manage')" @submit.prevent="addMember" class="flex gap-3 flex-wrap">
            <select v-model="memberForm.employee_id" class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm" required>
              <option value="">-- Chọn cán bộ / CNV --</option>
              <option v-for="e in allEmployees" :key="e.id" :value="e.id">
                {{ e.code }} — {{ e.name }}<template v-if="e.position"> ({{ e.position }})</template>
              </option>
            </select>
            <input v-model="memberForm.role" type="text" placeholder="Vai trò trong dự án..."
              class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm" />
            <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
              Thêm
            </button>
          </form>
          <div class="space-y-2">
            <div v-for="m in project.members" :key="m.id"
              class="flex items-center justify-between p-3 rounded-lg border border-gray-100">
              <div>
                <p class="text-sm font-medium text-gray-800">
                  <span class="font-mono text-xs text-gray-400 mr-1">{{ m.employee.code }}</span>
                  {{ m.employee.name }}
                </p>
                <p class="text-xs text-gray-400">
                  <template v-if="m.employee.position">{{ m.employee.position }}</template>
                  <template v-if="m.employee.position && m.employee.department"> · </template>
                  <template v-if="m.employee.department">{{ m.employee.department }}</template>
                </p>
                <p v-if="m.role" class="text-xs text-primary-600 mt-0.5">Vai trò: {{ m.role }}</p>
              </div>
              <button v-if="can('projects.manage')" @click="removeMember(m.id)" class="text-gray-400 hover:text-red-500">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            <p v-if="!project.members.length" class="text-center text-gray-400 py-8 text-sm">Chưa có thành viên nào</p>
          </div>
        </div>

        <!-- Vật tư đã xuất tab (từ stock_exits) -->
        <div v-if="activeTab === 'stock-exits'" class="overflow-x-auto">
          <div class="p-4 flex items-center justify-between flex-wrap gap-2 border-b border-gray-100">
            <p class="text-sm text-gray-500">Vật tư thực tế đã xuất kho cho dự án. Chỉ hiển thị phiếu có mục đích "Xuất cho dự án".</p>
            <Link :href="route('warehouse.stock-exits.create')"
              class="bg-primary-600 hover:bg-primary-700 text-white px-3 py-1.5 rounded-lg text-xs font-medium">
              + Tạo phiếu xuất kho
            </Link>
          </div>
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Ngày xuất</th>
                <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Mã phiếu</th>
                <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Kho</th>
                <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Mã VT</th>
                <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Tên vật tư</th>
                <th class="text-right px-4 py-2.5 font-semibold text-gray-600">Số lượng</th>
                <th class="text-right px-4 py-2.5 font-semibold text-gray-600">Đơn giá vốn</th>
                <th class="text-right px-4 py-2.5 font-semibold text-gray-600">Thành tiền</th>
                <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Bút toán</th>
                <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Trạng thái</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="(item, idx) in stockExitItems" :key="idx"
                :class="['hover:bg-gray-50', item.is_cancelled ? 'opacity-50' : '']">
                <td class="px-4 py-2 text-gray-600">{{ item.exit_date }}</td>
                <td class="px-4 py-2">
                  <Link :href="route('warehouse.stock-exits.show', item.exit_id)"
                    class="font-mono text-xs text-primary-600 hover:underline">
                    {{ item.exit_code }}
                  </Link>
                </td>
                <td class="px-4 py-2 text-gray-600 text-xs">{{ item.warehouse }}</td>
                <td class="px-4 py-2 font-mono text-xs text-gray-500">{{ item.product_code }}</td>
                <td class="px-4 py-2 text-gray-800">{{ item.product_name }}</td>
                <td class="px-4 py-2 text-right text-gray-700">{{ item.quantity }} <span class="text-xs text-gray-400">{{ item.unit }}</span></td>
                <td class="px-4 py-2 text-right text-gray-600">{{ formatVnd(item.unit_cost) }}</td>
                <td class="px-4 py-2 text-right font-medium" :class="item.is_cancelled ? 'line-through text-gray-400' : 'text-gray-900'">
                  {{ formatVnd(item.total_cost) }}
                </td>
                <td class="px-4 py-2 text-xs font-mono text-gray-500">{{ item.journal_code }}</td>
                <td class="px-4 py-2">
                  <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', item.is_cancelled ? 'bg-red-100 text-red-600' : 'bg-green-100 text-green-700']">
                    {{ item.status_label }}
                  </span>
                </td>
              </tr>
              <tr v-if="!stockExitItems.length">
                <td colspan="10" class="px-4 py-10 text-center text-gray-400">
                  Chưa có phiếu xuất kho nào cho dự án.
                  <br><span class="text-xs">Tạo phiếu xuất kho và chọn mục đích "Xuất cho dự án" để ghi nhận vật tư vào đây.</span>
                </td>
              </tr>
            </tbody>
            <tfoot v-if="stockExitItems.filter(i => !i.is_cancelled).length" class="bg-gray-50 border-t border-gray-200">
              <tr>
                <td colspan="7" class="px-4 py-2.5 text-right font-semibold text-gray-700">Tổng giá trị vật tư xuất:</td>
                <td class="px-4 py-2.5 text-right font-bold text-gray-900">{{ formatVnd(stockExitTotal) }}</td>
                <td colspan="2" />
              </tr>
            </tfoot>
          </table>
        </div>

        <!-- Vật tư phát sinh tab -->
        <div v-if="activeTab === 'direct-materials'" class="space-y-4">
          <!-- Add form -->
          <div v-if="can('projects.manage')" class="p-4 border-b border-gray-100">
            <div class="flex items-center justify-between mb-3">
              <h3 class="text-sm font-semibold text-gray-700">Thêm vật tư phát sinh</h3>
              <button @click="showDmForm = !showDmForm"
                class="text-xs text-primary-600 hover:underline">
                {{ showDmForm ? 'Ẩn form' : '+ Thêm mới' }}
              </button>
            </div>

            <div v-if="showDmForm" class="space-y-3 bg-gray-50 rounded-lg p-4">
              <!-- Loại xử lý -->
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <label v-for="ht in handlingTypes" :key="ht.value"
                  :class="['flex items-start gap-2 p-3 border-2 rounded-lg cursor-pointer text-sm', dmForm.handling_type === ht.value ? 'border-primary-500 bg-primary-50' : 'border-gray-200 bg-white']">
                  <input type="radio" v-model="dmForm.handling_type" :value="ht.value" class="mt-0.5" />
                  <div>
                    <p class="font-medium text-gray-800">{{ ht.label }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">{{ ht.description }}</p>
                  </div>
                </label>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Ngày phát sinh <span class="text-red-500">*</span></label>
                  <input v-model="dmForm.occurrence_date" type="date"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" required />
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Tên vật tư <span class="text-red-500">*</span></label>
                  <input v-model="dmForm.product_name" type="text" placeholder="Nhập tên vật tư..."
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Số lượng <span class="text-red-500">*</span></label>
                  <input v-model="dmForm.quantity" type="number" min="0.001" step="any"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Đơn giá <span class="text-red-500">*</span></label>
                  <input v-model="dmForm.unit_price" type="number" min="0" step="any"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                </div>
              </div>

              <!-- Tài khoản Có — chỉ hiện khi type = journal_entry -->
              <div v-if="dmForm.handling_type === 'journal_entry'" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Tài khoản Có (Cr) <span class="text-red-500">*</span></label>
                  <input v-model="dmForm.credit_account_code" type="text" placeholder="vd: 3311, 1111, 1121"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono" />
                  <p class="text-xs text-gray-400 mt-1">Nợ TK 154 / Có TK {{ dmForm.credit_account_code || '3311' }}</p>
                </div>
                <div class="flex items-end">
                  <button @click="previewJe" type="button"
                    class="border border-purple-300 text-purple-700 hover:bg-purple-50 px-3 py-2 rounded-lg text-sm font-medium w-full">
                    Xem trước bút toán
                  </button>
                </div>
              </div>

              <!-- Preview bút toán -->
              <div v-if="jePreview.length" class="bg-purple-50 border border-purple-200 rounded-lg p-3">
                <p class="text-xs font-semibold text-purple-700 mb-2">Preview bút toán:</p>
                <table class="w-full text-xs">
                  <tr v-for="line in jePreview" :key="line.account_code">
                    <td class="font-mono text-gray-700 pr-4">{{ line.account_code }}</td>
                    <td class="text-gray-600 pr-4">{{ line.description }}</td>
                    <td class="text-right text-blue-700 pr-4">{{ line.side === 'debit' ? formatVnd(line.amount) : '' }}</td>
                    <td class="text-right text-red-700">{{ line.side === 'credit' ? formatVnd(line.amount) : '' }}</td>
                  </tr>
                </table>
              </div>

              <!-- Ghi chú + chứng từ -->
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Ghi chú / Lý do</label>
                  <input v-model="dmForm.notes" type="text" placeholder="Mô tả chi tiết..."
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Chứng từ nguồn</label>
                  <input v-model="dmForm.source_document_ref" type="text" placeholder="Mã hóa đơn, biên lai..."
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                </div>
              </div>

              <div class="flex justify-end gap-2">
                <button @click="showDmForm = false; jePreview = []" type="button"
                  class="border border-gray-300 text-gray-600 px-4 py-2 rounded-lg text-sm">
                  Hủy
                </button>
                <button @click="addDirectMaterial" type="button"
                  class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                  Xác nhận thêm
                </button>
              </div>
            </div>
          </div>

          <!-- Table -->
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Ngày</th>
                  <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Vật tư</th>
                  <th class="text-right px-4 py-2.5 font-semibold text-gray-600">Số lượng</th>
                  <th class="text-right px-4 py-2.5 font-semibold text-gray-600">Đơn giá</th>
                  <th class="text-right px-4 py-2.5 font-semibold text-gray-600">Thành tiền</th>
                  <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Loại xử lý</th>
                  <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Bút toán</th>
                  <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Trạng thái</th>
                  <th v-if="can('projects.manage')" class="px-4 py-2.5" />
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="m in directMaterials" :key="m.id"
                  :class="['hover:bg-gray-50', m.status === 'cancelled' ? 'opacity-50' : '']">
                  <td class="px-4 py-2 text-gray-600">{{ m.occurrence_date }}</td>
                  <td class="px-4 py-2">
                    <p class="text-gray-800">{{ m.product_name }}</p>
                    <p v-if="m.product_code" class="text-xs font-mono text-gray-400">{{ m.product_code }}</p>
                    <p v-if="m.notes" class="text-xs text-gray-400 italic mt-0.5">{{ m.notes }}</p>
                    <p v-if="m.source_ref" class="text-xs text-blue-500 mt-0.5">CT: {{ m.source_ref }}</p>
                  </td>
                  <td class="px-4 py-2 text-right text-gray-700">{{ m.quantity }}</td>
                  <td class="px-4 py-2 text-right text-gray-700">{{ formatVnd(m.unit_price) }}</td>
                  <td class="px-4 py-2 text-right font-medium" :class="m.status === 'cancelled' ? 'line-through text-gray-400' : 'text-gray-900'">
                    {{ formatVnd(m.total_amount) }}
                  </td>
                  <td class="px-4 py-2">
                    <span :class="['text-xs px-2 py-0.5 rounded-full font-medium',
                      m.handling_color === 'gray'   ? 'bg-gray-100 text-gray-600' :
                      m.handling_color === 'blue'   ? 'bg-blue-100 text-blue-700' :
                      'bg-purple-100 text-purple-700']">
                      {{ m.handling_label }}
                    </span>
                    <p v-if="m.pi_item_ref" class="text-xs text-blue-500 mt-0.5">{{ m.pi_item_ref }}</p>
                  </td>
                  <td class="px-4 py-2 text-xs font-mono text-gray-500">{{ m.journal_code ?? '—' }}</td>
                  <td class="px-4 py-2">
                    <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', m.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-600']">
                      {{ m.status === 'active' ? 'Đang hoạt động' : 'Đã hủy' }}
                    </span>
                    <p v-if="m.cancel_reason" class="text-xs text-gray-400 mt-0.5">{{ m.cancel_reason }}</p>
                  </td>
                  <td v-if="can('projects.manage')" class="px-4 py-2 text-right">
                    <button v-if="m.status === 'active'" @click="cancelDirectMaterial(m)"
                      class="text-xs text-red-500 hover:underline">
                      Hủy
                    </button>
                  </td>
                </tr>
                <tr v-if="!directMaterials.length">
                  <td :colspan="can('projects.manage') ? 9 : 8" class="px-4 py-10 text-center text-gray-400">
                    Chưa có vật tư phát sinh nào.
                    <br><span class="text-xs">Dùng để ghi nhận vật tư mua ngoài, không nhập kho, hoặc vật tư phát sinh trực tiếp cho dự án.</span>
                  </td>
                </tr>
              </tbody>
              <tfoot v-if="directMaterials.filter(m => m.status === 'active').length" class="bg-gray-50 border-t border-gray-200">
                <tr>
                  <td colspan="4" class="px-4 py-2.5 text-right font-semibold text-gray-700">Tổng vật tư phát sinh:</td>
                  <td class="px-4 py-2.5 text-right font-bold text-gray-900">{{ formatVnd(directMaterialTotal) }}</td>
                  <td :colspan="can('projects.manage') ? 4 : 3" />
                </tr>
              </tfoot>
            </table>
          </div>
        </div>

        <!-- Expenses tab -->
        <div v-if="activeTab === 'expenses'" class="p-5 space-y-4">
          <form v-if="can('projects.manage')" @submit.prevent="addExpense" class="grid grid-cols-1 sm:grid-cols-5 gap-3">
            <select v-model="expenseForm.category" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
              <option v-for="c in expenseCategories" :key="c.value" :value="c.value">{{ c.label }}</option>
            </select>
            <input v-model="expenseForm.description" type="text" placeholder="Mô tả chi phí"
              class="col-span-2 border border-gray-300 rounded-lg px-3 py-2 text-sm" required />
            <input v-model="expenseForm.amount" type="number" min="0" step="any" placeholder="Số tiền"
              class="border border-gray-300 rounded-lg px-3 py-2 text-sm" required />
            <div class="flex gap-2">
              <input v-model="expenseForm.expense_date" type="date"
                class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm" required />
              <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                Thêm
              </button>
            </div>
          </form>

          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border border-gray-200">
              <tr>
                <th class="text-left px-4 py-2 font-semibold text-gray-600">Danh mục</th>
                <th class="text-left px-4 py-2 font-semibold text-gray-600">Mô tả</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-600">Số tiền</th>
                <th class="text-left px-4 py-2 font-semibold text-gray-600">Ngày</th>
                <th class="text-left px-4 py-2 font-semibold text-gray-600">Người ghi</th>
                <th class="px-4 py-2" />
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="e in project.expenses" :key="e.id" class="hover:bg-gray-50">
                <td class="px-4 py-2">
                  <span class="bg-gray-100 text-gray-600 px-2 py-0.5 rounded text-xs">{{ e.category_label }}</span>
                </td>
                <td class="px-4 py-2 text-gray-800">{{ e.description }}</td>
                <td class="px-4 py-2 text-right font-medium text-gray-800">{{ formatVnd(e.amount) }}</td>
                <td class="px-4 py-2 text-gray-600">{{ e.expense_date }}</td>
                <td class="px-4 py-2 text-gray-500 text-xs">{{ e.creator ?? '—' }}</td>
                <td class="px-4 py-2 text-right">
                  <button v-if="can('projects.manage')" @click="removeExpense(e.id)" class="text-gray-400 hover:text-red-500">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </td>
              </tr>
              <tr v-if="!project.expenses.length">
                <td colspan="6" class="px-4 py-8 text-center text-gray-400">Chưa có chi phí nào</td>
              </tr>
              <tr v-if="project.expenses.length" class="bg-gray-50 font-semibold">
                <td colspan="2" class="px-4 py-2 text-right text-gray-700">Tổng chi phí phát sinh:</td>
                <td class="px-4 py-2 text-right text-gray-900">{{ formatVnd(project.total_expenses) }}</td>
                <td colspan="3" />
              </tr>
            </tbody>
          </table>
        </div>

        <!-- WIP tab -->
        <div v-if="activeTab === 'wip'" class="p-5 space-y-5">
          <div class="grid grid-cols-5 gap-3">
            <div v-for="row in wipSummary" :key="row.cost_type"
              class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-center">
              <p class="text-xs text-gray-500 font-medium">{{ row.label }}</p>
              <p class="text-base font-bold text-gray-900 mt-1">{{ formatVnd(row.total) }}</p>
            </div>
          </div>

          <div class="flex items-center justify-between bg-purple-50 border border-purple-200 rounded-xl px-5 py-4">
            <div>
              <p class="text-sm font-semibold text-purple-800">Tổng chi phí dở dang TK 154</p>
              <p class="text-2xl font-bold text-purple-900 mt-1">{{ formatVnd(wipTotal) }}</p>
              <p class="text-xs text-purple-600 mt-0.5">Khi dự án hoàn thành / nghiệm thu → kết chuyển sang Nợ 632 / Có 154</p>
            </div>
            <button v-if="can('accounting.manage') && wipTotal > 0"
              @click="recognizeCost"
              class="bg-purple-600 hover:bg-purple-700 text-white px-5 py-2.5 rounded-lg text-sm font-medium">
              Kết chuyển vào giá vốn (632)
            </button>
          </div>

          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Ngày</th>
                <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Loại CP</th>
                <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Mô tả</th>
                <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Nguồn</th>
                <th class="text-right px-4 py-2.5 font-semibold text-gray-600">Số tiền</th>
                <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Bút toán</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="e in wipEntries" :key="e.id" class="hover:bg-gray-50">
                <td class="px-4 py-2 text-gray-600">{{ e.entry_date }}</td>
                <td class="px-4 py-2">
                  <span class="bg-purple-100 text-purple-700 px-2 py-0.5 rounded text-xs font-medium">{{ e.label }}</span>
                </td>
                <td class="px-4 py-2 text-gray-800">{{ e.description }}</td>
                <td class="px-4 py-2 text-xs text-gray-500">{{ wipSourceLabel(e.source_type) }}</td>
                <td class="px-4 py-2 text-right font-medium text-gray-800">{{ formatVnd(e.amount) }}</td>
                <td class="px-4 py-2 text-xs text-gray-500 font-mono">{{ e.journal_code ?? '—' }}</td>
              </tr>
              <tr v-if="!wipEntries.length">
                <td colspan="6" class="px-4 py-10 text-center text-gray-400">
                  Chưa có phiếu xuất kho nào cho dự án này.
                  <br><span class="text-xs">Tạo phiếu xuất kho với mục đích "Xuất cho dự án" để tích lũy TK 154.</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Tab: Đơn mua hàng -->
        <div v-if="activeTab === 'purchase-orders'">
          <table v-if="purchaseOrders.length" class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã đơn</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Nhà cung cấp</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày đặt</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng tiền</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="po in purchaseOrders" :key="po.id" class="hover:bg-gray-50">
                <td class="px-5 py-3">
                  <Link :href="route('purchasing.purchase-orders.show', po.id)"
                    class="font-mono text-sm text-primary-600 hover:underline">
                    {{ po.code }}
                  </Link>
                </td>
                <td class="px-5 py-3 text-gray-700">{{ po.supplier }}</td>
                <td class="px-5 py-3 text-gray-600">{{ po.order_date }}</td>
                <td class="px-5 py-3">
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                    :class="{
                      'bg-gray-100 text-gray-600':     po.status === 'draft',
                      'bg-blue-100 text-blue-700':     po.status === 'sent',
                      'bg-yellow-100 text-yellow-700': po.status === 'partial_received',
                      'bg-green-100 text-green-700':   po.status === 'received',
                      'bg-red-100 text-red-600':       po.status === 'cancelled',
                    }">
                    {{ po.status_label }}
                  </span>
                </td>
                <td class="px-5 py-3 text-right font-medium text-gray-900">{{ formatVnd(po.total) }}</td>
              </tr>
            </tbody>
            <tfoot class="bg-gray-50 border-t border-gray-200">
              <tr>
                <td colspan="4" class="px-5 py-3 text-right font-semibold text-gray-700">Tổng cộng:</td>
                <td class="px-5 py-3 text-right font-bold text-gray-900">{{ formatVnd(purchaseOrderTotal) }}</td>
              </tr>
            </tfoot>
          </table>
          <div v-else class="p-10 text-center text-gray-400">
            <p class="text-sm">Chưa có đơn mua hàng liên kết với dự án này.</p>
            <Link :href="route('purchasing.purchase-orders.create')"
              class="mt-3 inline-block text-sm text-primary-600 hover:underline">
              Tạo đơn mua hàng mới →
            </Link>
          </div>
        </div>

        <!-- Tab: Hóa đơn mua hàng -->
        <div v-if="activeTab === 'purchase-invoices'">
          <table v-if="purchaseInvoices.length" class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã HĐ</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Nhà cung cấp</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Đơn mua</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày HĐ</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng tiền</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="pi in purchaseInvoices" :key="pi.id" class="hover:bg-gray-50">
                <td class="px-5 py-3">
                  <Link :href="route('purchasing.purchase-invoices.show', pi.id)"
                    class="font-mono text-sm text-primary-600 hover:underline">
                    {{ pi.code }}
                  </Link>
                </td>
                <td class="px-5 py-3 text-gray-700">{{ pi.supplier }}</td>
                <td class="px-5 py-3">
                  <Link v-if="pi.po_code" :href="route('purchasing.purchase-orders.show', pi.id)"
                    class="font-mono text-xs text-gray-500 hover:underline">
                    {{ pi.po_code }}
                  </Link>
                  <span v-else class="text-gray-400">—</span>
                </td>
                <td class="px-5 py-3 text-gray-600">{{ pi.invoice_date ?? '—' }}</td>
                <td class="px-5 py-3">
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                    :class="{
                      'bg-gray-100 text-gray-600':    pi.status === 'pending',
                      'bg-blue-100 text-blue-700':    pi.status === 'received' || pi.status === 'reviewing',
                      'bg-yellow-100 text-yellow-700': pi.status === 'valid' || pi.status === 'partial_paid',
                      'bg-green-100 text-green-700':  pi.status === 'paid',
                      'bg-red-100 text-red-600':      pi.status === 'cancelled',
                    }">
                    {{ pi.status_label }}
                  </span>
                </td>
                <td class="px-5 py-3 text-right font-medium text-gray-900">{{ formatVnd(pi.total) }}</td>
              </tr>
            </tbody>
            <tfoot class="bg-gray-50 border-t border-gray-200">
              <tr>
                <td colspan="5" class="px-5 py-3 text-right font-semibold text-gray-700">Tổng cộng:</td>
                <td class="px-5 py-3 text-right font-bold text-gray-900">{{ formatVnd(purchaseInvoiceTotal) }}</td>
              </tr>
            </tfoot>
          </table>
          <div v-else class="p-10 text-center text-gray-400">
            <p class="text-sm">Chưa có hóa đơn mua hàng liên kết với dự án này.</p>
          </div>
        </div>

        <!-- Info tab -->
        <div v-if="activeTab === 'info'" class="p-5">
          <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4 text-sm">
            <div>
              <dt class="text-gray-500">Mã dự án</dt>
              <dd class="font-mono text-gray-800 mt-0.5">{{ project.code }}</dd>
            </div>
            <div>
              <dt class="text-gray-500">Khách hàng</dt>
              <dd class="text-gray-800 mt-0.5">{{ project.customer.name }}</dd>
            </div>
            <div>
              <dt class="text-gray-500">Người phụ trách</dt>
              <dd class="text-gray-800 mt-0.5">{{ project.manager?.name ?? '—' }}</dd>
            </div>
            <div>
              <dt class="text-gray-500">Hợp đồng</dt>
              <dd class="text-gray-800 mt-0.5">{{ project.contract?.code ?? '—' }}</dd>
            </div>
            <div>
              <dt class="text-gray-500">Địa điểm thi công</dt>
              <dd class="text-gray-800 mt-0.5">{{ project.location ?? '—' }}</dd>
            </div>
            <div>
              <dt class="text-gray-500">Ngày tạo</dt>
              <dd class="text-gray-800 mt-0.5">{{ project.created_at }} — {{ project.creator }}</dd>
            </div>
            <div class="col-span-2">
              <dt class="text-gray-500">Ghi chú</dt>
              <dd class="text-gray-800 mt-0.5 whitespace-pre-line">{{ project.notes ?? '—' }}</dd>
            </div>
          </dl>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  project:             Object,
  allUsers:            Array,
  allProducts:         Array,
  expenseCategories:   Array,
  wipSummary:          { type: Array,  default: () => [] },
  wipEntries:          { type: Array,  default: () => [] },
  wipTotal:            { type: Number, default: 0 },
  purchaseOrders:      { type: Array,  default: () => [] },
  purchaseInvoices:    { type: Array,  default: () => [] },
  allEmployees:        { type: Array,  default: () => [] },
  contract_value:      { type: Number, default: null },
  actual_cost_from_pi: { type: Number, default: 0 },
  stockExitItems:      { type: Array,  default: () => [] },
  stockExitTotal:      { type: Number, default: 0 },
  directMaterials:     { type: Array,  default: () => [] },
  directMaterialTotal: { type: Number, default: 0 },
});

const { hasPermission } = usePermission();
const can = hasPermission;
const { formatVnd } = useCurrency();

const activeTab = ref('tasks');
const tabs = computed(() => [
  { id: 'tasks',            label: 'Công việc',           count: props.project.tasks.length },
  { id: 'members',          label: 'Nhân sự',              count: props.project.members.length },
  { id: 'stock-exits',      label: 'Vật tư đã xuất',       count: props.stockExitItems.filter(i => !i.is_cancelled).length },
  { id: 'direct-materials', label: 'Vật tư phát sinh',     count: props.directMaterials.filter(m => m.status === 'active').length },
  { id: 'expenses',         label: 'Chi phí PS',           count: props.project.expenses.length },
  { id: 'purchase-orders',    label: 'Đơn mua hàng',      count: props.purchaseOrders.length },
  { id: 'purchase-invoices',  label: 'Hóa đơn mua',       count: props.purchaseInvoices.length },
  { id: 'wip',              label: 'Chi phí dở dang (TK 154)' },
  { id: 'info',             label: 'Thông tin' },
]);

const doneTasks = computed(() => props.project.tasks.filter(t => t.status === 'done').length);
const purchaseOrderTotal   = computed(() => props.purchaseOrders.reduce((s, po) => s + (po.total ?? 0), 0));
const purchaseInvoiceTotal = computed(() => props.purchaseInvoices.reduce((s, pi) => s + (pi.total ?? 0), 0));

// WIP source label helper
const WIP_SOURCE_LABELS = {
  'App\\Models\\StockExit':           'Xuất kho',
  'App\\Models\\ProjectExpense':      'Chi phí PS',
  'App\\Models\\ProjectDirectMaterial': 'Vật tư phát sinh',
};
const wipSourceLabel = (type) => WIP_SOURCE_LABELS[type] ?? type?.split('\\').pop() ?? '—';

// Task form
const taskForm = reactive({ title: '', assigned_to: null, priority: 'medium', due_date: '' });
const addTask = () => {
  router.post(route('projects.projects.tasks.store', props.project.id), taskForm, {
    preserveScroll: true,
    onSuccess: () => { taskForm.title = ''; taskForm.assigned_to = null; taskForm.due_date = ''; },
  });
};
const deleteTask = (taskId) => {
  if (!confirm('Xóa công việc này?')) return;
  router.delete(route('projects.projects.tasks.destroy', [props.project.id, taskId]), { preserveScroll: true });
};
const cycleStatus = (task) => {
  const cycle = { todo: 'in_progress', in_progress: 'done', done: 'todo', cancelled: 'todo' };
  router.patch(route('projects.projects.tasks.status', [props.project.id, task.id]),
    { status: cycle[task.status] }, { preserveScroll: true });
};

// Member form
const memberForm = reactive({ employee_id: '', role: '' });
const addMember = () => {
  router.post(route('projects.projects.members.store', props.project.id), memberForm, {
    preserveScroll: true,
    onSuccess: () => { memberForm.employee_id = ''; memberForm.role = ''; },
  });
};
const removeMember = (memberId) => {
  if (!confirm('Xóa thành viên này?')) return;
  router.delete(route('projects.projects.members.destroy', [props.project.id, memberId]), { preserveScroll: true });
};

// Expense form
const expenseForm = reactive({ category: 'other', description: '', amount: '', expense_date: '' });
const addExpense = () => {
  router.post(route('projects.projects.expenses.store', props.project.id), expenseForm, {
    preserveScroll: true,
    onSuccess: () => { expenseForm.description = ''; expenseForm.amount = ''; expenseForm.expense_date = ''; },
  });
};
const removeExpense = (expenseId) => {
  if (!confirm('Xóa chi phí này?')) return;
  router.delete(route('projects.projects.expenses.destroy', [props.project.id, expenseId]), { preserveScroll: true });
};

// Direct material form
const showDmForm = ref(false);
const jePreview = ref([]);
const handlingTypes = [
  { value: 'tracking_only', label: 'Chỉ theo dõi', description: 'Không tạo bút toán, không cập nhật TK 154.' },
  { value: 'invoice_link',  label: 'Liên kết HĐ mua', description: 'Link vào hóa đơn đã có, không tạo thêm bút toán.' },
  { value: 'journal_entry', label: 'Ghi nhận TK 154', description: 'Tạo bút toán Nợ 154 / Có TK chỉ định.' },
];
const dmForm = reactive({
  product_id: null,
  product_name: '',
  quantity: 1,
  unit_price: 0,
  occurrence_date: new Date().toISOString().slice(0, 10),
  handling_type: 'tracking_only',
  credit_account_code: '3311',
  notes: '',
  source_document_ref: '',
});

const previewJe = async () => {
  if (!dmForm.quantity || !dmForm.unit_price || !dmForm.credit_account_code) return;
  try {
    const res = await fetch(route('projects.projects.direct-materials.preview', props.project.id), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
      body: JSON.stringify({ quantity: dmForm.quantity, unit_price: dmForm.unit_price, credit_account_code: dmForm.credit_account_code }),
    });
    const data = await res.json();
    jePreview.value = data.lines ?? [];
  } catch {
    jePreview.value = [];
  }
};

const addDirectMaterial = () => {
  router.post(route('projects.projects.direct-materials.store', props.project.id), { ...dmForm }, {
    preserveScroll: true,
    onSuccess: () => {
      showDmForm.value = false;
      jePreview.value = [];
      Object.assign(dmForm, { product_id: null, product_name: '', quantity: 1, unit_price: 0, notes: '', source_document_ref: '' });
    },
  });
};

const cancelDirectMaterial = (m) => {
  const reason = prompt(`Lý do hủy vật tư phát sinh "${m.product_name}"?`);
  if (!reason) return;
  router.delete(route('projects.projects.direct-materials.destroy', [props.project.id, m.id]), {
    data: { cancel_reason: reason },
    preserveScroll: true,
  });
};

// Project transition
const doTransition = (status) => {
  router.post(route('projects.projects.transition', props.project.id), { status }, { preserveScroll: true });
};

// WIP — kết chuyển giá thành
const recognizeCost = () => {
  if (!confirm(`Kết chuyển toàn bộ chi phí dở dang TK 154 của dự án ${props.project.code} vào giá vốn TK 632?\n\nHành động này tạo bút toán: Nợ 632 / Có 154.`)) return;
  router.post(route('projects.projects.recognize-cost', props.project.id), {}, { preserveScroll: true });
};

// Delete project
const confirmDelete = () => {
  if (!confirm(`Xóa vĩnh viễn dự án ${props.project.code} — ${props.project.name}?\n\nTất cả tasks, thành viên, vật tư, chi phí sẽ bị xóa.\nHành động này không thể hoàn tác.`)) return;
  router.delete(route('projects.projects.destroy', props.project.id));
};

// Style helpers
const taskStatusClass = (status) => {
  return {
    todo:        'border-gray-300',
    in_progress: 'border-yellow-400 bg-yellow-400',
    done:        'border-green-500 bg-green-500',
    cancelled:   'border-red-400 bg-red-400',
  }[status] ?? 'border-gray-300';
};
const priorityClass = (p) => {
  return { high: 'bg-red-100 text-red-700', medium: 'bg-yellow-100 text-yellow-700', low: 'bg-gray-100 text-gray-600' }[p] ?? '';
};
</script>
