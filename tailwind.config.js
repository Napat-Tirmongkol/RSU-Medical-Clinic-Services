/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './**/*.php',
    './**/*.html',
    '!./archive/**',
    '!./vendor/**',
    '!./node_modules/**',
  ],
  theme: {
    extend: {
      // ── Brand colors ───────────────────────────────────────────
      colors: {
        // Primary brand (clinic green) — used by user-facing modules
        brand: {
          50:  '#ecfdf5',
          100: '#d1fae5',
          200: '#a7f3d0',
          300: '#6ee7b7',
          400: '#34d399',
          500: '#2e9e63',  // ← canonical brand color (matches user/hub)
          600: '#1f7a4d',
          700: '#155e3d',
          800: '#114a31',
          900: '#0d3a26',
        },
        // Secondary (admin / info) — keeps blue accents alive
        info: {
          50:  '#eff6ff',
          100: '#dbeafe',
          500: '#0052CC',
          600: '#003d99',
          700: '#002e80',
        },
        // Surface / canvas
        canvas: '#F8FAFF',
      },

      // ── Typography ─────────────────────────────────────────────
      fontFamily: {
        sans: ['RSU', 'Sarabun', 'system-ui', 'sans-serif'],
        prompt: ['Prompt', 'sans-serif'],
      },

      // ── Geometry ───────────────────────────────────────────────
      borderRadius: {
        'card': '1.5rem',     // standard card
        'card-lg': '2.5rem',  // hub-style premium card
        'pill': '9999px',
      },
      boxShadow: {
        'card': '0 10px 30px rgba(0,0,0,0.04)',
        'card-lg': '0 20px 50px rgba(0,0,0,0.04)',
        'glow-brand': '0 15px 30px rgba(46,158,99,0.25)',
        'glow-info':  '0 15px 30px rgba(0,82,204,0.25)',
      },
    },
  },
  plugins: [
    require('tailwindcss-animate'),
  ],
}
