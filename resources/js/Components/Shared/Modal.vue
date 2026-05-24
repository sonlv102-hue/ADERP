<template>
  <Teleport to="body">
    <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0"
      enter-to-class="opacity-100" leave-active-class="transition ease-in duration-150"
      leave-from-class="opacity-100" leave-to-class="opacity-0">
      <div v-if="show" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4">
          <div class="fixed inset-0 bg-black/50" @click="$emit('close')" />
          <div :class="['relative bg-white rounded-xl shadow-xl w-full', maxWidthClass]">
            <!-- Header -->
            <div v-if="$slots.title" class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
              <h3 class="text-lg font-semibold text-gray-900">
                <slot name="title" />
              </h3>
              <button @click="$emit('close')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            <!-- Body -->
            <div class="px-6 py-4">
              <slot />
            </div>
            <!-- Footer -->
            <div v-if="$slots.footer" class="px-6 py-4 border-t border-gray-100 flex justify-end gap-3">
              <slot name="footer" />
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  show: Boolean,
  maxWidth: { type: String, default: 'lg' },
});

defineEmits(['close']);

const maxWidthClass = computed(() => ({
  sm: 'max-w-sm', md: 'max-w-md', lg: 'max-w-lg', xl: 'max-w-xl', '2xl': 'max-w-2xl',
}[props.maxWidth]));
</script>
