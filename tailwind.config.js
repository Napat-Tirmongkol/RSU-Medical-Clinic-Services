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
        // Accent — vibrant fuchsia for "bold" personality
        accent: {
          50:  '#fdf4ff',
          100: '#fae8ff',
          400: '#e879f9',
          500: '#d946ef',
          600: '#c026d3',
          700: '#a21caf',
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
        'card':       '0 10px 30px rgba(0,0,0,0.04)',
        'card-lg':    '0 20px 50px rgba(0,0,0,0.04)',
        'glow-brand': '0 18px 40px -8px rgba(46,158,99,0.45)',
        'glow-info':  '0 18px 40px -8px rgba(0,82,204,0.40)',
        'glow-accent':'0 18px 40px -8px rgba(217,70,239,0.45)',
        'glow-amber': '0 18px 40px -8px rgba(245,158,11,0.45)',
        'glow-rose':  '0 18px 40px -8px rgba(244,63,94,0.45)',
        'lift':       '0 24px 50px -16px rgba(15,23,42,0.18)',
      },
      backgroundImage: {
        'brand-gradient':  'linear-gradient(135deg, #2e9e63 0%, #3bba7a 60%, #6ee7b7 100%)',
        'sunset-gradient': 'linear-gradient(135deg, #f43f5e 0%, #f59e0b 100%)',
        'royal-gradient':  'linear-gradient(135deg, #6366f1 0%, #d946ef 100%)',
        'ocean-gradient':  'linear-gradient(135deg, #0ea5e9 0%, #14b8a6 100%)',
      },
    },
  },
  plugins: [
    require('tailwindcss-animate'),
  ],
}
