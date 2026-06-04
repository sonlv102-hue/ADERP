<template>
  <div v-if="links.length > 3" class="flex items-center justify-between mt-4">
    <p class="text-sm text-slate-500">
      Hiển thị {{ meta.from }}–{{ meta.to }} / {{ meta.total }} kết quả
    </p>
    <div class="flex gap-1">
      <Link v-for="link in links" :key="link.label"
        :href="link.url ?? '#'"
        :class="[
          'px-3 py-1.5 text-sm rounded-lg border transition-colors',
          link.active ? 'bg-primary-600 text-white border-primary-600 shadow-sm' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50 hover:border-slate-400',
          !link.url ? 'opacity-40 pointer-events-none' : '',
        ]"
        v-html="link.label"
        preserve-scroll
      />
    </div>
  </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
defineProps({
  links: { type: Array, default: () => [] },
  meta: { type: Object, default: () => ({}) },
});
</script>
