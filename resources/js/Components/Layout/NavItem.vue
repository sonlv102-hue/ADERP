<template>
  <Link :href="href" :class="[
    'flex items-center gap-2.5 px-3 py-2 text-sm transition-colors rounded-lg mx-2',
    sub ? 'pl-10' : '',
    isActive ? 'bg-primary-600 text-white' : 'text-gray-300 hover:bg-gray-800 hover:text-white'
  ]">
    <component v-if="!sub && iconComponent" :is="iconComponent" class="w-4 h-4 flex-shrink-0" />
    <slot />
  </Link>
</template>

<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { icons } from './navIcons';

const props = defineProps({ href: String, icon: String, sub: Boolean });

const page = usePage();
const isActive = computed(() => page.url === props.href || page.url.startsWith(props.href + '/'));
const iconComponent = computed(() => props.icon ? (icons[props.icon] ?? null) : null);
</script>
