// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  modules: ['@nuxtjs/tailwindcss', '@pinia/nuxt', '@nuxt/eslint'],
  // SPA: the session lives in an httpOnly cookie of the API origin, so pages are rendered client-side only.
  ssr: false,
  devtools: { enabled: true },
  app: {
    head: {
      htmlAttrs: { lang: 'pt-BR' },
      title: 'PulseBoard',
      meta: [
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
        { name: 'description', content: 'PulseBoard — métricas e gestão comercial.' },
        { name: 'theme-color', content: '#ffffff' },
      ],
      link: [{ rel: 'icon', type: 'image/x-icon', href: '/favicon.ico' }],
    },
  },
  css: ['@fontsource-variable/inter'],
  runtimeConfig: {
    public: {
      // Overridden by NUXT_PUBLIC_API_URL / NUXT_PUBLIC_API_ORIGIN
      apiUrl: 'http://localhost:8000/api/v1',
      apiOrigin: 'http://localhost:8000',
    },
  },
  compatibilityDate: '2025-07-15',
  eslint: {
    config: {
      stylistic: true,
    },
  },
  tailwindcss: {
    cssPath: '~/assets/css/main.css',
  },
})
