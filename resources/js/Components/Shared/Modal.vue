<template>
  <Teleport to="body">
    <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0"
      enter-to-class="opacity-100" leave-active-class="transition ease-in duration-150"
      leave-from-class="opacity-100" leave-to-class="opacity-0">
      <div v-if="show" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
        <!-- Backdrop -->
        <div class="fixed inset-0 bg-black/50" @click="$emit('close')" />

        <!-- Modal panel: bottom-sheet on mobile, centered on desktop -->
        <div :class="[
          'relative bg-white w-full shadow-xl flex flex-col',
          'rounded-t-2xl sm:rounded-xl',
          'max-h-[90vh] sm:max-h-[85vh]',
          smMaxWidthClass,
        ]">
          <!-- Header: sticky -->
          <div v-if="$slots.title"
            class="flex items-center justify-between px-4 sm:px-6 py-3 sm:py-4 border-b border-gray-100 flex-shrink-0">
            <h3 class="text-base sm:text-lg font-semibold text-gray-900 pr-4">
              <slot name="title" />
            </h3>
            <button @click="$emit('close')"
              class="text-gray-400 hover:text-gray-600 p-1 rounded-md hover:bg-gray-100 flex-shrink-0 min-w-[32px] min-h-[32px] flex items-center justify-center">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <!-- Body: scrollable -->
          <div class="px-4 sm:px-6 py-4 overflow-y-auto flex-1">
            <slot />
          </div>

          <!-- Footer: sticky -->
          <div v-if="$slots.footer"
            class="px-4 sm:px-6 py-3 sm:py-4 border-t border-gray-100 flex flex-wrap justify-end gap-2 sm:gap-3 flex-shrink-0">
            <slot name="footer" />
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

// sm: prefix so full-width on mobile, max-width on sm+
const smMaxWidthClass = computed(() => ({
  sm:  'sm:max-w-sm',
  md:  'sm:max-w-md',
  lg:  'sm:max-w-lg',
  xl:  'sm:max-w-xl',
  '2xl': 'sm:max-w-2xl',
}[props.maxWidth] ?? 'sm:max-w-lg'));
</script>
