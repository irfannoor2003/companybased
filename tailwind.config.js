import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },

            colors: {
                primary: 'rgb(var(--color-primary) / <alpha-value>)',
                'primary-strong': 'rgb(var(--color-primary-strong) / <alpha-value>)',
                accent: 'rgb(var(--color-accent) / <alpha-value>)',
                surface: 'rgb(var(--color-surface) / <alpha-value>)',
                'surface-muted': 'rgb(var(--color-surface-muted) / <alpha-value>)',
                canvas: 'rgb(var(--color-canvas) / <alpha-value>)',
                ink: 'rgb(var(--color-ink) / <alpha-value>)',
                'ink-soft': 'rgb(var(--color-ink-soft) / <alpha-value>)',
                'ink-faint': 'rgb(var(--color-ink-faint) / <alpha-value>)',
                line: 'rgb(var(--color-line) / <alpha-value>)',
            },

            boxShadow: {
                card: '0 1px 2px 0 rgb(0 0 0 / 0.04), 0 1px 3px 0 rgb(0 0 0 / 0.06)',
                lift: '0 10px 30px -12px rgb(0 0 0 / 0.18)',
                modal: '0 20px 50px -12px rgb(0 0 0 / 0.28)',
            },

            borderRadius: {
                xl2: '1.25rem',
            },

            keyframes: {
                'fade-in': {
                    '0%': { opacity: '0' },
                    '100%': { opacity: '1' },
                },
                'slide-in-right': {
                    '0%': { transform: 'translateX(100%)' },
                    '100%': { transform: 'translateX(0)' },
                },
                'slide-in-up': {
                    '0%': { transform: 'translateY(12px); opacity: 0' },
                    '100%': { transform: 'translateY(0); opacity: 1' },
                },
                'slide-down': {
                    '0%': { transform: 'translateY(-4px); opacity: 0' },
                    '100%': { transform: 'translateY(0); opacity: 1' },
                },
                'pop-in': {
                    '0%': { transform: 'scale(0.96); opacity: 0' },
                    '100%': { transform: 'scale(1); opacity: 1' },
                },
            },

            animation: {
                'fade-in': 'fade-in 0.2s ease-out',
                'slide-in-right': 'slide-in-right 0.25s cubic-bezier(0.16, 1, 0.3, 1)',
                'slide-in-up': 'slide-in-up 0.3s cubic-bezier(0.16, 1, 0.3, 1)',
                'slide-down': 'slide-down 0.15s ease-out',
                'pop-in': 'pop-in 0.2s cubic-bezier(0.16, 1, 0.3, 1)',
            },
        },
    },

    plugins: [forms],
};
