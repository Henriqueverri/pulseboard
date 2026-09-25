// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },
  modules: ['@nuxtjs/tailwindcss', '@pinia/nuxt'],
  runtimeConfig: {
    public: {
      // Overridden by NUXT_PUBLIC_API_URL / NUXT_PUBLIC_API_ORIGIN
      apiUrl: 'http://localhost:8000/api/v1',
      apiOrigin: 'http://localhost:8000',
    },
  },
})
