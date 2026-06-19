<template>
  <div class="mb-0.5">
    <button @click="toggle"
      class="w-full flex items-center gap-2.5 px-3 py-2.5 mx-2 text-sm rounded-lg transition-colors min-h-[44px]"
      :class="matchesRoute ? 'text-white hover:bg-slate-700/60' : 'text-slate-400 hover:bg-slate-700/60 hover:text-white'">
      <component v-if="iconComponent" :is="iconComponent" class="w-4 h-4 flex-shrink-0" />
      <span class="flex-1 text-left font-medium">{{ label }}</span>
      <svg :class="['w-3.5 h-3.5 flex-shrink-0 transition-transform duration-200', isOpen ? 'rotate-180' : '']"
        fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" />
      </svg>
    </button>
    <div v-show="isOpen" class="mt-0.5 space-y-0.5">
      <slot />
    </div>
  </div>
</template>

<script setup>
import { computed, inject, onMounted, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { icons } from './navIcons';

const props = defineProps({ label: String, icon: String, prefix: String });

const page        = usePage();
const matchesRoute = computed(() => props.prefix ? page.url.startsWith(props.prefix) : false);
const iconComponent = computed(() => props.icon ? (icons[props.icon] ?? null) : null);

// Accordion context provided by Sidebar.vue
const ctx = inject('sidebarAccordion', null);

// Open = either this group's prefix is the accordion's active prefix,
// or (fallback without context) matches current route
const isOpen = computed(() => {
  if (ctx) return ctx.openPrefix.value === props.prefix;
  return matchesRoute.value;
});

function toggle() {
  if (ctx) {
    ctx.setOpen(isOpen.value ? null : props.prefix);
  }
}

// Auto-open when navigating into this group's section
onMounted(() => {
  if (matchesRoute.value && ctx) ctx.setOpen(props.prefix);
});

watch(matchesRoute, (val) => {
  if (val && ctx) ctx.setOpen(props.prefix);
});
</script>
