<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <h1 class="text-2xl font-bold text-gray-900">Sao lưu dữ liệu</h1>
        <button
          @click="createBackup"
          :disabled="creating"
          class="inline-flex items-center gap-2 bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white text-sm font-medium px-4 py-2 rounded-lg transition"
        >
          <svg v-if="creating" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
          </svg>
          <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
          </svg>
          {{ creating ? 'Đang tạo backup...' : 'Tạo backup ngay' }}
        </button>
      </div>

      <!-- Flash messages -->
      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-lg px-4 py-3">
        {{ $page.props.flash.success }}
      </div>
      <div v-if="$page.props.errors?.error" class="bg-red-50 border border-red-200 text-red-800 text-sm rounded-lg px-4 py-3">
        {{ $page.props.errors.error }}
      </div>

      <!-- Info card -->
      <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 text-sm text-blue-800">
        <p class="font-medium mb-1">Lịch backup tự động</p>
        <p>Hệ thống tự động backup hàng ngày lúc <strong>02:00</strong>. Backup được giữ lại trong <strong>14 ngày</strong>, sau đó tự động xóa.</p>
      </div>

      <!-- Backup list -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <div v-if="backups.length === 0" class="text-center py-16 text-gray-400">
          <svg class="mx-auto h-12 w-12 mb-3 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
          </svg>
          <p>Chưa có backup nào</p>
        </div>

        <table v-else class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="px-4 py-3 text-left font-medium text-gray-600">Tên file</th>
              <th class="px-4 py-3 text-left font-medium text-gray-600">Ngày tạo</th>
              <th class="px-4 py-3 text-right font-medium text-gray-600">Kích thước</th>
              <th class="px-4 py-3 text-right font-medium text-gray-600">Thao tác</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="b in backups" :key="b.name" class="hover:bg-gray-50 transition">
              <td class="px-4 py-3 font-mono text-xs text-gray-700">{{ b.name }}</td>
              <td class="px-4 py-3 text-gray-600">{{ b.created_at }}</td>
              <td class="px-4 py-3 text-right text-gray-600">{{ b.size }}</td>
              <td class="px-4 py-3 text-right">
                <div class="inline-flex gap-2">
                  <a
                    :href="route('admin.backups.download', b.name)"
                    class="inline-flex items-center gap-1 text-primary-600 hover:text-primary-800 font-medium transition"
                  >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    Tải xuống
                  </a>
                  <button
                    @click="deleteBackup(b.name)"
                    class="inline-flex items-center gap-1 text-red-500 hover:text-red-700 font-medium transition"
                  >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    Xóa
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import AppLayout from '@/Components/Layout/AppLayout.vue'
import { router } from '@inertiajs/vue3'
import { ref } from 'vue'

defineProps({ backups: Array })

const creating = ref(false)

function createBackup() {
  creating.value = true
  router.post(route('admin.backups.store'), {}, {
    onFinish: () => { creating.value = false },
  })
}

function deleteBackup(name) {
  if (!confirm(`Xóa backup "${name}"?`)) return
  router.delete(route('admin.backups.destroy', name))
}
</script>
