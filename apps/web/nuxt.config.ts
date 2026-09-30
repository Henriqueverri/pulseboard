// https://nuxt.com/docs/api/configuration/nuxt-config

// `nuxt generate` bakes the public runtime config into the HTML, so a Cloudflare Pages build
// without these variables would silently ship a bundle that calls localhost.
if (process.env.CF_PAGES && (!process.env.NUXT_PUBLIC_API_URL || !process.env.NUXT_PUBLIC_API_ORIGIN)) {
  throw new Error('NUXT_PUBLIC_API_URL and NUXT_PUBLIC_API_ORIGIN must be set in the Cloudflare Pages build environment.')
}

export default defineNuxtConfig({
  modules: ['@nuxtjs/tailwindcss', '@pinia/nuxt', '@nuxt/eslint'],
  // SPA: the session lives in an httpOnly cookie of the API origin, so pages are rendered client-side only.
  ssr: false,
  components: [
    // UI primitives are prefixed (<UiButton>); feature components use their file name (<KpiCard>).
    { path: '~/components/ui', prefix: 'Ui' },
    { path: '~/components', pathPrefix: false },
  ],
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
      // Overridden by NUXT_PUBLIC_API_URL / NUXT_PUBLIC_API_ORIGIN when running `nuxt generate`
      apiUrl: 'http://localhost:8000/api/v1',
      apiOrigin: 'http://localhost:8000',
    },
  },
  compatibilityDate: '2025-07-15',
  nitro: {
    preset: 'cloudflare_pages',
    prerender: {
      // Cloudflare Pages only falls back to the SPA shell (200 for deep links such as
      // /products/:id) when the output has no top-level 404.html.
      ignore: ['/404.html'],
    },
  },
  hooks: {
    'pages:extend'(pages) {
      if (process.env.NODE_ENV === 'production') {
        const index = pages.findIndex(page => page.path === '/dev/ui')
        if (index !== -1) {
          pages.splice(index, 1)
        }
      }
    },
  },
  eslint: {
    config: {
      stylistic: true,
    },
  },
  tailwindcss: {
    cssPath: '~/assets/css/main.css',
  },
})
