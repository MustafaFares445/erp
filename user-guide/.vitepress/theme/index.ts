import DefaultTheme from 'vitepress/theme'
import type { Theme } from 'vitepress'
import IerpDocsLayout from './IerpDocsLayout.vue'
import IerpNavigationPath from './components/IerpNavigationPath.vue'
import IerpPageSummary from './components/IerpPageSummary.vue'
import IerpScreenshot from './components/IerpScreenshot.vue'
import IerpStatus from './components/IerpStatus.vue'
import IerpWorkflow from './components/IerpWorkflow.vue'
import './custom.css'

export default {
  extends: DefaultTheme,
  Layout: IerpDocsLayout,
  enhanceApp({ app }) {
    app.component('IerpNavigationPath', IerpNavigationPath)
    app.component('IerpPageSummary', IerpPageSummary)
    app.component('IerpScreenshot', IerpScreenshot)
    app.component('IerpStatus', IerpStatus)
    app.component('IerpWorkflow', IerpWorkflow)
  },
} satisfies Theme
