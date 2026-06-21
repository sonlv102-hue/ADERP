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

          <!-- Cảnh báo trùng số hóa đơn -->
          <div v-if="$page.props.flash?.warning_duplicate"
            class="flex items-start gap-3 bg-amber-50 border border-amber-300 rounded-lg px-4 py-3">
            <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
            </svg>
            <div class="flex-1 text-sm text-amber-800">
              {{ $page.props.flash.warning_duplicate }}
            </div>
            <button @click="submitExpenseForce"
              class="text-xs bg-amber-600 hover:bg-amber-700 text-white px-3 py-1.5 rounded font-medium whitespace-nowrap">
              Vẫn ghi nhận
            </button>
          </div>

          <form v-if="can('projects.manage')" @submit.prevent="addExpense"
            class="bg-gray-50 border border-gray-200 rounded-xl p-4 space-y-3">
            <h3 class="text-sm font-semibold text-gray-700">Thêm chi phí phát sinh</h3>

            <!-- Row 1: thông tin bắt buộc -->
            <div class="grid grid-cols-1 sm:grid-cols-5 gap-3">
              <select v-model="expenseForm.category" class="border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white">
                <option v-for="c in expenseCategories" :key="c.value" :value="c.value">{{ c.label }}</option>
              </select>
              <input v-model="expenseForm.description" type="text" placeholder="Mô tả chi phí"
                class="col-span-2 border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white" required />
              <input v-model="expenseForm.amount" type="number" min="0" step="any" placeholder="Số tiền (trước VAT)"
                class="border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white" required />
              <input v-model="expenseForm.expense_date" type="date"
                class="border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white" required />
            </div>

            <!-- Row 2: TK Nợ / TK Có -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-xs text-gray-500 mb-0.5">TK Nợ <span class="text-gray-400">(mặc định: 154)</span></label>
                <RemoteSearchSelect
                  v-model="expenseForm.debit_account"
                  :display-text="expenseForm.debit_account_name"
                  :search-url="route('search.account-codes') + '?detail_only=true'"
                  placeholder="Tìm TK Nợ (6271, 6272, 6237...)"
                  @change="(opt) => { expenseForm.debit_account_name = opt ? opt.code + ' - ' + opt.label : '' }"
                />
                <p v-if="expenseForm.debit_account && /^15[26]/.test(expenseForm.debit_account)"
                   class="text-xs text-red-500 mt-0.5">
                  TK {{ expenseForm.debit_account }} là vật tư/hàng hóa — dùng phiếu xuất kho.
                </p>
                <p v-else-if="expenseForm.debit_account && expenseForm.debit_account.startsWith('154')"
                   class="text-xs text-blue-500 mt-0.5">
                  Hạch toán thẳng vào WIP 154 (bỏ qua bước kết chuyển).
                </p>
                <p v-else-if="expenseForm.debit_account" class="text-xs text-green-600 mt-0.5">
                  TT133: cần kết chuyển sang 154 sau khi hoàn thành hạng mục.
                </p>
              </div>
              <div>
                <label class="block text-xs text-gray-500 mb-0.5">TK Có</label>
                <RemoteSearchSelect
                  v-model="expenseForm.credit_account"
                  :display-text="expenseForm.credit_account_name"
                  :search-url="route('search.account-codes') + '?detail_only=true'"
                  placeholder="Tìm TK Có (3311, 1111, 1121, 3341, 141...)"
                  @change="(opt) => { expenseForm.credit_account_name = opt ? opt.code + ' - ' + opt.label : '' }"
                />
                <p v-if="expenseForm.credit_account && !COMMON_CREDIT_ACCOUNTS.includes(expenseForm.credit_account)"
                   class="text-xs text-amber-600 mt-0.5">TK Có không thông thường — kiểm tra lại</p>
              </div>
            </div>

            <!-- Row 3: trường phụ theo TK Có -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
              <!-- NCC — bắt buộc khi TK Có = 3311 -->
              <div v-if="expenseShowSupplier" class="lg:col-span-2">
                <label class="block text-xs text-gray-500 mb-0.5">
                  Nhà cung cấp
                  <span v-if="expenseForm.credit_account === '3311'" class="text-red-500">*</span>
                </label>
                <RemoteSearchSelect
                  v-model="expenseForm.supplier_id"
                  :display-text="expenseForm.supplier_name"
                  :search-url="route('search.suppliers')"
                  placeholder="Tìm NCC..."
                  :has-error="expenseForm.credit_account === '3311' && !expenseForm.supplier_id"
                  @change="(opt) => { expenseForm.supplier_name = opt ? (opt.code ? opt.code + ' - ' + opt.label : opt.label) : '' }"
                />
                <p v-if="expenseForm.credit_account === '3311' && !expenseForm.supplier_id"
                   class="text-xs text-red-500 mt-0.5">Bắt buộc chọn NCC khi TK Có là 3311</p>
              </div>

              <!-- Quỹ tiền mặt — bắt buộc khi TK Có = 1111 -->
              <div v-if="expenseForm.credit_account === '1111'">
                <label class="block text-xs text-gray-500 mb-0.5">Quỹ tiền mặt <span class="text-red-500">*</span></label>
                <select v-model="expenseForm.fund_id"
                  :class="['border rounded-lg px-3 py-2 text-sm w-full bg-white', !expenseForm.fund_id ? 'border-red-400' : 'border-gray-300']">
                  <option value="">-- Chọn quỹ --</option>
                  <option v-for="f in funds.filter(f => f.type === 'cash')" :key="f.id" :value="f.id">
                    {{ f.name }} ({{ f.account_code }})
                  </option>
                </select>
                <p v-if="!expenseForm.fund_id" class="text-xs text-red-500 mt-0.5">Bắt buộc chọn quỹ khi TK Có là 1111</p>
              </div>

              <!-- TK ngân hàng — bắt buộc khi TK Có = 1121 -->
              <div v-if="expenseForm.credit_account === '1121'">
                <label class="block text-xs text-gray-500 mb-0.5">Tài khoản ngân hàng <span class="text-red-500">*</span></label>
                <select v-model="expenseForm.bank_account_id"
                  :class="['border rounded-lg px-3 py-2 text-sm w-full bg-white', !expenseForm.bank_account_id ? 'border-red-400' : 'border-gray-300']">
                  <option value="">-- Chọn TK ngân hàng --</option>
                  <option v-for="b in bankAccounts" :key="b.id" :value="b.id">
                    {{ b.bank_name }} - {{ b.account_number }} ({{ b.account_code }})
                  </option>
                </select>
                <p v-if="!expenseForm.bank_account_id" class="text-xs text-red-500 mt-0.5">Bắt buộc chọn TK ngân hàng khi TK Có là 1121</p>
              </div>

              <!-- Nhân viên — khi TK Có = 3341 hoặc 141 -->
              <div v-if="expenseShowEmployee" class="lg:col-span-2">
                <label class="block text-xs text-gray-500 mb-0.5">
                  Nhân viên
                  <span v-if="expenseForm.credit_account === '141'" class="text-red-500">* (tạm ứng)</span>
                </label>
                <select v-model="expenseForm.employee_id" class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-full bg-white">
                  <option value="">-- Chọn nhân viên --</option>
                  <option v-for="emp in allEmployees" :key="emp.id" :value="emp.id">
                    {{ emp.code }} - {{ emp.name }}
                  </option>
                </select>
                <p v-if="expenseForm.credit_account === '141' && !expenseForm.employee_id"
                   class="text-xs text-red-500 mt-0.5">Bắt buộc chọn nhân viên tạm ứng</p>
              </div>
            </div>

            <!-- Row 4: thông tin bổ sung + nút submit -->
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
              <input v-model="expenseForm.invoice_number" type="text" placeholder="Số hóa đơn (tuỳ chọn)"
                class="border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white" />
              <div class="flex gap-2">
                <div class="flex-1">
                  <label class="block text-xs text-gray-500 mb-0.5">VAT (số tiền)</label>
                  <input v-model="expenseForm.vat_amount" type="number" min="0" placeholder="0"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white" />
                </div>
                <div class="flex-1">
                  <label class="block text-xs text-gray-500 mb-0.5">VAT %</label>
                  <input v-model="expenseForm.vat_rate" type="number" min="0" max="100" placeholder="10"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white" />
                </div>
              </div>
              <div class="text-xs text-gray-500 space-y-0.5 self-end pb-2">
                <div v-if="expenseForm.debit_account" class="font-mono text-blue-700">
                  Nợ {{ expenseForm.debit_account || '154' }}
                  {{ expenseForm.amount ? formatVnd(Number(expenseForm.amount)) : '' }}
                </div>
                <div v-if="expenseForm.vat_amount > 0" class="font-mono text-blue-700">
                  Nợ 1331 {{ formatVnd(Number(expenseForm.vat_amount)) }}
                </div>
                <div v-if="expenseForm.credit_account" class="font-mono text-red-600">
                  Có {{ expenseForm.credit_account }}
                  {{ expenseForm.amount ? formatVnd(Number(expenseForm.amount) + Number(expenseForm.vat_amount || 0)) : '' }}
                </div>
              </div>
              <div class="flex gap-2 justify-end">
                <button type="button" @click="resetExpenseForm"
                  class="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-600 hover:bg-gray-100">
                  Xóa trắng
                </button>
                <button type="submit"
                  :disabled="expenseSubmitBlocked"
                  class="bg-primary-600 hover:bg-primary-700 disabled:bg-gray-300 text-white px-5 py-2 rounded-lg text-sm font-medium whitespace-nowrap">
                  Thêm chi phí
                </button>
              </div>
            </div>

            <p class="text-xs text-gray-400">
              TK Nợ 154 → vào WIP TK 154 ngay. TK Nợ khác (6271, 6272, 6278...) → cần kết chuyển sang 154 thủ công.
              Không được dùng TK 152/156 (vật tư phải đi qua phiếu xuất kho).
              VAT khấu trừ → thêm dòng Nợ 1331 tự động (chỉ khi có hóa đơn VAT hợp lệ).
            </p>
          </form>

          <!-- Kết chuyển 154 — hiển thị khi có expenses chờ KC (không cần tick trước) -->
          <div v-if="can('projects.manage') && selectableExpenses.length > 0"
            class="flex items-center justify-between bg-purple-50 border border-purple-200 rounded-lg px-4 py-2.5">
            <span class="text-sm text-purple-700">
              <span class="font-medium">{{ selectableExpenses.length }} khoản chi phí</span>
              chờ kết chuyển sang TK 154
              <span v-if="selectedExpenseIds.length > 0" class="ml-2 text-purple-500">
                (đã chọn {{ selectedExpenseIds.length }} · {{ formatVnd(selectedExpensesTotal) }})
              </span>
            </span>
            <div class="flex items-center gap-2">
              <button v-if="selectedExpenseIds.length > 0" @click="selectedExpenseIds = []"
                class="text-xs text-gray-500 hover:text-gray-700 px-2 py-1 border border-gray-300 rounded">
                Bỏ chọn
              </button>
              <button @click="selectAllAndTransfer"
                class="text-xs bg-purple-600 hover:bg-purple-700 text-white px-3 py-1.5 rounded font-medium">
                {{ selectedExpenseIds.length > 0 ? `Kết chuyển ${selectedExpenseIds.length} mục →154` : 'Chọn tất cả và kết chuyển →154' }}
              </button>
            </div>
          </div>

          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-gray-50 border border-gray-200">
                <tr>
                  <th v-if="can('projects.manage')" class="px-3 py-2 w-8">
                    <input type="checkbox" :checked="allSelectableChecked"
                      :indeterminate="selectedExpenseIds.length > 0 && !allSelectableChecked"
                      @change="toggleSelectAll"
                      class="rounded border-gray-300 text-purple-600 cursor-pointer" />
                  </th>
                  <th class="text-left px-3 py-2 font-semibold text-gray-600">Danh mục</th>
                  <th class="text-left px-3 py-2 font-semibold text-gray-600">Mô tả</th>
                  <th class="text-left px-3 py-2 font-semibold text-gray-600 hidden md:table-cell">TK Nợ / Có</th>
                  <th class="text-right px-3 py-2 font-semibold text-gray-600">Số tiền</th>
                  <th class="text-right px-3 py-2 font-semibold text-gray-600 hidden lg:table-cell">Đã KC 154</th>
                  <th class="text-right px-3 py-2 font-semibold text-gray-600 hidden lg:table-cell">Còn lại</th>
                  <th class="text-left px-3 py-2 font-semibold text-gray-600">Bút toán</th>
                  <th class="text-left px-3 py-2 font-semibold text-gray-600">Trạng thái KC</th>
                  <th class="px-3 py-2" />
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <template v-for="e in project.expenses" :key="e.id">
                  <!-- Dòng chi phí chính -->
                  <tr :class="['hover:bg-gray-50', selectedExpenseIds.includes(e.id) ? 'bg-purple-50' : '']">
                    <td v-if="can('projects.manage')" class="px-3 py-2.5">
                      <input v-if="e.can_transfer" type="checkbox"
                        :checked="selectedExpenseIds.includes(e.id)"
                        @change="toggleExpenseSelection(e.id)"
                        class="rounded border-gray-300 text-purple-600 cursor-pointer" />
                      <span v-else class="block w-4 h-4" />
                    </td>
                    <td class="px-3 py-2.5">
                      <span class="bg-gray-100 text-gray-600 px-2 py-0.5 rounded text-xs">{{ e.category_label }}</span>
                    </td>
                    <td class="px-3 py-2.5 text-gray-800 max-w-[200px]">
                      <div class="truncate">{{ e.description }}</div>
                      <div v-if="e.invoice_number" class="text-xs text-gray-400">HĐ: {{ e.invoice_number }}</div>
                      <div v-if="e.supplier_name" class="text-xs text-gray-400">NCC: {{ e.supplier_name }}</div>
                      <div v-if="e.employee_name" class="text-xs text-gray-400">NV: {{ e.employee_name }}</div>
                      <div v-if="e.fund_name" class="text-xs text-gray-400">Quỹ: {{ e.fund_name }}</div>
                      <div v-if="e.bank_account_name" class="text-xs text-gray-400">NH: {{ e.bank_account_name }}</div>
                      <div v-if="e.expense_date" class="text-xs text-gray-400">{{ e.expense_date }}</div>
                      <span v-if="e.status === 'cancelled'"
                        class="text-xs bg-red-100 text-red-600 px-1 rounded">Đã hủy</span>
                    </td>
                    <td class="px-3 py-2.5 hidden md:table-cell">
                      <div v-if="e.debit_account" class="font-mono text-xs text-blue-700">Nợ {{ e.debit_account }}</div>
                      <div v-if="e.credit_account" class="font-mono text-xs text-red-600">Có {{ e.credit_account }}</div>
                      <span v-if="!e.debit_account && !e.credit_account" class="text-xs text-gray-400">—</span>
                    </td>
                    <td class="px-3 py-2.5 text-right font-medium text-gray-800 whitespace-nowrap">
                      {{ formatVnd(e.amount) }}
                      <div v-if="e.vat_amount > 0" class="text-xs text-gray-400">VAT: {{ formatVnd(e.vat_amount) }}</div>
                    </td>
                    <td class="px-3 py-2.5 text-right hidden lg:table-cell">
                      <span v-if="e.transfer_status === 'direct_154' || e.transfer_status === 'legacy'" class="text-xs text-gray-400">—</span>
                      <span v-else class="font-medium" :class="e.transferred_amount > 0 ? 'text-green-700' : 'text-gray-400'">
                        {{ formatVnd(e.transferred_amount) }}
                      </span>
                    </td>
                    <td class="px-3 py-2.5 text-right hidden lg:table-cell">
                      <span v-if="e.transfer_status === 'direct_154' || e.transfer_status === 'legacy'" class="text-xs text-gray-400">—</span>
                      <span v-else class="font-medium" :class="e.remaining_amount > 0 ? 'text-amber-600' : 'text-green-700'">
                        {{ formatVnd(e.remaining_amount) }}
                      </span>
                    </td>
                    <td class="px-3 py-2.5">
                      <Link v-if="e.je_id"
                        :href="route('accounting.journal-entries.show', e.je_id)"
                        class="text-xs text-blue-600 hover:underline font-mono">
                        {{ e.je_code }}
                      </Link>
                      <span v-else class="text-xs text-amber-500">Chưa có BT</span>
                    </td>
                    <td class="px-3 py-2.5">
                      <span :class="['text-xs px-1.5 py-0.5 rounded font-medium', transferStatusClass(e.transfer_status)]">
                        {{ transferStatusLabel(e.transfer_status) }}
                      </span>
                    </td>
                    <td class="px-3 py-2.5 text-right whitespace-nowrap">
                      <div class="flex items-center justify-end gap-1">
                        <button v-if="can('projects.manage') && e.can_transfer"
                          @click="openTransferModal(e)"
                          class="text-xs bg-purple-600 hover:bg-purple-700 text-white px-2 py-1 rounded font-medium whitespace-nowrap">
                          →154
                        </button>
                        <button v-if="can('projects.manage')" @click="removeExpense(e.id)" class="text-gray-400 hover:text-red-500 ml-1">
                          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                          </svg>
                        </button>
                      </div>
                    </td>
                  </tr>
                  <!-- Dòng kết chuyển (sub-rows) -->
                  <tr v-for="t in e.transfers" :key="'t' + t.id"
                    class="bg-purple-50 border-l-2 border-purple-300 text-xs">
                    <td v-if="can('projects.manage')" class="px-3 py-1.5" />
                    <td class="pl-6 pr-3 py-1.5 text-purple-600 font-medium" colspan="2">
                      KC →154 ngày {{ t.transfer_date }}
                    </td>
                    <td class="px-3 py-1.5 hidden md:table-cell">
                      <span class="font-mono text-blue-700">Nợ {{ t.debit_account }}</span>
                      <span class="font-mono text-red-600 ml-2">Có {{ t.credit_account }}</span>
                    </td>
                    <td class="px-3 py-1.5 text-right font-semibold text-purple-700">{{ formatVnd(t.amount) }}</td>
                    <td class="px-3 py-1.5 hidden lg:table-cell" />
                    <td class="px-3 py-1.5 hidden lg:table-cell" />
                    <td class="px-3 py-1.5">
                      <Link v-if="t.je_id" :href="route('accounting.journal-entries.show', t.je_id)"
                        class="font-mono text-blue-600 hover:underline">{{ t.je_code }}</Link>
                      <span v-else class="text-gray-400">—</span>
                    </td>
                    <td class="px-3 py-1.5">
                      <span class="bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded">Đã kết chuyển</span>
                    </td>
                    <td class="px-3 py-1.5 text-right">
                      <button v-if="can('projects.manage')"
                        @click="openCancelTransfer(e, t)"
                        class="text-red-500 hover:underline">
                        Hủy
                      </button>
                    </td>
                  </tr>
                </template>
                <tr v-if="!project.expenses.length">
                  <td :colspan="can('projects.manage') ? 10 : 9" class="px-4 py-8 text-center text-gray-400">Chưa có chi phí nào</td>
                </tr>
                <tr v-if="project.expenses.length" class="bg-gray-50 font-semibold">
                  <td v-if="can('projects.manage')" />
                  <td colspan="3" class="px-3 py-2 text-right text-gray-700">Tổng chi phí phát sinh:</td>
                  <td class="px-3 py-2 text-right text-gray-900">{{ formatVnd(project.total_expenses) }}</td>
                  <td colspan="5" />
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Modal kết chuyển sang TK 154 -->
        <div v-if="transferModal.open" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 p-4"
          @click.self="transferModal.open = false">
          <div class="bg-white rounded-xl shadow-xl w-full max-w-md flex-shrink-0 space-y-4 p-6">
            <h3 class="font-semibold text-gray-900">Kết chuyển chi phí sang TK 154</h3>

            <!-- Thông tin chi phí gốc -->
            <div class="bg-gray-50 rounded-lg p-3 text-sm space-y-1.5">
              <div class="flex justify-between">
                <span class="text-gray-500">Chi phí:</span>
                <span class="font-medium text-gray-800 text-right max-w-[200px] truncate">{{ transferModal.expense?.description }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-gray-500">TK Nợ gốc:</span>
                <span class="font-mono text-blue-700">{{ transferModal.expense?.debit_account ?? '(theo danh mục)' }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-gray-500">Số tiền gốc:</span>
                <span class="font-semibold text-gray-900">{{ formatVnd(transferModal.expense?.amount) }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-gray-500">Đã kết chuyển:</span>
                <span :class="transferModal.expense?.transferred_amount > 0 ? 'text-green-700 font-medium' : 'text-gray-400'">
                  {{ formatVnd(transferModal.expense?.transferred_amount) }}
                </span>
              </div>
              <div class="flex justify-between border-t border-gray-200 pt-1.5">
                <span class="text-gray-500 font-medium">Còn lại có thể KC:</span>
                <span class="font-bold text-amber-600">{{ formatVnd(transferModal.expense?.remaining_amount) }}</span>
              </div>
            </div>

            <!-- Bút toán sẽ tạo -->
            <div class="bg-purple-50 border border-purple-200 rounded-lg p-3 text-xs">
              <p class="font-semibold text-purple-700 mb-1">Bút toán sẽ tạo:</p>
              <div class="flex justify-between">
                <span class="font-mono text-blue-700">Nợ {{ transferModal.form.debit_account || '154' }}</span>
                <span class="text-gray-600">{{ formatVnd(Number(transferModal.form.amount) || 0) }}</span>
              </div>
              <div class="flex justify-between mt-0.5">
                <span class="font-mono text-red-600">Có {{ transferModal.expense?.debit_account ?? '(TK Nợ gốc)' }}</span>
                <span class="text-gray-600">{{ formatVnd(Number(transferModal.form.amount) || 0) }}</span>
              </div>
            </div>

            <!-- Form -->
            <div class="space-y-3">
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">TK Nợ (kết chuyển) <span class="text-red-500">*</span></label>
                  <input v-model="transferModal.form.debit_account" type="text" placeholder="154"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono" />
                  <p class="text-xs text-gray-400 mt-0.5">Mặc định: 154</p>
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Ngày kết chuyển <span class="text-red-500">*</span></label>
                  <input v-model="transferModal.form.transfer_date" type="date"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" required />
                </div>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">
                  Số tiền kết chuyển <span class="text-red-500">*</span>
                  <span class="text-gray-400 font-normal ml-1">(tối đa {{ formatVnd(transferModal.expense?.remaining_amount) }})</span>
                </label>
                <input v-model="transferModal.form.amount" type="number" min="1" step="1"
                  :max="transferModal.expense?.remaining_amount"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" required />
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Diễn giải</label>
                <input v-model="transferModal.form.description" type="text"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
                  :placeholder="`Kết chuyển chi phí: ${transferModal.expense?.description ?? ''}`" />
              </div>
            </div>

            <p v-if="transferModal.error" class="text-xs text-red-600">{{ transferModal.error }}</p>

            <div class="flex gap-3 pt-1">
              <button @click="submitTransfer"
                :disabled="!transferModal.form.amount || !transferModal.form.transfer_date || transferModal.submitting"
                class="flex-1 bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white px-4 py-2 rounded-lg text-sm font-medium">
                {{ transferModal.submitting ? 'Đang xử lý...' : 'Kết chuyển sang TK 154' }}
              </button>
              <button @click="transferModal.open = false"
                class="flex-1 border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm">
                Hủy bỏ
              </button>
            </div>
          </div>
        </div>

        <!-- Modal hủy kết chuyển -->
        <div v-if="cancelTransferModal.open" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 p-4"
          @click.self="cancelTransferModal.open = false">
          <div class="bg-white rounded-xl shadow-xl w-full max-w-sm flex-shrink-0 p-6 space-y-4">
            <h3 class="font-semibold text-gray-900">Hủy kết chuyển TK 154</h3>
            <div class="bg-gray-50 rounded-lg p-3 text-sm space-y-1">
              <div class="flex justify-between">
                <span class="text-gray-500">Ngày KC:</span>
                <span>{{ cancelTransferModal.transfer?.transfer_date }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-gray-500">Số tiền:</span>
                <span class="font-semibold">{{ formatVnd(cancelTransferModal.transfer?.amount) }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-gray-500">Bút toán:</span>
                <span class="font-mono text-blue-600">{{ cancelTransferModal.transfer?.je_code ?? '—' }}</span>
              </div>
            </div>
            <p class="text-xs text-gray-500">Tạo bút toán đảo: Nợ {{ cancelTransferModal.transfer?.credit_account }} / Có {{ cancelTransferModal.transfer?.debit_account }}. WIP entry sẽ chuyển sang hủy.</p>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Lý do hủy <span class="text-red-500">*</span></label>
              <textarea v-model="cancelTransferModal.reason" rows="2" required
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm resize-none"
                placeholder="Nhập lý do bắt buộc..." />
            </div>
            <p v-if="cancelTransferModal.error" class="text-xs text-red-600">{{ cancelTransferModal.error }}</p>
            <div class="flex gap-3">
              <button @click="submitCancelTransfer"
                :disabled="!cancelTransferModal.reason.trim() || cancelTransferModal.submitting"
                class="flex-1 bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white px-4 py-2 rounded-lg text-sm font-medium">
                {{ cancelTransferModal.submitting ? 'Đang xử lý...' : 'Xác nhận hủy' }}
              </button>
              <button @click="cancelTransferModal.open = false"
                class="flex-1 border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm">
                Đóng
              </button>
            </div>
          </div>
        </div>

        <!-- Modal kết chuyển nhiều chi phí (batch) -->
        <div v-if="batchTransferModal.open" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 p-4"
          @click.self="batchTransferModal.open = false">
          <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl flex flex-col max-h-[90vh]">
            <div class="p-5 border-b border-gray-200 flex-shrink-0">
              <h3 class="font-semibold text-gray-900">Kết chuyển {{ batchTransferModal.rows.length }} chi phí sang TK 154</h3>
            </div>

            <div class="overflow-y-auto flex-1 p-5 space-y-4">
              <!-- Tổng / ngày / diễn giải -->
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Ngày kết chuyển <span class="text-red-500">*</span></label>
                  <input v-model="batchTransferModal.transfer_date" type="date"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" required />
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Diễn giải</label>
                  <input v-model="batchTransferModal.description" type="text"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
                    :placeholder="`Kết chuyển chi phí dự án ${project.code} sang TK 154`" />
                </div>
              </div>

              <!-- Danh sách chi phí được chọn -->
              <div class="border border-gray-200 rounded-lg overflow-hidden">
                <table class="min-w-full text-sm">
                  <thead class="bg-gray-50">
                    <tr>
                      <th class="text-left px-3 py-2 font-medium text-gray-600">Mô tả</th>
                      <th class="text-left px-3 py-2 font-medium text-gray-600 hidden sm:table-cell">TK Có</th>
                      <th class="text-right px-3 py-2 font-medium text-gray-600">Còn lại</th>
                      <th class="text-right px-3 py-2 font-medium text-gray-600">Kết chuyển</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-100">
                    <tr v-for="row in batchTransferModal.rows" :key="row.expense_id"
                      :class="row.error ? 'bg-red-50' : ''">
                      <td class="px-3 py-2 text-gray-800 max-w-[200px]">
                        <div class="truncate text-xs">{{ row.description }}</div>
                        <div v-if="row.error" class="text-xs text-red-500 mt-0.5">{{ row.error }}</div>
                      </td>
                      <td class="px-3 py-2 hidden sm:table-cell">
                        <span v-if="row.credit_account" class="font-mono text-xs text-red-600">{{ row.credit_account }}</span>
                        <span v-else class="text-xs text-gray-400">—</span>
                      </td>
                      <td class="px-3 py-2 text-right text-xs text-gray-600">
                        {{ row.remaining != null ? formatVnd(row.remaining) : '—' }}
                      </td>
                      <td class="px-3 py-2 text-right">
                        <input v-if="!row.error && row.remaining > 0"
                          v-model.number="batchTransferModal.amounts[row.expense_id]"
                          type="number" min="1" :max="row.remaining" step="1"
                          class="w-28 border border-gray-300 rounded px-2 py-1 text-xs text-right font-mono" />
                        <span v-else class="text-xs text-gray-400">—</span>
                      </td>
                    </tr>
                  </tbody>
                  <tfoot class="bg-gray-50 border-t border-gray-200">
                    <tr>
                      <td colspan="3" class="px-3 py-2 text-right text-xs font-semibold text-gray-700">Tổng kết chuyển:</td>
                      <td class="px-3 py-2 text-right text-sm font-bold text-purple-700">
                        {{ formatVnd(batchComputedTotal) }}
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>

              <!-- Preview bút toán -->
              <div v-if="batchComputedTotal > 0" class="bg-purple-50 border border-purple-200 rounded-lg p-3 text-xs">
                <p class="font-semibold text-purple-700 mb-1.5">Bút toán sẽ tạo (mỗi chi phí một bút toán riêng):</p>
                <div class="space-y-0.5">
                  <div class="flex justify-between">
                    <span class="font-mono text-blue-700">Nợ 154 (tổng)</span>
                    <span class="font-medium">{{ formatVnd(batchComputedTotal) }}</span>
                  </div>
                  <div v-for="(amt, acct) in batchCreditGroups" :key="acct" class="flex justify-between">
                    <span class="font-mono text-red-600 pl-2">Có {{ acct }}</span>
                    <span>{{ formatVnd(amt) }}</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="p-5 border-t border-gray-200 flex-shrink-0">
              <p v-if="batchTransferModal.error" class="text-xs text-red-600 mb-2">{{ batchTransferModal.error }}</p>
              <div class="flex gap-3">
                <button @click="submitBatchTransfer"
                  :disabled="batchComputedTotal <= 0 || !batchTransferModal.transfer_date || batchTransferModal.submitting"
                  class="flex-1 bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white px-4 py-2 rounded-lg text-sm font-medium">
                  {{ batchTransferModal.submitting ? 'Đang xử lý...' : `Kết chuyển ${formatVnd(batchComputedTotal)} → TK 154` }}
                </button>
                <button @click="batchTransferModal.open = false"
                  class="flex-1 border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm">
                  Hủy bỏ
                </button>
              </div>
            </div>
          </div>
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

          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Ngày</th>
                  <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Loại CP</th>
                  <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Mô tả</th>
                  <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Nguồn</th>
                  <th class="text-right px-4 py-2.5 font-semibold text-gray-600">Số tiền</th>
                  <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Bút toán</th>
                  <th class="text-left px-4 py-2.5 font-semibold text-gray-600">Trạng thái</th>
                  <th class="text-left px-4 py-2.5 font-semibold text-gray-600 hidden md:table-cell">Người XL</th>
                  <th v-if="can('project.wip.adjust')" class="px-4 py-2.5" />
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="e in wipEntries" :key="e.id"
                  :class="['hover:bg-gray-50', e.status !== 'active' ? 'opacity-60' : '']">
                  <td class="px-4 py-2 text-gray-600 whitespace-nowrap">{{ e.entry_date }}</td>
                  <td class="px-4 py-2">
                    <span class="bg-purple-100 text-purple-700 px-2 py-0.5 rounded text-xs font-medium">{{ e.label }}</span>
                  </td>
                  <td class="px-4 py-2 text-gray-800 max-w-xs truncate">
                    {{ e.description }}
                    <p v-if="e.cancel_reason" class="text-xs text-gray-400 truncate">{{ e.cancel_reason }}</p>
                  </td>
                  <td class="px-4 py-2 text-xs text-gray-500 whitespace-nowrap">
                    {{ wipSourceLabel(e.source_type) }}
                    <span v-if="e.source_code" class="ml-1 font-mono text-gray-400">{{ e.source_code }}</span>
                  </td>
                  <td class="px-4 py-2 text-right font-medium text-gray-800 whitespace-nowrap">{{ formatVnd(e.amount) }}</td>
                  <td class="px-4 py-2 text-xs text-gray-500 font-mono whitespace-nowrap">{{ e.journal_code ?? '—' }}</td>
                  <td class="px-4 py-2 whitespace-nowrap">
                    <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', wipStatusClass(e.status)]">
                      {{ e.status_label }}
                    </span>
                  </td>
                  <td class="px-4 py-2 text-xs text-gray-400 whitespace-nowrap hidden md:table-cell">
                    <template v-if="e.cancelled_by_name">
                      <p>{{ e.cancelled_by_name }}</p>
                      <p class="text-gray-300">{{ e.cancelled_at }}</p>
                    </template>
                    <span v-else>—</span>
                  </td>
                  <td v-if="can('project.wip.adjust')" class="px-4 py-2 text-right whitespace-nowrap">
                    <div v-if="e.status === 'active'" class="relative inline-block">
                      <button @click="openWipMenu(e)"
                        class="text-xs text-gray-500 hover:text-primary-600 border border-gray-200 hover:border-primary-300 px-2 py-1 rounded">
                        Xử lý ▾
                      </button>
                    </div>
                    <button v-else @click="viewWipHistory(e)"
                      class="text-xs text-gray-400 hover:text-gray-600 underline">
                      Lịch sử
                    </button>
                  </td>
                </tr>
                <tr v-if="!wipEntries.length">
                  <td :colspan="can('project.wip.adjust') ? 9 : 8" class="px-4 py-10 text-center text-gray-400">
                    Chưa có chi phí dở dang nào cho dự án này.
                    <br><span class="text-xs">Tạo phiếu xuất kho với mục đích "Xuất cho dự án" để tích lũy TK 154.</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- WIP Action Menu (dropdown-style modal) -->
        <div v-if="wipMenu.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30"
          @click.self="wipMenu.open = false">
          <div class="bg-white rounded-xl shadow-xl w-80 p-5 space-y-3">
            <h3 class="font-semibold text-gray-800 text-sm">Xử lý chi phí dở dang</h3>
            <p class="text-xs text-gray-500 truncate">{{ wipMenu.entry?.description }}</p>
            <p class="text-sm font-bold text-gray-900">{{ formatVnd(wipMenu.entry?.amount) }}</p>
            <div class="flex flex-col gap-2 pt-1">
              <!-- Non-stock-exit: phân biệt có/không có bút toán -->
              <template v-if="!wipMenu.entry?.is_stock_exit">
                <button v-if="wipMenu.entry?.has_je"
                  @click="openWipCorrection(wipMenu.entry, 'cancel')"
                  class="text-left text-sm px-3 py-2 rounded-lg hover:bg-red-50 text-red-600 border border-red-100">
                  Hủy chi phí / Tạo bút toán đảo
                </button>
                <button v-else
                  @click="openWipCorrection(wipMenu.entry, 'cancel')"
                  class="text-left text-sm px-3 py-2 rounded-lg hover:bg-gray-50 text-gray-600 border border-gray-200">
                  Xóa dòng chưa hạch toán
                </button>
              </template>
              <!-- Stock-exit: link trực tiếp đến phiếu xuất kho -->
              <template v-else>
                <a v-if="wipMenu.entry?.source_id"
                  :href="route('warehouse.stock-exits.show', wipMenu.entry.source_id)"
                  class="text-left text-sm px-3 py-2 rounded-lg hover:bg-yellow-50 text-yellow-700 border border-yellow-200 block"
                  @click="wipMenu.open = false">
                  → Xem phiếu xuất kho {{ wipMenu.entry.source_code ?? '' }}
                </a>
                <p class="text-xs text-gray-400 px-3">Hủy phiếu xuất kho để đảo chi phí TK 154 và hoàn tồn kho.</p>
              </template>
              <button @click="openWipCorrection(wipMenu.entry, 'transfer')"
                class="text-left text-sm px-3 py-2 rounded-lg hover:bg-blue-50 text-blue-600 border border-blue-100">
                Chuyển sang dự án khác
              </button>
              <button @click="openWipCorrection(wipMenu.entry, 'reclass')"
                class="text-left text-sm px-3 py-2 rounded-lg hover:bg-orange-50 text-orange-600 border border-orange-100">
                Điều chỉnh sang tài khoản khác
              </button>
            </div>
            <button @click="wipMenu.open = false" class="w-full text-xs text-gray-400 pt-1">Đóng</button>
          </div>
        </div>

        <!-- WIP Correction Modal -->
        <div v-if="wipCorrection.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4"
          @click.self="wipCorrection.open = false">
          <div class="bg-white rounded-xl shadow-xl w-full max-w-lg space-y-4 p-6">
            <h3 class="font-semibold text-gray-900">{{ wipCorrectionTitle }}</h3>

            <!-- Entry info -->
            <div class="bg-gray-50 rounded-lg px-4 py-3 text-sm space-y-1">
              <p class="text-gray-500">Dòng chi phí: <span class="text-gray-900 font-medium">{{ wipCorrection.entry?.description }}</span></p>
              <p class="text-gray-500">Số tiền TK 154: <span class="text-gray-900 font-bold">{{ formatVnd(wipCorrection.entry?.amount) }}</span></p>
              <p class="text-gray-500">Bút toán gốc: <span class="font-mono text-gray-700">{{ wipCorrection.entry?.journal_code ?? '—' }}</span></p>
            </div>

            <!-- Transfer: choose target project -->
            <div v-if="wipCorrection.action === 'transfer'" class="space-y-1">
              <label class="text-xs font-medium text-gray-600">Dự án đích</label>
              <select v-model="wipCorrection.targetProjectId"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" required>
                <option value="">-- Chọn dự án --</option>
                <option v-for="p in allActiveProjects" :key="p.id" :value="p.id">
                  {{ p.code }} — {{ p.name }}
                </option>
              </select>
            </div>

            <!-- Reclass: choose target account -->
            <div v-if="wipCorrection.action === 'reclass'" class="space-y-1">
              <label class="text-xs font-medium text-gray-600">Tài khoản đích</label>
              <input v-model="wipCorrection.targetAccountCode" type="text" placeholder="Vd: 6422, 632, 1561"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono" />
              <p class="text-xs text-gray-400">Chỉ dùng tài khoản chi tiết (is_detail=true). Vd: 6422, 632, 1561...</p>
            </div>

            <!-- Preview JE -->
            <div v-if="wipCorrection.preview">
              <p class="text-xs font-medium text-gray-600 mb-2">Bút toán sẽ tạo:</p>
              <div class="bg-gray-50 rounded-lg overflow-hidden border border-gray-200">
                <table class="min-w-full text-xs">
                  <thead class="bg-gray-100">
                    <tr>
                      <th class="text-left px-3 py-1.5 font-semibold text-gray-600">TK</th>
                      <th class="text-right px-3 py-1.5 font-semibold text-gray-600">Nợ</th>
                      <th class="text-right px-3 py-1.5 font-semibold text-gray-600">Có</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-100">
                    <tr v-for="(line, i) in wipCorrection.preview.je_lines" :key="i">
                      <td class="px-3 py-1.5 font-mono text-gray-700">
                        {{ line.account_code }}
                        <span v-if="line.project_id" class="text-gray-400"> [DA:{{ line.project_id }}]</span>
                      </td>
                      <td class="px-3 py-1.5 text-right text-gray-800">{{ line.debit > 0 ? formatVnd(line.debit) : '—' }}</td>
                      <td class="px-3 py-1.5 text-right text-gray-800">{{ line.credit > 0 ? formatVnd(line.credit) : '—' }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p v-if="wipCorrection.preview.warning" class="text-xs text-yellow-600 mt-2">
                ⚠ {{ wipCorrection.preview.warning }}
              </p>
              <p v-if="wipCorrection.preview.period_info?.is_locked" class="text-xs text-red-600 mt-2">
                ⚠ Kỳ kế toán {{ wipCorrection.preview.period_info.period }} đã khóa. Bút toán điều chỉnh sẽ được ghi vào kỳ hiện tại.
              </p>
            </div>
            <p v-else-if="wipCorrection.loadingPreview" class="text-xs text-gray-400">Đang tải preview...</p>

            <!-- Reason -->
            <div class="space-y-1">
              <label class="text-xs font-medium text-gray-600">Lý do <span class="text-red-500">*</span></label>
              <textarea v-model="wipCorrection.reason" rows="2" required
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm resize-none"
                placeholder="Nhập lý do bắt buộc..." />
            </div>

            <!-- Error -->
            <p v-if="wipCorrection.error" class="text-xs text-red-600">{{ wipCorrection.error }}</p>

            <div class="flex gap-3 pt-1">
              <button @click="submitWipCorrection" :disabled="!wipCorrection.reason.trim() || wipCorrection.submitting"
                class="flex-1 bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white px-4 py-2 rounded-lg text-sm font-medium">
                {{ wipCorrection.submitting ? 'Đang xử lý...' : 'Xác nhận' }}
              </button>
              <button @click="wipCorrection.open = false"
                class="flex-1 border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium">
                Hủy bỏ
              </button>
            </div>
          </div>
        </div>

        <!-- WIP History Modal -->
        <div v-if="wipHistory.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4"
          @click.self="wipHistory.open = false">
          <div class="bg-white rounded-xl shadow-xl w-full max-w-lg p-6 space-y-4">
            <h3 class="font-semibold text-gray-900">Lịch sử xử lý chi phí</h3>
            <p class="text-xs text-gray-500">{{ wipHistory.entry?.description }}</p>
            <div v-if="wipHistory.loading" class="text-center text-gray-400 py-4">Đang tải...</div>
            <table v-else-if="wipHistory.logs.length" class="min-w-full text-xs">
              <thead class="bg-gray-50">
                <tr>
                  <th class="text-left px-3 py-2 font-semibold text-gray-600">Hành động</th>
                  <th class="text-left px-3 py-2 font-semibold text-gray-600">Lý do</th>
                  <th class="text-left px-3 py-2 font-semibold text-gray-600">Người thực hiện</th>
                  <th class="text-left px-3 py-2 font-semibold text-gray-600">Thời gian</th>
                  <th class="text-left px-3 py-2 font-semibold text-gray-600">Bút toán</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="log in wipHistory.logs" :key="log.id">
                  <td class="px-3 py-2 font-medium text-gray-700">{{ log.action_label }}</td>
                  <td class="px-3 py-2 text-gray-600 max-w-xs truncate">{{ log.reason }}</td>
                  <td class="px-3 py-2 text-gray-600">{{ log.performed_by }}</td>
                  <td class="px-3 py-2 text-gray-500 whitespace-nowrap">{{ log.performed_at }}</td>
                  <td class="px-3 py-2 font-mono text-gray-500">{{ log.je_code ?? '—' }}</td>
                </tr>
              </tbody>
            </table>
            <p v-else class="text-center text-gray-400 py-4 text-xs">Chưa có lịch sử xử lý.</p>
            <button @click="wipHistory.open = false"
              class="w-full border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm">
              Đóng
            </button>
          </div>
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
import { ref, computed, reactive, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import RemoteSearchSelect from '@/Components/Shared/RemoteSearchSelect.vue';
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
  allActiveProjects:   { type: Array,  default: () => [] },
  contract_value:      { type: Number, default: null },
  actual_cost_from_pi: { type: Number, default: 0 },
  stockExitItems:      { type: Array,  default: () => [] },
  stockExitTotal:      { type: Number, default: 0 },
  directMaterials:     { type: Array,  default: () => [] },
  directMaterialTotal: { type: Number, default: 0 },
  funds:               { type: Array,  default: () => [] },
  bankAccounts:        { type: Array,  default: () => [] },
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
  'App\\Models\\StockExit':             'Xuất kho',
  'App\\Models\\ProjectExpense':        'Chi phí PS',
  'App\\Models\\ProjectDirectMaterial': 'Vật tư phát sinh',
};
const wipSourceLabel = (type) => WIP_SOURCE_LABELS[type] ?? type?.split('\\').pop() ?? '—';

const wipStatusClass = (status) => {
  if (status === 'active')      return 'bg-green-100 text-green-700';
  if (status === 'cancelled')   return 'bg-red-100 text-red-600';
  if (status === 'adjusted')    return 'bg-yellow-100 text-yellow-700';
  if (status === 'transferred') return 'bg-blue-100 text-blue-700';
  return 'bg-gray-100 text-gray-500';
};

// WIP action menu
const wipMenu = reactive({ open: false, entry: null });
const openWipMenu = (entry) => { wipMenu.entry = entry; wipMenu.open = true; };

// WIP correction modal
const wipCorrection = reactive({
  open: false,
  entry: null,
  action: null,
  targetProjectId: '',
  targetAccountCode: '',
  reason: '',
  preview: null,
  loadingPreview: false,
  submitting: false,
  error: '',
});

const wipCorrectionTitle = computed(() => ({
  cancel:   'Hủy chi phí dở dang TK 154',
  transfer: 'Chuyển chi phí sang dự án khác',
  reclass:  'Điều chỉnh tài khoản chi phí',
}[wipCorrection.action] ?? 'Xử lý chi phí dở dang'));

const openWipCorrection = async (entry, action) => {
  wipMenu.open = false;
  Object.assign(wipCorrection, {
    open: true, entry, action,
    targetProjectId: '', targetAccountCode: '',
    reason: '', preview: null, error: '',
    loadingPreview: false, submitting: false,
  });

  if (action === 'cancel') {
    await loadWipPreview();
  }
};

const loadWipPreview = async () => {
  if (!wipCorrection.entry) return;
  wipCorrection.loadingPreview = true;
  wipCorrection.preview = null;
  wipCorrection.error = '';

  const urlMap = {
    cancel:   route('projects.projects.wip.preview-cancel',   [props.project.id, wipCorrection.entry.id]),
    transfer: route('projects.projects.wip.preview-transfer', [props.project.id, wipCorrection.entry.id]),
    reclass:  route('projects.projects.wip.preview-reclass',  [props.project.id, wipCorrection.entry.id]),
  };

  const body = {
    ...(wipCorrection.action === 'transfer' ? { target_project_id: wipCorrection.targetProjectId } : {}),
    ...(wipCorrection.action === 'reclass'  ? { target_account_code: wipCorrection.targetAccountCode } : {}),
  };

  try {
    const res = await fetch(urlMap[wipCorrection.action], {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
      body: JSON.stringify(body),
    });
    const data = await res.json();
    if (data.error) { wipCorrection.error = data.error; }
    else { wipCorrection.preview = data; }
  } catch {
    wipCorrection.error = 'Không thể tải preview. Thử lại.';
  } finally {
    wipCorrection.loadingPreview = false;
  }
};

const submitWipCorrection = () => {
  if (!wipCorrection.reason.trim()) return;
  wipCorrection.submitting = true;
  wipCorrection.error = '';

  const urlMap = {
    cancel:   route('projects.projects.wip.cancel',   [props.project.id, wipCorrection.entry.id]),
    transfer: route('projects.projects.wip.transfer', [props.project.id, wipCorrection.entry.id]),
    reclass:  route('projects.projects.wip.reclass',  [props.project.id, wipCorrection.entry.id]),
  };

  const data = {
    reason: wipCorrection.reason,
    ...(wipCorrection.action === 'transfer' ? { target_project_id: wipCorrection.targetProjectId } : {}),
    ...(wipCorrection.action === 'reclass'  ? { target_account_code: wipCorrection.targetAccountCode } : {}),
  };

  router.post(urlMap[wipCorrection.action], data, {
    preserveScroll: true,
    onSuccess: () => { wipCorrection.open = false; },
    onError: (errors) => {
      wipCorrection.error = Object.values(errors)[0] ?? 'Có lỗi xảy ra.';
      wipCorrection.submitting = false;
    },
    onFinish: () => { wipCorrection.submitting = false; },
  });
};

// WIP history modal
const wipHistory = reactive({ open: false, entry: null, logs: [], loading: false });

const viewWipHistory = async (entry) => {
  wipHistory.entry = entry;
  wipHistory.open = true;
  wipHistory.loading = true;
  wipHistory.logs = [];

  try {
    const res = await fetch(route('projects.projects.wip.history', [props.project.id, entry.id]));
    const data = await res.json();
    wipHistory.logs = data.logs ?? [];
  } catch {
    wipHistory.logs = [];
  } finally {
    wipHistory.loading = false;
  }
};

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
const COMMON_CREDIT_ACCOUNTS = ['3311', '1111', '1121', '3341', '3388', '141', '331UT'];

// TK Nợ mặc định theo TT133 cho từng danh mục chi phí dự án
const TT133_DEBIT = {
  labor:     { code: '6271', name: 'Chi phí nhân công TT' },
  equipment: { code: '6237', name: 'Chi phí sử dụng máy' },
  material:  { code: '6272', name: 'Chi phí vật liệu TT' },
  transport: { code: '6278', name: 'Chi phí vận chuyển' },
  other:     { code: '6279', name: 'Chi phí khác' },
};

const expenseForm = reactive({
  category: 'labor',
  description: '',
  amount: '',
  expense_date: '',
  debit_account: '',
  debit_account_name: '',
  credit_account: '',
  credit_account_name: '',
  // Conditional fields
  supplier_id: null,
  supplier_name: '',
  fund_id: '',
  bank_account_id: '',
  employee_id: '',
  // Extra
  payment_method: 'payable',
  invoice_number: '',
  vat_amount: '',
  vat_rate: '',
  force_duplicate: false,
});

// Computed helpers for conditional field visibility
const expenseShowSupplier = computed(() => {
  const ca = expenseForm.credit_account;
  return !ca || ca === '3311' || ca === '331UT';
});
const expenseShowEmployee = computed(() => {
  const ca = expenseForm.credit_account;
  return ca === '3341' || ca === '141';
});

// Auto-fill TK Nợ khi đổi danh mục (theo TT133); không override nếu user đã tự nhập
watch(() => expenseForm.category, (newCat, oldCat) => {
  const prev = TT133_DEBIT[oldCat];
  const next = TT133_DEBIT[newCat];
  if (!next) return;
  // Chỉ auto-fill nếu TK Nợ đang trống HOẶC đang là default của danh mục cũ
  if (!expenseForm.debit_account || expenseForm.debit_account === prev?.code) {
    expenseForm.debit_account = next.code;
    expenseForm.debit_account_name = `${next.code} - ${next.name}`;
  }
}, { immediate: true });

// Block submit when required fields missing based on TK Có
const expenseSubmitBlocked = computed(() => {
  const ca = expenseForm.credit_account;
  if (!expenseForm.debit_account) return false; // TK Nợ optional — backend sẽ resolve từ category
  if (/^15[26]/.test(expenseForm.debit_account)) return true;
  if (ca === '3311' && !expenseForm.supplier_id) return true;
  if (ca === '1111' && !expenseForm.fund_id) return true;
  if (ca === '1121' && !expenseForm.bank_account_id) return true;
  if (ca === '141' && !expenseForm.employee_id) return true;
  return false;
});

// Auto-fill TK Có khi đổi hình thức thanh toán
const CREDIT_ACCOUNT_DEFAULTS = { payable: '3311', cash: '1111', bank: '1121' };
watch(() => expenseForm.payment_method, (method) => {
  const defaultAcct = CREDIT_ACCOUNT_DEFAULTS[method] ?? '';
  const currentIsDefault = Object.values(CREDIT_ACCOUNT_DEFAULTS).includes(expenseForm.credit_account);
  if (!expenseForm.credit_account || currentIsDefault) {
    expenseForm.credit_account = defaultAcct;
    expenseForm.credit_account_name = '';
  }
});

// Clear conditional fields when TK Có changes
watch(() => expenseForm.credit_account, (newVal) => {
  if (newVal !== '3311' && newVal !== '331UT') {
    expenseForm.supplier_id = null;
    expenseForm.supplier_name = '';
  }
  if (newVal !== '1111') expenseForm.fund_id = '';
  if (newVal !== '1121') expenseForm.bank_account_id = '';
  if (newVal !== '3341' && newVal !== '141') expenseForm.employee_id = '';
});

function resetExpenseForm() {
  const defaultCat = 'labor';
  const defaultDebit = TT133_DEBIT[defaultCat];
  Object.assign(expenseForm, {
    category: defaultCat, description: '', amount: '', expense_date: '',
    debit_account: defaultDebit.code,
    debit_account_name: `${defaultDebit.code} - ${defaultDebit.name}`,
    credit_account: '', credit_account_name: '',
    supplier_id: null, supplier_name: '', fund_id: '', bank_account_id: '', employee_id: '',
    payment_method: 'payable', invoice_number: '', vat_amount: '', vat_rate: '',
    force_duplicate: false,
  });
}

const addExpense = () => {
  if (expenseSubmitBlocked.value) return;
  expenseForm.force_duplicate = false;
  router.post(route('projects.projects.expenses.store', props.project.id), expenseForm, {
    preserveScroll: true,
    onSuccess: () => resetExpenseForm(),
  });
};

// Ghi nhận bất chấp cảnh báo trùng hóa đơn
const submitExpenseForce = () => {
  expenseForm.force_duplicate = true;
  router.post(route('projects.projects.expenses.store', props.project.id), expenseForm, {
    preserveScroll: true,
    onSuccess: () => resetExpenseForm(),
  });
};

const removeExpense = (expenseId) => {
  if (!confirm('Xóa chi phí này? Bút toán kế toán và WIP TK 154 sẽ bị đảo.')) return;
  router.delete(route('projects.projects.expenses.destroy', [props.project.id, expenseId]), { preserveScroll: true });
};

// ─── Multi-select checkboxes ───────────────────────────────────────────────
const selectedExpenseIds = ref([]);

const selectableExpenses = computed(() =>
  props.project.expenses.filter(e => e.can_transfer)
);

const allSelectableChecked = computed(() =>
  selectableExpenses.value.length > 0 &&
  selectableExpenses.value.every(e => selectedExpenseIds.value.includes(e.id))
);

const selectedExpensesTotal = computed(() =>
  props.project.expenses
    .filter(e => selectedExpenseIds.value.includes(e.id))
    .reduce((sum, e) => sum + (e.remaining_amount ?? 0), 0)
);

function toggleExpenseSelection(id) {
  const idx = selectedExpenseIds.value.indexOf(id);
  if (idx >= 0) selectedExpenseIds.value.splice(idx, 1);
  else selectedExpenseIds.value.push(id);
}

function toggleSelectAll() {
  if (allSelectableChecked.value) {
    selectedExpenseIds.value = [];
  } else {
    selectedExpenseIds.value = selectableExpenses.value.map(e => e.id);
  }
}

function selectAllAndTransfer() {
  if (selectedExpenseIds.value.length === 0) {
    // Chưa có gì được chọn → chọn tất cả rồi mở modal
    selectedExpenseIds.value = selectableExpenses.value.map(e => e.id);
  }
  openBatchTransferModal();
}

// ─── Batch transfer modal ──────────────────────────────────────────────────
const batchTransferModal = reactive({
  open: false,
  rows: [],
  amounts: {},
  transfer_date: new Date().toISOString().slice(0, 10),
  description: '',
  submitting: false,
  error: '',
});

const batchCreditGroups = computed(() => {
  const groups = {};
  for (const row of batchTransferModal.rows) {
    if (row.error || !row.credit_account) continue;
    const amt = Number(batchTransferModal.amounts[row.expense_id] ?? row.remaining ?? 0);
    if (amt > 0) {
      groups[row.credit_account] = (groups[row.credit_account] ?? 0) + amt;
    }
  }
  return groups;
});

const batchComputedTotal = computed(() =>
  Object.values(batchCreditGroups.value).reduce((s, v) => s + v, 0)
);

function openBatchTransferModal() {
  const rows = props.project.expenses
    .filter(e => selectedExpenseIds.value.includes(e.id) && e.can_transfer)
    .map(e => ({
      expense_id:     e.id,
      description:    e.description,
      credit_account: e.debit_account ?? null,
      remaining:      e.remaining_amount ?? 0,
      amount:         e.remaining_amount ?? 0,
      error:          null,
    }));

  const amounts = {};
  for (const row of rows) {
    amounts[row.expense_id] = row.remaining;
  }

  Object.assign(batchTransferModal, {
    open: true,
    rows,
    amounts,
    transfer_date: new Date().toISOString().slice(0, 10),
    description: `Kết chuyển chi phí dự án ${props.project.code} sang TK 154`,
    submitting: false,
    error: '',
  });
}

function submitBatchTransfer() {
  if (batchComputedTotal.value <= 0 || !batchTransferModal.transfer_date) return;
  batchTransferModal.submitting = true;
  batchTransferModal.error = '';

  const payload = {
    expense_ids:   batchTransferModal.rows.filter(r => !r.error).map(r => r.expense_id),
    amounts:       { ...batchTransferModal.amounts },
    transfer_date: batchTransferModal.transfer_date,
    description:   batchTransferModal.description || undefined,
  };

  router.post(
    route('projects.projects.expense-transfers-batch.store', props.project.id),
    payload,
    {
      preserveScroll: true,
      onSuccess: () => {
        batchTransferModal.open = false;
        selectedExpenseIds.value = [];
      },
      onError: (errors) => {
        batchTransferModal.error = Object.values(errors)[0] ?? 'Có lỗi xảy ra.';
        batchTransferModal.submitting = false;
      },
      onFinish: () => { batchTransferModal.submitting = false; },
    }
  );
}

// Transfer status helpers
const transferStatusLabel = (status) => ({
  direct_154: 'Vào 154 trực tiếp',
  legacy:     'WIP (cũ)',
  none:       'Chưa kết chuyển',
  partial:    'KC một phần',
  full:       'Đã KC đủ',
}[status] ?? status);

const transferStatusClass = (status) => ({
  direct_154: 'bg-blue-100 text-blue-700',
  legacy:     'bg-gray-100 text-gray-600',
  none:       'bg-amber-100 text-amber-700',
  partial:    'bg-orange-100 text-orange-700',
  full:       'bg-green-100 text-green-700',
}[status] ?? 'bg-gray-100 text-gray-500');

// Modal kết chuyển
const transferModal = reactive({
  open: false,
  expense: null,
  submitting: false,
  error: '',
  form: {
    transfer_date: new Date().toISOString().slice(0, 10),
    amount: '',
    debit_account: '154',
    description: '',
  },
});

const openTransferModal = (expense) => {
  Object.assign(transferModal, {
    open: true,
    expense,
    submitting: false,
    error: '',
  });
  Object.assign(transferModal.form, {
    transfer_date: new Date().toISOString().slice(0, 10),
    amount: expense.remaining_amount ?? expense.amount,
    debit_account: '154',
    description: `Kết chuyển chi phí: ${expense.description}`,
  });
};

const submitTransfer = () => {
  if (!transferModal.form.amount || !transferModal.form.transfer_date) return;
  transferModal.submitting = true;
  transferModal.error = '';

  router.post(
    route('projects.projects.expense-transfers.store', [props.project.id, transferModal.expense.id]),
    { ...transferModal.form },
    {
      preserveScroll: true,
      onSuccess: () => { transferModal.open = false; },
      onError: (errors) => {
        transferModal.error = Object.values(errors)[0] ?? 'Có lỗi xảy ra.';
        transferModal.submitting = false;
      },
      onFinish: () => { transferModal.submitting = false; },
    }
  );
};

// Modal hủy kết chuyển
const cancelTransferModal = reactive({
  open: false,
  expense: null,
  transfer: null,
  reason: '',
  submitting: false,
  error: '',
});

const openCancelTransfer = (expense, transfer) => {
  Object.assign(cancelTransferModal, {
    open: true,
    expense,
    transfer,
    reason: '',
    submitting: false,
    error: '',
  });
};

const submitCancelTransfer = () => {
  if (!cancelTransferModal.reason.trim()) return;
  cancelTransferModal.submitting = true;
  cancelTransferModal.error = '';

  router.delete(
    route('projects.projects.expense-transfers.destroy', [
      props.project.id,
      cancelTransferModal.expense.id,
      cancelTransferModal.transfer.id,
    ]),
    {
      data: { cancel_reason: cancelTransferModal.reason },
      preserveScroll: true,
      onSuccess: () => { cancelTransferModal.open = false; },
      onError: (errors) => {
        cancelTransferModal.error = Object.values(errors)[0] ?? 'Có lỗi xảy ra.';
        cancelTransferModal.submitting = false;
      },
      onFinish: () => { cancelTransferModal.submitting = false; },
    }
  );
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

// Auto-load preview when target changes
watch(() => wipCorrection.targetProjectId, (v) => {
  if (wipCorrection.action === 'transfer' && v) loadWipPreview();
});
watch(() => wipCorrection.targetAccountCode, (v) => {
  if (wipCorrection.action === 'reclass' && v && v.length >= 3) loadWipPreview();
});

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
