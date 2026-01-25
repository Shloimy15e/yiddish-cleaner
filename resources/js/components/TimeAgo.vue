<script setup lang="ts">
import { ref, computed } from 'vue';
import { formatTimeAgo, formatDateTime } from '@/lib/date';

const props = defineProps<{
  value: string | Date;
  class?: string;
}>();

const dateString = computed(() => {
  if (props.value instanceof Date) return props.value.toISOString();
  return props.value;
});

const timeAgo = computed(() => formatTimeAgo(dateString.value));
const exact = computed(() => formatDateTime(dateString.value, {
    dateStyle: 'long',
    timeStyle: 'short',
}));
</script>

<template>
  <span
    :class="props.class"
    v-tippy="{ content: exact, placement: 'top', theme: 'light-border' }"
  >
    {{ timeAgo }}
  </span>
</template>

<style scoped>
</style>
