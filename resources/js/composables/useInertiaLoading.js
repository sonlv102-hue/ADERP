import { ref, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';

export function useInertiaLoading() {
  const isLoading = ref(false);

  const removeStart  = router.on('start',  () => { isLoading.value = true; });
  const removeFinish = router.on('finish', () => { isLoading.value = false; });

  onUnmounted(() => {
    removeStart();
    removeFinish();
  });

  return { isLoading };
}
