<template>
  <AppLayout title="Tình trạng hệ thống">
    <div class="max-w-6xl mx-auto px-4 py-6 space-y-6">

      <!-- Header -->
      <div class="flex justify-between items-center flex-wrap gap-y-3">
        <div>
          <h1 class="text-2xl font-bold text-slate-800">Tình trạng hệ thống</h1>
          <p class="text-sm text-slate-500 mt-1">Kiểm tra trạng thái vận hành — không cần SSH.</p>
        </div>
        <button @click="reload" class="btn btn-secondary">Làm mới</button>
      </div>

      <!-- Summary row -->
      <div class="flex flex-wrap gap-2">
        <span v-for="(check, key) in checks" :key="key"
          :class="badgeClass(check.status)"
          class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold">
          {{ statusIcon(check.status) }} {{ CHECK_TITLES[key] ?? key }}
        </span>
      </div>

      <!-- Cards grid -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">

        <!-- Environment -->
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
              <span :class="dotClass(checks.environment.status)" class="w-2.5 h-2.5 rounded-full"></span>
              <h3 class="font-semibold text-slate-800 text-sm">Môi trường</h3>
            </div>
            <span :class="chipClass(checks.environment.status)" class="text-xs px-2 py-0.5 rounded font-medium">
              {{ statusLabel(checks.environment.status) }}
            </span>
          </div>
          <dl class="space-y-1 text-xs">
            <div class="flex justify-between gap-2">
              <dt class="text-slate-500">Environment</dt>
              <dd class="font-medium text-slate-800">{{ checks.environment.detail?.environment }}</dd>
            </div>
            <div class="flex justify-between gap-2">
              <dt class="text-slate-500">PHP</dt>
              <dd class="font-medium text-slate-800">{{ checks.environment.detail?.php_version }}</dd>
            </div>
            <div class="flex justify-between gap-2">
              <dt class="text-slate-500">Laravel</dt>
              <dd class="font-medium text-slate-800">{{ checks.environment.detail?.laravel_version }}</dd>
            </div>
          </dl>
        </div>

        <!-- Database -->
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
              <span :class="dotClass(checks.database.status)" class="w-2.5 h-2.5 rounded-full"></span>
              <h3 class="font-semibold text-slate-800 text-sm">Database</h3>
            </div>
            <span :class="chipClass(checks.database.status)" class="text-xs px-2 py-0.5 rounded font-medium">
              {{ statusLabel(checks.database.status) }}
            </span>
          </div>
          <template v-if="isObj(checks.database.detail)">
            <dl class="space-y-1 text-xs">
              <div class="flex justify-between gap-2">
                <dt class="text-slate-500">Driver</dt>
                <dd class="font-medium text-slate-800">{{ checks.database.detail.driver }}</dd>
              </div>
              <div class="flex justify-between gap-2">
                <dt class="text-slate-500">Database</dt>
                <dd class="font-medium text-slate-800">{{ checks.database.detail.database }}</dd>
              </div>
            </dl>
          </template>
          <p v-else class="text-xs text-red-600 break-words">{{ checks.database.detail }}</p>
        </div>

        <!-- Migrations -->
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
              <span :class="dotClass(checks.migrations.status)" class="w-2.5 h-2.5 rounded-full"></span>
              <h3 class="font-semibold text-slate-800 text-sm">Migration</h3>
            </div>
            <span :class="chipClass(checks.migrations.status)" class="text-xs px-2 py-0.5 rounded font-medium">
              {{ statusLabel(checks.migrations.status) }}
            </span>
          </div>
          <dl class="space-y-1 text-xs">
            <div class="flex justify-between gap-2">
              <dt class="text-slate-500">Tổng</dt>
              <dd class="font-medium text-slate-800">{{ checks.migrations.detail?.total }}</dd>
            </div>
            <div class="flex justify-between gap-2">
              <dt class="text-slate-500">Đã chạy</dt>
              <dd class="font-medium text-slate-800">{{ checks.migrations.detail?.ran }}</dd>
            </div>
            <div class="flex justify-between gap-2">
              <dt class="text-slate-500">Chưa chạy</dt>
              <dd class="font-medium" :class="(checks.migrations.detail?.pending?.length ?? 0) > 0 ? 'text-yellow-700' : 'text-slate-800'">
                {{ checks.migrations.detail?.pending?.length ?? 0 }}
              </dd>
            </div>
          </dl>
          <div v-if="checks.migrations.detail?.pending?.length" class="mt-3 pt-3 border-t border-slate-100">
            <p class="text-xs font-semibold text-yellow-700 mb-1">Chưa chạy:</p>
            <ul class="space-y-0.5">
              <li v-for="m in checks.migrations.detail.pending" :key="m"
                class="text-xs font-mono text-yellow-800 truncate">{{ m }}</li>
            </ul>
          </div>
        </div>

        <!-- Queue -->
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
              <span :class="dotClass(checks.queue.status)" class="w-2.5 h-2.5 rounded-full"></span>
              <h3 class="font-semibold text-slate-800 text-sm">Queue</h3>
            </div>
            <span :class="chipClass(checks.queue.status)" class="text-xs px-2 py-0.5 rounded font-medium">
              {{ statusLabel(checks.queue.status) }}
            </span>
          </div>
          <dl class="space-y-1 text-xs">
            <div class="flex justify-between gap-2">
              <dt class="text-slate-500">Connection</dt>
              <dd class="font-medium text-slate-800">{{ isObj(checks.queue.detail) ? checks.queue.detail.connection : '—' }}</dd>
            </div>
            <div class="flex justify-between gap-2">
              <dt class="text-slate-500">Job thất bại</dt>
              <dd class="font-medium" :class="(checks.queue.detail?.failed_jobs ?? 0) > 0 ? 'text-yellow-700' : 'text-slate-800'">
                {{ isObj(checks.queue.detail) ? checks.queue.detail.failed_jobs : '—' }}
              </dd>
            </div>
            <div v-if="isObj(checks.queue.detail) && checks.queue.detail.last_failed_at" class="flex justify-between gap-2">
              <dt class="text-slate-500">Thất bại gần nhất</dt>
              <dd class="font-medium text-slate-800">{{ checks.queue.detail.last_failed_at }}</dd>
            </div>
          </dl>
        </div>

        <!-- Frontend -->
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
              <span :class="dotClass(checks.frontend.status)" class="w-2.5 h-2.5 rounded-full"></span>
              <h3 class="font-semibold text-slate-800 text-sm">Frontend Build</h3>
            </div>
            <span :class="chipClass(checks.frontend.status)" class="text-xs px-2 py-0.5 rounded font-medium">
              {{ statusLabel(checks.frontend.status) }}
            </span>
          </div>
          <template v-if="isObj(checks.frontend.detail)">
            <dl class="space-y-1 text-xs">
              <div class="flex justify-between gap-2">
                <dt class="text-slate-500">Manifest</dt>
                <dd class="font-mono text-slate-800 text-right">{{ checks.frontend.detail.manifest }}</dd>
              </div>
              <div class="flex justify-between gap-2">
                <dt class="text-slate-500">Build lúc</dt>
                <dd class="font-medium text-slate-800">{{ checks.frontend.detail.built_at }}</dd>
              </div>
            </dl>
          </template>
          <p v-else class="text-xs" :class="checks.frontend.status === 'warning' ? 'text-yellow-700' : 'text-slate-500'">
            {{ checks.frontend.detail }}
          </p>
        </div>

        <!-- Git -->
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
              <span :class="dotClass(checks.git.status)" class="w-2.5 h-2.5 rounded-full"></span>
              <h3 class="font-semibold text-slate-800 text-sm">Git</h3>
            </div>
            <span :class="chipClass(checks.git.status)" class="text-xs px-2 py-0.5 rounded font-medium">
              {{ statusLabel(checks.git.status) }}
            </span>
          </div>
          <template v-if="isObj(checks.git.detail) && checks.git.detail.branch">
            <dl class="space-y-1 text-xs">
              <div class="flex justify-between gap-2">
                <dt class="text-slate-500">Branch</dt>
                <dd class="font-mono text-slate-800">{{ checks.git.detail.branch }}</dd>
              </div>
              <div class="flex justify-between gap-2">
                <dt class="text-slate-500">Commit</dt>
                <dd class="font-mono text-slate-800">{{ checks.git.detail.commit }}</dd>
              </div>
              <div v-if="checks.git.detail.message" class="flex gap-2">
                <dt class="text-slate-500 flex-shrink-0">Message</dt>
                <dd class="text-slate-800 text-right truncate">{{ checks.git.detail.message }}</dd>
              </div>
            </dl>
          </template>
          <p v-else class="text-xs text-slate-400">Không đọc được thông tin git.</p>
        </div>

        <!-- Deploy metadata -->
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
              <span :class="dotClass(checks.deploy.status)" class="w-2.5 h-2.5 rounded-full"></span>
              <h3 class="font-semibold text-slate-800 text-sm">Thông tin Deploy</h3>
            </div>
            <span :class="chipClass(checks.deploy.status)" class="text-xs px-2 py-0.5 rounded font-medium">
              {{ statusLabel(checks.deploy.status) }}
            </span>
          </div>
          <template v-if="isObj(checks.deploy.detail) && checks.deploy.detail.deployed_at">
            <dl class="space-y-1 text-xs">
              <div class="flex justify-between gap-2">
                <dt class="text-slate-500">Thời điểm</dt>
                <dd class="font-medium text-slate-800">{{ checks.deploy.detail.deployed_at }}</dd>
              </div>
              <div v-if="checks.deploy.detail.branch" class="flex justify-between gap-2">
                <dt class="text-slate-500">Branch</dt>
                <dd class="font-mono text-slate-800">{{ checks.deploy.detail.branch }}</dd>
              </div>
              <div v-if="checks.deploy.detail.commit" class="flex justify-between gap-2">
                <dt class="text-slate-500">Commit</dt>
                <dd class="font-mono text-slate-800">{{ checks.deploy.detail.commit }}</dd>
              </div>
              <div v-if="checks.deploy.detail.deployed_by" class="flex justify-between gap-2">
                <dt class="text-slate-500">Deploy bởi</dt>
                <dd class="font-medium text-slate-800">{{ checks.deploy.detail.deployed_by }}</dd>
              </div>
              <div v-if="checks.deploy.detail.commit_message" class="flex gap-2">
                <dt class="text-slate-500 flex-shrink-0">Message</dt>
                <dd class="text-slate-800 truncate">{{ checks.deploy.detail.commit_message }}</dd>
              </div>
            </dl>
          </template>
          <p v-else class="text-xs text-slate-400">{{ checks.deploy.detail }}</p>
        </div>

        <!-- Maintenance -->
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
              <span :class="dotClass(checks.maintenance.status)" class="w-2.5 h-2.5 rounded-full"></span>
              <h3 class="font-semibold text-slate-800 text-sm">Maintenance Mode</h3>
            </div>
            <span :class="chipClass(checks.maintenance.status)" class="text-xs px-2 py-0.5 rounded font-medium">
              {{ statusLabel(checks.maintenance.status) }}
            </span>
          </div>
          <p class="text-xs" :class="checks.maintenance.detail?.is_down ? 'text-yellow-700 font-semibold' : 'text-slate-500'">
            {{ checks.maintenance.detail?.is_down
              ? 'App đang ở chế độ bảo trì (down for maintenance).'
              : 'Hệ thống hoạt động bình thường.' }}
          </p>
        </div>

      </div>

      <!-- Storage permissions full table -->
      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
          <div class="flex items-center gap-3">
            <span :class="dotClass(checks.storage.status)" class="w-3 h-3 rounded-full flex-shrink-0"></span>
            <h2 class="font-semibold text-slate-800">Quyền thư mục</h2>
          </div>
          <span :class="chipClass(checks.storage.status)" class="text-xs font-semibold px-2 py-0.5 rounded">
            {{ statusLabel(checks.storage.status) }}
          </span>
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50">
              <tr>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wide">Thư mục</th>
                <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wide">Tồn tại</th>
                <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wide">Ghi được</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wide">Trạng thái</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="row in (checks.storage.detail ?? [])" :key="row.path">
                <td class="px-5 py-3 font-mono text-sm text-slate-700">{{ row.path }}</td>
                <td class="px-5 py-3 text-center">
                  <span :class="row.exists ? 'text-green-600' : 'text-red-500'" class="font-bold">
                    {{ row.exists ? '✓' : '✗' }}
                  </span>
                </td>
                <td class="px-5 py-3 text-center">
                  <span :class="row.writable ? 'text-green-600' : 'text-red-500'" class="font-bold">
                    {{ row.writable ? '✓' : '✗' }}
                  </span>
                </td>
                <td class="px-5 py-3">
                  <span v-if="row.writable" class="text-xs text-green-700">OK</span>
                  <span v-else class="text-xs text-red-600 font-semibold">
                    {{ row.exists ? 'Không có quyền ghi' : 'Thư mục không tồn tại' }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Laravel Log -->
      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
          <div class="flex items-center gap-3">
            <span :class="dotClass(checks.log.status)" class="w-3 h-3 rounded-full flex-shrink-0"></span>
            <h2 class="font-semibold text-slate-800">Laravel Log</h2>
            <span v-if="checks.log.log_size_kb" class="text-xs text-slate-400">
              (file: {{ checks.log.log_size_kb }} KB)
            </span>
          </div>
          <span :class="chipClass(checks.log.status)" class="text-xs font-semibold px-2 py-0.5 rounded">
            {{ statusLabel(checks.log.status) }}
          </span>
        </div>

        <div class="px-5 py-4">
          <template v-if="Array.isArray(checks.log.detail) && checks.log.detail.length">
            <p class="text-xs text-slate-500 mb-3">10 lỗi gần nhất (đọc từ cuối log):</p>
            <div class="space-y-2">
              <div v-for="(err, i) in checks.log.detail" :key="i"
                class="flex gap-3 p-3 rounded-lg border text-xs"
                :class="['CRITICAL','ALERT','EMERGENCY'].includes(err.level)
                  ? 'bg-orange-50 border-orange-200'
                  : 'bg-red-50 border-red-100'">
                <div class="flex-shrink-0 font-mono text-slate-500 w-36">{{ err.time }}</div>
                <div class="flex-1 min-w-0">
                  <span class="font-bold mr-2"
                    :class="['CRITICAL','ALERT','EMERGENCY'].includes(err.level) ? 'text-orange-700' : 'text-red-700'">
                    [{{ err.level }}]
                  </span>
                  <span class="text-slate-700 break-words">{{ err.message }}</span>
                </div>
              </div>
            </div>
          </template>
          <p v-else class="text-sm text-green-700">Không tìm thấy lỗi ERROR/CRITICAL gần đây trong log.</p>
        </div>
      </div>

    </div>
  </AppLayout>
</template>

<script setup>
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/Layout/AppLayout.vue'

defineProps({
  checks: Object,
})

const CHECK_TITLES = {
  environment: 'Môi trường',
  database:    'Database',
  migrations:  'Migration',
  storage:     'Thư mục',
  queue:       'Queue',
  frontend:    'Frontend',
  git:         'Git',
  deploy:      'Deploy',
  maintenance: 'Maintenance',
  log:         'Log',
}

function isObj(val) {
  return val !== null && typeof val === 'object' && !Array.isArray(val)
}

function statusLabel(status) {
  return { ok: 'OK', warning: 'Cảnh báo', error: 'Lỗi', info: 'Info' }[status] ?? status
}

function statusIcon(status) {
  return { ok: '✓', warning: '⚠', error: '✗', info: 'ℹ' }[status] ?? '?'
}

function dotClass(status) {
  return {
    ok:      'bg-green-500',
    warning: 'bg-yellow-400',
    error:   'bg-red-500',
    info:    'bg-blue-400',
  }[status] ?? 'bg-slate-400'
}

function chipClass(status) {
  return {
    ok:      'bg-green-100 text-green-700',
    warning: 'bg-yellow-100 text-yellow-700',
    error:   'bg-red-100 text-red-700',
    info:    'bg-blue-100 text-blue-700',
  }[status] ?? 'bg-slate-100 text-slate-600'
}

function badgeClass(status) {
  return {
    ok:      'bg-green-100 text-green-800',
    warning: 'bg-yellow-100 text-yellow-800',
    error:   'bg-red-100 text-red-800',
    info:    'bg-blue-100 text-blue-800',
  }[status] ?? 'bg-slate-100 text-slate-700'
}

function reload() {
  router.reload()
}
</script>
