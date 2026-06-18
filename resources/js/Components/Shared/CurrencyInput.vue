<template>
  <div class="relative">
    <input
      ref="inputRef"
      v-model="displayValue"
      type="text"
      inputmode="numeric"
      :placeholder="placeholder"
      :disabled="disabled"
      class="w-full rounded-lg border bg-white px-3 py-2 text-sm text-right font-mono transition-colors outline-none
             placeholder:text-slate-400 focus:ring-2
             disabled:bg-slate-50 disabled:text-slate-400 disabled:cursor-not-allowed"
      :class="hasError
        ? 'border-red-400 focus:border-red-400 focus:ring-red-100/40'
        : 'border-slate-300 focus:border-primary-500 focus:ring-primary-500/20'"
      @focus="onFocus"
      @blur="onBlur"
      @keydown.up.prevent="step(1000)"
      @keydown.down.prevent="step(-1000)"
    />
    <span
      v-if="!isFocused && numericValue != null"
      class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 pointer-events-none"
    >₫</span>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
  modelValue:  { type: Number, default: null },
  placeholder: { type: String, default: '0' },
  disabled:    { type: Boolean, default: false },
  hasError:    { type: Boolean, default: false },
  min:         { type: Number, default: null },
  max:         { type: Number, default: null },
})

const emit = defineEmits(['update:modelValue'])

const inputRef    = ref(null)
const isFocused   = ref(false)
const displayValue = ref('')

const numericValue = ref(props.modelValue)

function format(val) {
  if (val == null || val === '' || isNaN(val)) return ''
  return Number(val).toLocaleString('vi-VN')
}

function parse(str) {
  const cleaned = String(str).replace(/[^\d]/g, '')
  if (!cleaned) return null
  const n = parseInt(cleaned, 10)
  if (props.min != null && n < props.min) return props.min
  if (props.max != null && n > props.max) return props.max
  return n
}

watch(() => props.modelValue, (val) => {
  numericValue.value = val
  if (!isFocused.value) {
    displayValue.value = format(val)
  }
}, { immediate: true })

function onFocus() {
  isFocused.value = true
  displayValue.value = numericValue.value != null ? String(numericValue.value) : ''
  inputRef.value?.select()
}

function onBlur() {
  isFocused.value = false
  const parsed = parse(displayValue.value)
  numericValue.value = parsed
  displayValue.value = format(parsed)
  emit('update:modelValue', parsed)
}

function step(delta) {
  const current = numericValue.value ?? 0
  const next = current + delta
  numericValue.value = props.min != null ? Math.max(props.min, next) : next
  displayValue.value = String(numericValue.value)
  emit('update:modelValue', numericValue.value)
}
</script>
