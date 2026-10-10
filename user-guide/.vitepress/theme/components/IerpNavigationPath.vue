<script setup lang="ts">
defineProps<{ items: string[] }>()
</script>

<template>
  <nav class="ierp-nav-path" aria-label="Dashboard navigation path">
    <template v-for="(item, index) in items" :key="item + index">
      <span>{{ item }}</span>
      <svg v-if="index < items.length - 1" viewBox="0 0 20 20" aria-hidden="true">
        <path d="m7.5 5 5 5-5 5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </template>
  </nav>
</template>
