<template>
  <header class="sticky top-0 z-40 bg-white/95 backdrop-blur-sm border-b border-slate-200/80 shadow-sm h-14 flex items-center px-4 lg:px-6 gap-4">
    <button @click="$emit('toggle-sidebar')" class="text-slate-500 hover:text-slate-700 p-1.5 rounded-md hover:bg-slate-100">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
      </svg>
    </button>

    <div class="flex-1" />

    <!-- Notifications -->
    <NotificationDropdown />

    <!-- User menu -->
    <div class="relative" ref="menuRef">
      <button @click="menuOpen = !menuOpen"
        class="flex items-center gap-2 text-sm text-slate-700 hover:text-slate-900 px-2 py-1.5 rounded-lg hover:bg-slate-100">
        <div class="w-8 h-8 bg-primary-600 rounded-full flex items-center justify-center text-white font-semibold text-xs">
          {{ initials }}
        </div>
        <span class="hidden sm:block font-medium">{{ auth.user?.name }}</span>
        <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      <div v-if="menuOpen"
        class="absolute right-0 top-full mt-1 w-48 bg-white rounded-xl shadow-lg border border-slate-200 py-1 z-50">
        <div class="px-4 py-2 border-b border-slate-100">
          <p class="text-sm font-medium text-slate-900">{{ auth.user?.name }}</p>
          <p class="text-xs text-slate-500">{{ auth.roles?.[0] }}</p>
        </div>
        <Link :href="route('logout')" method="post" as="button"
          class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50 flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
          </svg>
          Đăng xuất
        </Link>
      </div>
    </div>
  </header>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import NotificationDropdown from '@/Components/Shared/NotificationDropdown.vue';

defineEmits(['toggle-sidebar']);

const page = usePage();
const auth = computed(() => page.props.auth ?? {});
const initials = computed(() => {
  const name = auth.value.user?.name ?? '';
  return name.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase();
});

const menuOpen = ref(false);
const menuRef = ref(null);

const handleClickOutside = (e) => {
  if (menuRef.value && !menuRef.value.contains(e.target)) menuOpen.value = false;
};

onMounted(() => document.addEventListener('click', handleClickOutside));
onBeforeUnmount(() => document.removeEventListener('click', handleClickOutside));
</script>
