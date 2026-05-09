/** @type {import('tailwindcss').Config} */
import defaultTheme from 'tailwindcss/defaultTheme';

export default {
  content: [
    './templates/**/*.html.twig',
    './assets/**/*.{ts,tsx,js,jsx}',
    './design/**/*.html',
  ],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        // Brand: deep teal — trustworthy, fintech, distinct from generic SaaS blue
        brand: {
          50:  '#f0fdfa',
          100: '#ccfbf1',
          200: '#99f6e4',
          300: '#5eead4',
          400: '#2dd4bf',
          500: '#14b8a6',
          600: '#0d9488', // primary action
          700: '#0f766e',
          800: '#115e59',
          900: '#134e4a',
          950: '#042f2e',
        },

        // Semantic surface tokens — used in components, not raw hex
        surface: {
          DEFAULT: '#ffffff',
          subtle:  '#f8fafc', // slate-50
          muted:   '#f1f5f9', // slate-100
          inverse: '#0f172a', // slate-900
        },

        // Status colors — paired light/dark, restrained usage
        success: { 50: '#ecfdf5', 500: '#10b981', 600: '#059669', 700: '#047857' },
        warning: { 50: '#fffbeb', 500: '#f59e0b', 600: '#d97706', 700: '#b45309' },
        danger:  { 50: '#fef2f2', 500: '#ef4444', 600: '#dc2626', 700: '#b91c1c' },
        info:    { 50: '#eff6ff', 500: '#3b82f6', 600: '#2563eb', 700: '#1d4ed8' },
      },

      fontFamily: {
        sans:    ['Inter',         'ui-sans-serif',  ...defaultTheme.fontFamily.sans],
        display: ['"Inter Display"', 'Inter',        ...defaultTheme.fontFamily.sans],
        mono:    ['"JetBrains Mono"', 'ui-monospace', ...defaultTheme.fontFamily.mono],
      },

      fontSize: {
        '2xs': ['0.6875rem', { lineHeight: '1rem' }],
      },

      // Slightly softer than default — friendlier without being playful
      borderRadius: {
        xl:  '0.875rem',
        '2xl': '1.125rem',
        '3xl': '1.5rem',
      },

      // Layered shadow scale — fintech card feel
      boxShadow: {
        'xs':         '0 1px 2px rgba(15,23,42,0.04)',
        'card':       '0 1px 2px rgba(15,23,42,0.04), 0 4px 12px rgba(15,23,42,0.04)',
        'card-hover': '0 2px 4px rgba(15,23,42,0.06), 0 8px 24px rgba(15,23,42,0.08)',
        'pop':        '0 8px 24px rgba(15,23,42,0.10), 0 24px 48px rgba(15,23,42,0.08)',
        'ring-brand': '0 0 0 4px rgba(13,148,136,0.18)',
      },

      // Spacing rhythm: 4/8 system — explicit named values for layout consistency
      spacing: {
        '18': '4.5rem',
      },

      // Type scale — semantic, scaled for hierarchy
      letterSpacing: {
        tight:    '-0.015em',
        tighter:  '-0.025em',
        tightest: '-0.04em',
      },

      // Motion tokens — match CLAUDE.md rules: 150ms hover/focus, 240ms layout
      transitionDuration: {
        '150': '150ms',
        '240': '240ms',
      },
      transitionTimingFunction: {
        'out-expo': 'cubic-bezier(0.16, 1, 0.3, 1)',
      },

      animation: {
        'fade-in':   'fadeIn 200ms ease-out',
        'slide-up':  'slideUp 240ms cubic-bezier(0.16, 1, 0.3, 1)',
        'pulse-dot': 'pulseDot 2s ease-in-out infinite',
      },
      keyframes: {
        fadeIn:   { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
        slideUp:  { '0%': { opacity: '0', transform: 'translateY(8px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
        pulseDot: { '0%, 100%': { opacity: '1' }, '50%': { opacity: '0.4' } },
      },

      // Background utilities for hero subtle decoration
      backgroundImage: {
        'grid-slate': 'linear-gradient(to right, rgba(15,23,42,0.04) 1px, transparent 1px), linear-gradient(to bottom, rgba(15,23,42,0.04) 1px, transparent 1px)',
        'grid-slate-dark': 'linear-gradient(to right, rgba(255,255,255,0.04) 1px, transparent 1px), linear-gradient(to bottom, rgba(255,255,255,0.04) 1px, transparent 1px)',
        'mesh-brand': 'radial-gradient(at 20% 30%, rgba(45,212,191,0.18) 0px, transparent 50%), radial-gradient(at 80% 20%, rgba(13,148,136,0.14) 0px, transparent 50%), radial-gradient(at 60% 80%, rgba(94,234,212,0.10) 0px, transparent 50%)',
      },
      backgroundSize: {
        'grid-32': '32px 32px',
      },
    },
  },
  plugins: [],
};
