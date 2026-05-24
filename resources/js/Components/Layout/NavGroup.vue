<template>
  <div class="mb-0.5">
    <button @click="open = !open"
      class="w-full flex items-center gap-2.5 px-3 py-2 mx-2 text-sm rounded-lg transition-colors"
      :class="matchesRoute ? 'text-white hover:bg-gray-700' : 'text-gray-400 hover:bg-gray-800 hover:text-white'">
      <component v-if="iconComponent" :is="iconComponent" class="w-4 h-4 flex-shrink-0" />
      <span class="flex-1 text-left font-medium">{{ label }}</span>
      <svg :class="['w-3.5 h-3.5 flex-shrink-0 transition-transform duration-200', open ? 'rotate-180' : '']"
        fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" />
      </svg>
    </button>
    <div v-show="open" class="mt-0.5 space-y-0.5">
      <slot />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { icons } from './navIcons';

const props = defineProps({ label: String, icon: String, prefix: String });

const page = usePage();
const matchesRoute = computed(() => props.prefix ? page.url.startsWith(props.prefix) : false);

// Auto-open if current route is inside this group; otherwise start collapsed
const open = ref(props.prefix ? matchesRoute.value : true);

const iconComponent = computed(() => props.icon ? (icons[props.icon] ?? null) : null);

// Auto-open when navigating into this group
watch(matchesRoute, (val) => { if (val) open.value = true; });
</script>
