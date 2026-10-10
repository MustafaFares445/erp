<script setup lang="ts">
import { useData } from 'vitepress'

const { lang } = useData()
type StepState = 'complete' | 'current' | 'pending' | 'blocked' | 'optional'
type WorkflowStep = string | { label: string; state?: StepState }

const props = withDefaults(defineProps<{
  steps: WorkflowStep[]
  active?: number
}>(), {
  active: 0,
})

const labelFor = (step: WorkflowStep): string => typeof step === 'string' ? step : step.label
const stateFor = (step: WorkflowStep, index: number): StepState => {
  if (typeof step !== 'string' && step.state) return step.state
  if (index < props.active) return 'complete'
  if (index === props.active) return 'current'
  return 'pending'
}
</script>

<template>
  <ol class="ierp-stepper" :aria-label="lang === 'ar' ? 'خطوات مسار العمل' : 'Workflow steps'" tabindex="0">
    <li
      v-for="(step, index) in steps"
      :key="String(index) + '-' + labelFor(step)"
      class="ierp-stepper-step"
      :data-state="stateFor(step, index)"
    >
      <span class="ierp-stepper-marker" aria-hidden="true">{{ index + 1 }}</span>
      <span class="ierp-stepper-label">{{ labelFor(step) }}</span>
    </li>
  </ol>
</template>
