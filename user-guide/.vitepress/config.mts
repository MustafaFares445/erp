import { defineConfig } from 'vitepress'
import { enSidebar } from './sidebar/en'
import { arSidebar } from './sidebar/ar'

const enNav = [
  { text: 'Home', link: '/' },
  { text: 'Getting Started', link: '/getting-started/' },
  { text: 'Workflows', link: '/workflows/' },
  {
    text: 'Modules',
    items: [
      { text: 'Sales', link: '/screens/sales/' },
      { text: 'Accounting', link: '/screens/accounting/' },
      { text: 'Inventory', link: '/screens/inventory/' },
      { text: 'Purchasing', link: '/screens/vendors/' },
      { text: 'CRM', link: '/screens/crm/' },
      { text: 'Employees', link: '/screens/employees/' },
      { text: 'Support & Maintenance', link: '/screens/support/' },
      { text: 'Reports', link: '/screens/reports/' },
      { text: 'Administration', link: '/screens/system/' },
    ],
  },
]

const arNav = [
  { text: 'الرئيسية', link: '/ar/' },
  { text: 'البدء', link: '/ar/getting-started/' },
  { text: 'مسارات العمل', link: '/ar/workflows/' },
  {
    text: 'الوحدات',
    items: [
      { text: 'المبيعات', link: '/ar/screens/sales/' },
      { text: 'المحاسبة', link: '/ar/screens/accounting/' },
      { text: 'المخزون', link: '/ar/screens/inventory/' },
      { text: 'المشتريات', link: '/ar/screens/vendors/' },
      { text: 'إدارة علاقات العملاء', link: '/ar/screens/crm/' },
      { text: 'الموظفون', link: '/ar/screens/employees/' },
      { text: 'الدعم والصيانة', link: '/ar/screens/support/' },
      { text: 'التقارير', link: '/ar/screens/reports/' },
      { text: 'الإدارة', link: '/ar/screens/system/' },
    ],
  },
]

const arThemeConfig = {
  nav: arNav,
  sidebar: arSidebar,
  outline: {
    level: [2, 3] as [number, number],
  },
  outlineTitle: 'في هذه الصفحة',
  lastUpdatedText: 'آخر تحقق',
  docFooter: {
    prev: 'السابق',
    next: 'التالي',
  },
  returnToTopLabel: 'العودة للأعلى',
  sidebarMenuLabel: 'القائمة',
  langMenuLabel: 'تغيير اللغة',
  skipToContentLabel: 'الانتقال إلى المحتوى',
  darkModeSwitchLabel: 'المظهر',
  lightModeSwitchTitle: 'استخدام الوضع الفاتح',
  darkModeSwitchTitle: 'استخدام الوضع الداكن',
  footer: {
    message: 'توثيق التشغيل لنظام IERP',
    copyright: 'مبني من هيكل لوحة التحكم الحالي ووثائق العمل المعتمدة',
  },
}

export default defineConfig({
  title: 'IERP User Guide',
  description: 'Complete operational documentation for the IERP administration dashboard',
  base: '/guide/',
  cleanUrls: true,
  srcExclude: ['content/overrides/**'],
  lastUpdated: true,
  outDir: '../public/guide',
  head: [
    ['meta', { name: 'theme-color', content: '#2563eb' }],
    ['link', { rel: 'preconnect', href: 'https://fonts.bunny.net' }],
    ['link', { rel: 'stylesheet', href: 'https://fonts.bunny.net/css?family=noto-sans-arabic:400,500,600,700' }],
  ],
  locales: {
    root: {
      label: 'English',
      lang: 'en',
      title: 'IERP User Guide',
      description: 'Complete operational documentation for the IERP administration dashboard',
    },
    ar: {
      label: 'العربية',
      lang: 'ar',
      dir: 'rtl',
      link: '/ar/',
      title: 'دليل استخدام IERP',
      description: 'التوثيق التشغيلي الشامل للوحة تحكم IERP',
      themeConfig: arThemeConfig,
    },
  },
  themeConfig: {
    search: {
      provider: 'local',
      options: {
        locales: {
          ar: {
            translations: {
              button: {
                buttonText: 'بحث',
                buttonAriaLabel: 'البحث في التوثيق',
              },
              modal: {
                displayDetails: 'عرض التفاصيل',
                resetButtonTitle: 'مسح البحث',
                backButtonTitle: 'إغلاق البحث',
                noResultsText: 'لا توجد نتائج لـ',
                footer: {
                  selectText: 'اختيار',
                  selectKeyAriaLabel: 'Enter للاختيار',
                  navigateText: 'تنقل',
                  navigateUpKeyAriaLabel: 'سهم للأعلى',
                  navigateDownKeyAriaLabel: 'سهم للأسفل',
                  closeText: 'إغلاق',
                  closeKeyAriaLabel: 'Escape للإغلاق',
                },
              },
            },
          },
        },
      },
    },
  appearance: true,
    socialLinks: [],
    nav: enNav,
    sidebar: enSidebar,
    outline: {
      level: [2, 3],
    },
    outlineTitle: 'On this page',
    lastUpdatedText: 'Last verified',
    docFooter: {
      prev: 'Previous',
      next: 'Next',
    },
    returnToTopLabel: 'Back to top',
    sidebarMenuLabel: 'Menu',
    darkModeSwitchLabel: 'Appearance',
    lightModeSwitchTitle: 'Use light theme',
    darkModeSwitchTitle: 'Use dark theme',
    footer: {
      message: 'IERP operational documentation',
      copyright: 'Generated from the current dashboard structure and verified business documentation',
    },
  },
})
