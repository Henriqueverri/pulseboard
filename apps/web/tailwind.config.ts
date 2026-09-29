import type { Config } from 'tailwindcss'
import defaultTheme from 'tailwindcss/defaultTheme'

function token(name: string) {
  return `rgb(var(--pb-${name}) / <alpha-value>)`
}

export default {
  content: [
    './app/**/*.{vue,ts}',
  ],
  theme: {
    extend: {
      colors: {
        ink: token('ink'),
        canvas: token('canvas'),
        surface: token('surface'),
        brand: {
          50: token('brand-50'),
          100: token('brand-100'),
          200: token('brand-200'),
          300: token('brand-300'),
          400: token('brand-400'),
          500: token('brand-500'),
          600: token('brand-600'),
          700: token('brand-700'),
        },
        success: {
          DEFAULT: token('success'),
          soft: token('success-soft'),
          strong: token('success-strong'),
        },
        warning: {
          DEFAULT: token('warning'),
          soft: token('warning-soft'),
          strong: token('warning-strong'),
        },
        info: {
          DEFAULT: token('info'),
          soft: token('info-soft'),
          strong: token('info-strong'),
        },
        danger: {
          DEFAULT: token('danger'),
          soft: token('danger-soft'),
          strong: token('danger-strong'),
        },
        purple: token('purple'),
      },
      fontFamily: {
        sans: ['"Inter Variable"', 'Inter', ...defaultTheme.fontFamily.sans],
      },
      borderRadius: {
        'sm': '4px',
        'DEFAULT': '6px',
        'md': '8px',
        'lg': '12px',
        'xl': '16px',
        '2xl': '20px',
        '3xl': '24px',
      },
      boxShadow: {
        pop: '0 12px 32px -8px rgb(0 0 0 / 0.12), 0 0 0 1px rgb(0 0 0 / 0.05)',
        soft: '0 1px 2px rgb(0 0 0 / 0.04)',
      },
      spacing: {
        sidebar: '240px',
        rail: '64px',
      },
    },
  },
} satisfies Config
