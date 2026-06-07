<template>
  <div :class="wrapClass">
    <label
      v-if="label"
      class="mb-1.5 flex items-center gap-1 text-sm font-medium leading-none text-gray-700"
    >
      {{ label }}
      <span v-if="required" class="text-red-500" aria-hidden="true">*</span>
      <span v-if="optional" class="ml-0.5 text-xs font-normal text-gray-400">(tùy chọn)</span>
    </label>
    <slot />
    <p v-if="error" class="mt-1.5 flex items-start gap-1 text-xs text-red-600" role="alert">
      <svg class="mt-px h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path fill-rule="evenodd"
          d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
          clip-rule="evenodd" />
      </svg>
      {{ error }}
    </p>
    <p v-else-if="hint" class="mt-1 text-xs leading-relaxed text-gray-400">{{ hint }}</p>
  </div>
</template>

<script setup>
defineProps({
  label:     { type: String,  default: ''    },
  required:  { type: Boolean, default: false  },
  optional:  { type: Boolean, default: false  },
  error:     { type: String,  default: ''    },
  hint:      { type: String,  default: ''    },
  wrapClass: { type: String,  default: ''    },
});
</script>
