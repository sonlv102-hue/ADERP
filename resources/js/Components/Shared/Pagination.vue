<template>
  <div v-if="links.length > 3" class="flex items-center justify-between mt-4">
    <p class="text-sm text-gray-600">
      Hiển thị {{ meta.from }}–{{ meta.to }} / {{ meta.total }} kết quả
    </p>
    <div class="flex gap-1">
      <Link v-for="link in links" :key="link.label"
        :href="link.url ?? '#'"
        :class="[
          'px-3 py-1.5 text-sm rounded-lg border transition-colors',
          link.active ? 'bg-primary-600 text-white border-primary-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50',
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
