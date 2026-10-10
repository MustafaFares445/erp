<script setup lang="ts">
import DefaultTheme from 'vitepress/theme'
import { useData } from 'vitepress'

const { lang } = useData()
</script>

<template>
  <DefaultTheme.Layout>
    <template #sidebar-nav-before>
      <div class="ierp-docs-sidebar-brand">
        <div class="ierp-docs-sidebar-brand__mark">I</div>
        <div class="ierp-docs-sidebar-brand__copy">
          <strong>IERP</strong>
          <span>{{ lang === 'ar' ? 'دليل الاستخدام' : 'User Guide' }}</span>
        </div>
      </div>
      <div class="ierp-docs-sidebar-hint">
        {{ lang === 'ar' ? 'اختر وحدة ثم افتح الصفحة أو مسار العمل المطلوب.' : 'Choose a module, then open the screen or workflow you need.' }}
      </div>
    </template>
  </DefaultTheme.Layout>
</template>
