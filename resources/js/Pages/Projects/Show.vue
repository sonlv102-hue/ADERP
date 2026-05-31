<template>
  <AppLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex items-start justify-between">
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
        <div class="flex gap-2">
          <Link v-if="can('projects.manage')" :href="route('projects.projects.edit', project.id)"
            class="border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium">
            Sửa thông tin
          </Link>
          <!-- Transition buttons -->
          <template v-for="t in project.allowed_transitions" :key="t.value">
            <button @click="doTransition(t.value)"
              :class="['px-4 py-2 rounded-lg text-sm font-medium', t.value === 'cancelled' ? 'bg-red-600 hover:bg-red-700 text-white' : 'bg-primary-600 hover:bg-primary-700 text-white']">
              {{ t.label }}
            </button>
          </template>
        </div>
      </div>

      <!-- Info cards -->
      <div class="grid grid-cols-4 gap-4">
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
          <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Ngân sách</p>
          <p class="text-2xl font-bold text-gray-900 mt-2">{{ formatVnd(project.budget) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Chi phí thực tế</p>
          <p class="text-2xl font-bold text-gray-900 mt-2">{{ formatVnd(totalCost) }}</p>
          <p class="text-xs mt-1" :class="totalCost > project.budget ? 'text-red-500' : 'text-green-600'">
            {{ totalCost > project.budget ? 'Vượt ngân sách' : 'Trong ngân sách' }}
          </p>
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
        <div class="flex border-b border-gray-200">
          <button v-for="tab in tabs" :key="tab.id" @click="activeTab = tab.id"
            :class="['px-5 py-3 text-sm font-medium border-b-2 -mb-px', activeTab === tab.id ? 'border-primary-600 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700']">
            {{ tab.label }}
            <span v-if="tab.count !== undefined" class="ml-1.5 bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded-full text-xs">{{ tab.count }}</span>
          </button>
        </div>

        <!-- Tasks tab -->
        <div v-if="activeTab === 'tasks'" class="p-5 space-y-4">
          <form v-if="can('projects.manage')" @submit.prevent="addTask" class="flex gap-3">
            <input v-model="taskForm.title" type="text" placeholder="Tiêu đề công việc..."
              class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm" required />
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
              <!-- Status toggle -->
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

              <!-- Priority badge -->
              <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', priorityClass(task.priority)]">
                {{ task.priority === 'high' ? 'Cao' : task.priority === 'medium' ? 'TB' : 'Thấp' }}
              </span>

              <StatusBadge :color="task.status_color" class="text-xs">{{ task.status_label }}</StatusBadge>

              <span v-if="task.due_date" class="text-xs text-gray-400">{{ task.due_date }}</span>

              <button v-if="can('projects.manage')" @click="deleteTask(task.id)"
                class="text-gray-300 hover:text-red-500 ml-1">
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
          <form v-if="can('projects.manage')" @submit.prevent="addMember" class="flex gap-3">
            <select v-model="memberForm.user_id" class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm" required>
              <option value="">-- Chọn nhân viên --</option>
              <option v-for="u in allUsers" :key="u.id" :value="u.id">{{ u.name }}</option>
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
                <p class="text-sm font-medium text-gray-800">{{ m.user.name }}</p>
                <p v-if="m.role" class="text-xs text-gray-500">{{ m.role }}</p>
              </div>
              <button v-if="can('projects.manage')" @click="removeMember(m.id)"
                class="text-gray-400 hover:text-red-500">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            <p v-if="!project.members.length" class="text-center text-gray-400 py-8 text-sm">Chưa có thành viên nào</p>
          </div>
        </div>

        <!-- Materials tab -->
        <div v-if="activeTab === 'materials'" class="p-5 space-y-4">
          <form v-if="can('projects.manage')" @submit.prevent="addMaterial" class="flex gap-3">
            <select v-model="materialForm.product_id" class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm" required>
              <option value="">-- Chọn sản phẩm --</option>
              <option v-for="p in allProducts" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <input v-model="materialForm.quantity" type="number" min="0.01" step="0.01" placeholder="Số lượng"
              class="w-28 border border-gray-300 rounded-lg px-3 py-2 text-sm" required />
            <input v-model="materialForm.unit_price" type="number" min="0" step="1" placeholder="Đơn giá"
              class="w-36 border border-gray-300 rounded-lg px-3 py-2 text-sm" />
            <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
              Thêm
            </button>
          </form>

          <table class="w-full text-sm">
            <thead class="bg-gray-50 border border-gray-200 rounded-lg">
              <tr>
                <th class="text-left px-4 py-2 font-semibold text-gray-600">Sản phẩm</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-600">Số lượng</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-600">Đơn giá</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-600">Thành tiền</th>
                <th class="px-4 py-2" />
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="m in project.materials" :key="m.id" class="hover:bg-gray-50">
                <td class="px-4 py-2 text-gray-800">{{ m.product.name }}
                  <span class="text-gray-400 text-xs ml-1">{{ m.product.unit }}</span>
                </td>
                <td class="px-4 py-2 text-right text-gray-700">{{ m.quantity }}</td>
                <td class="px-4 py-2 text-right text-gray-700">{{ formatVnd(m.unit_price) }}</td>
                <td class="px-4 py-2 text-right font-medium text-gray-800">{{ formatVnd(m.line_total) }}</td>
                <td class="px-4 py-2 text-right">
                  <button v-if="can('projects.manage')" @click="removeMaterial(m.id)"
                    class="text-gray-400 hover:text-red-500">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </td>
              </tr>
              <tr v-if="!project.materials.length">
                <td colspan="5" class="px-4 py-8 text-center text-gray-400">Chưa có vật tư nào</td>
              </tr>
              <tr v-if="project.materials.length" class="bg-gray-50 font-semibold">
                <td colspan="3" class="px-4 py-2 text-right text-gray-700">Tổng chi phí vật tư:</td>
                <td class="px-4 py-2 text-right text-gray-900">{{ formatVnd(project.total_material_cost) }}</td>
                <td />
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Expenses tab -->
        <div v-if="activeTab === 'expenses'" class="p-5 space-y-4">
          <form v-if="can('projects.manage')" @submit.prevent="addExpense" class="grid grid-cols-5 gap-3">
            <select v-model="expenseForm.category" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
              <option v-for="c in expenseCategories" :key="c.value" :value="c.value">{{ c.label }}</option>
            </select>
            <input v-model="expenseForm.description" type="text" placeholder="Mô tả chi phí"
              class="col-span-2 border border-gray-300 rounded-lg px-3 py-2 text-sm" required />
            <input v-model="expenseForm.amount" type="number" min="0" step="1" placeholder="Số tiền"
              class="border border-gray-300 rounded-lg px-3 py-2 text-sm" required />
            <div class="flex gap-2">
              <input v-model="expenseForm.expense_date" type="date"
                class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm" required />
              <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                Thêm
              </button>
            </div>
          </form>

          <table class="w-full text-sm">
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
                  <button v-if="can('projects.manage')" @click="removeExpense(e.id)"
                    class="text-gray-400 hover:text-red-500">
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

        <!-- Info tab -->
        <div v-if="activeTab === 'info'" class="p-5">
          <dl class="grid grid-cols-2 gap-x-8 gap-y-4 text-sm">
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
import { Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  project: Object,
  allUsers: Array,
  allProducts: Array,
  expenseCategories: Array,
});

const { hasPermission } = usePermission();
const can = hasPermission;
const { formatVnd } = useCurrency();

const activeTab = ref('tasks');
const tabs = computed(() => [
  { id: 'tasks',    label: 'Công việc',    count: props.project.tasks.length },
  { id: 'members',  label: 'Nhân sự',      count: props.project.members.length },
  { id: 'materials',label: 'Vật tư',       count: props.project.materials.length },
  { id: 'expenses', label: 'Chi phí PS',   count: props.project.expenses.length },
  { id: 'info',     label: 'Thông tin' },
]);

const doneTasks = computed(() => props.project.tasks.filter(t => t.status === 'done').length);
const totalCost = computed(() => (props.project.total_expenses ?? 0) + (props.project.total_material_cost ?? 0));


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
const memberForm = reactive({ user_id: '', role: '' });
const addMember = () => {
  router.post(route('projects.projects.members.store', props.project.id), memberForm, {
    preserveScroll: true,
    onSuccess: () => { memberForm.user_id = ''; memberForm.role = ''; },
  });
};
const removeMember = (memberId) => {
  if (!confirm('Xóa thành viên này?')) return;
  router.delete(route('projects.projects.members.destroy', [props.project.id, memberId]), { preserveScroll: true });
};

// Material form
const materialForm = reactive({ product_id: '', quantity: '', unit_price: 0, notes: '' });
const addMaterial = () => {
  router.post(route('projects.projects.materials.store', props.project.id), materialForm, {
    preserveScroll: true,
    onSuccess: () => { materialForm.product_id = ''; materialForm.quantity = ''; materialForm.unit_price = 0; },
  });
};
const removeMaterial = (materialId) => {
  if (!confirm('Xóa vật tư này?')) return;
  router.delete(route('projects.projects.materials.destroy', [props.project.id, materialId]), { preserveScroll: true });
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

// Project transition
const doTransition = (status) => {
  router.post(route('projects.projects.transition', props.project.id), { status }, { preserveScroll: true });
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
