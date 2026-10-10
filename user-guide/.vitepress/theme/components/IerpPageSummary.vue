<script setup lang="ts">
import { useData } from 'vitepress'

defineProps<{
  purpose: string
  useWhen?: string
  owner?: string
}>()

const { lang } = useData()
</script>

<template>
  <div class="ierp-summary-grid">
    <div class="ierp-card">
      <span class="ierp-eyebrow">{{ lang === 'ar' ? 'الغرض' : 'Purpose' }}</span>
      <strong>{{ purpose }}</strong>
    </div>
    <div v-if="useWhen" class="ierp-card">
      <span class="ierp-eyebrow">{{ lang === 'ar' ? 'متى تستخدمها؟' : 'Use when' }}</span>
      <strong>{{ useWhen }}</strong>
    </div>
    <div v-if="owner" class="ierp-card">
      <span class="ierp-eyebrow">{{ lang === 'ar' ? 'الوحدة المالكة' : 'Business owner' }}</span>
      <strong>{{ owner }}</strong>
    </div>
  </div>
</template>
