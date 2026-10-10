<script setup lang="ts">
withDefaults(defineProps<{
  tone?: 'primary' | 'success' | 'warning' | 'danger' | 'neutral' | 'info'
}>(), { tone: 'neutral' })
</script>

<template>
  <span class="ierp-status" :data-tone="tone"><i></i><slot /></span>
</template>
