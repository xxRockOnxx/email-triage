/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
    './storage/framework/views/*.php',
    './resources/views/**/*.blade.php',
    './resources/js/**/*.vue',
  ],
  theme: {
    extend: {
      colors: {
        bg: 'var(--color-bg)',
        surface: 'var(--color-surface)',
        'surface-sunken': 'var(--color-surface-sunken)',
        border: 'var(--color-border)',
        ink: 'var(--color-ink)',
        'ink-soft': 'var(--color-ink-soft)',
        'ink-faint': 'var(--color-ink-faint)',
        accent: 'var(--color-accent)',
        'accent-soft': 'var(--color-accent-soft)',
        urgency: {
          low: 'var(--color-urgency-low)',
          medium: 'var(--color-urgency-medium)',
          high: 'var(--color-urgency-high)',
          critical: 'var(--color-urgency-critical)',
          'low-bg': 'var(--color-urgency-low-bg)',
          'medium-bg': 'var(--color-urgency-medium-bg)',
          'high-bg': 'var(--color-urgency-high-bg)',
          'critical-bg': 'var(--color-urgency-critical-bg)',
        },
      },
      fontFamily: {
        sans: ['Inter', 'sans-serif'],
        mono: ['JetBrains Mono', 'monospace'],
      },
    },
  },
  plugins: [],
};
