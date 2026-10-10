<script setup lang="ts">
import { computed } from 'vue'
import { useData, withBase } from 'vitepress'
import verifiedScreenshots from '../../../content/verified-screenshots.json'

const props = defineProps<{
  id: string
  title: string
  description?: string
  path?: string
  imageSrc?: string
}>()

const { lang } = useData()
const screenshots: Record<string, Partial<Record<'en' | 'ar', string>>> = verifiedScreenshots
// Authored imageSrc values are candidates, never approval. Only the verified registry may publish imagery.
const imageSource = computed(() => screenshots[props.id]?.[lang.value === 'ar' ? 'ar' : 'en'])
</script>

<template>
  <figure class="ierp-shot" :id="id">
    <div class="ierp-shot-frame">
      <div class="ierp-shot-chrome">
        <span></span><span></span><span></span>
        <strong>IERP</strong>
      </div>

      <img
        v-if="imageSource"
        class="ierp-shot-image"
        :src="withBase(imageSource)"
        :alt="title"
        loading="lazy"
      >

      <div v-else class="ierp-shot-reference" role="note" :aria-label="title">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M3 4.75A1.75 1.75 0 0 1 4.75 3h14.5A1.75 1.75 0 0 1 21 4.75v10.5A1.75 1.75 0 0 1 19.25 17H13v2h3v2H8v-2h3v-2H4.75A1.75 1.75 0 0 1 3 15.25zM5 5v10h14V5z"/>
        </svg>
        <div>
          <span>{{ lang === 'ar' ? 'لقطة شاشة للوحة التحكم' : 'Dashboard screen' }}</span>
          <strong>{{ title }}</strong>
          <small v-if="path">{{ path }}</small>
        </div>
      </div>
    </div>
    <figcaption>
      <strong>{{ title }}</strong>
      <span v-if="description">{{ description }}</span>
    </figcaption>
  </figure>
</template>
