import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import ovsTokens from './resources/js/ovs/tokens.generated.js';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.tsx',
        './resources/js/**/*.ts',
    ],

    theme: {
    	container: {
    		center: true,
    		padding: '2rem',
    		screens: {
    			'2xl': '1400px'
    		}
    	},
    	extend: {
    		fontFamily: {
    			sans: [
    				'Inter',
                    ...defaultTheme.fontFamily.sans
                ],
                display: [
                    'Plus Jakarta Sans',
                    'Outfit',
                    ...defaultTheme.fontFamily.sans
                ],
                mono: [
                    'Geist Mono',
                    'JetBrains Mono',
                    ...defaultTheme.fontFamily.mono
                ],
                ...ovsTokens.fontFamily,
    		},
    		colors: {
                // OVS Generated Tokens
                ...ovsTokens.colors,
                // Clinic Portal palette: the CRM visual language's values,
                // owned by the portal (resources/js/clinic) and independent of
                // any OVS token. `secondary` is the smallest text allowed on
                // `canvas`; `muted` passes AA on `surface` only.
                clinic: {
                    canvas: '#F4F6F8',
                    surface: '#FFFFFF',
                    inset: '#EEF1F4',
                    ink: '#0B0B0F',
                    'ink-soft': '#2A2D35',
                    secondary: '#565F73',
                    muted: '#6B7385',
                    border: '#E4E7EC',
                    'border-strong': '#CDD2DA',
                    brand: '#12A0B5',
                    'brand-strong': '#0A7181',
                    'brand-soft': '#E4F5F8',
                    danger: '#B42318',
                    'danger-soft': '#FEF3F2',
                    warning: '#93370D',
                    'warning-soft': '#FFFAEB',
                    success: '#067647',
                    'success-soft': '#ECFDF3',
                    'success-border': '#93EFBE',
                    'warning-border': '#FAD79A',
                    line: '#ECEEF2',
                    // Decorative accents (identity, tabs, highlights), not states.
                    'accent-teal': '#4A9DAB',
                    'accent-teal-soft': '#DFF1F4',
                    'accent-teal-border': '#A2D4DD',
                    'accent-violet': '#8B7FE8',
                    'accent-violet-soft': '#EAE6FD',
                    'accent-violet-border': '#C3BAF6',
                    'accent-coral': '#E8593C',
                    'accent-coral-soft': '#FDE6E0',
                    'accent-coral-text': '#A32E17',
                    'accent-yellow-soft': '#FEF6D8',
                    'accent-yellow-text': '#8A6A05',
                },
                // The rebuilt Clinic Portal (2026-10): navy brand, cyan accent,
                // pastel metric tones (each `-bg` paired with its `-ink`, which
                // passes WCAG AA on it). The `clinic.*` tokens above stay as
                // they are for the protected Login.
                cx: {
                    navy: '#0B1F3A',
                    'navy-800': '#122B4D',
                    'navy-700': '#1C3B66',
                    'navy-soft': '#E9EEF6',
                    cyan: '#0EA5B7',
                    'cyan-strong': '#0B7A88',
                    'cyan-soft': '#E2F6F8',
                    canvas: '#F4F6FA',
                    surface: '#FFFFFF',
                    line: '#E5E9F0',
                    'line-strong': '#D2D8E2',
                    text: '#0F172A',
                    'text-2': '#475467',
                    'text-3': '#5F6B7E',
                    'blue-bg': '#EAF1FF',
                    'blue-ink': '#1D4ED8',
                    'teal-bg': '#E3F6F4',
                    'teal-ink': '#0F6B64',
                    'violet-bg': '#F0ECFE',
                    'violet-ink': '#5B2BC4',
                    'amber-bg': '#FFF3DC',
                    'amber-ink': '#9A4A06',
                    'rose-bg': '#FDECEC',
                    'rose-ink': '#B42318',
                    'green-bg': '#E7F6EC',
                    'green-ink': '#067647',
                    'slate-bg': '#EEF1F5',
                    'slate-ink': '#344054',
                    // The approved dashboard reference (2026-10): sidebar navy, its active blue,
                    // and the four metric accents (blue, teal, purple, orange), each with a tint.
                    'side-from': '#0B2A54',
                    'side-to': '#0A2145',
                    'side-active': '#1C55B8',
                    'side-card': '#12366A',
                    // The approved navigation: a text-only soft ice-blue sidebar, deep-navy text, a subtle teal active state.
                    'nav-bg': '#EEF5FB',
                    // The workspace behind every portal page (the shell only; cards and actions keep their own).
                    'nav-canvas': '#F5F8FC',
                    'nav-hover': '#E1ECF6',
                    'nav-active': '#DDF2F2',
                    'nav-line': '#D6E3EF',
                    'nav-teal': '#00A8A8',
                    // Teal for small marks on the light active surface (icons), dark enough to read.
                    'nav-teal-strong': '#007A7B',
                    'nav-ink': '#0B1F33',
                    'nav-muted': '#4A5D73',
                    ink: '#102A43',
                    muted: '#64748B',
                    primary: '#1D5FD1',
                    'primary-strong': '#164CAB',
                    'primary-soft': '#EAF1FD',
                    'm-blue': '#2F6FDE',
                    'm-blue-bg': '#EFF5FF',
                    'm-blue-ring': '#DCE8FD',
                    'm-teal': '#0E9F87',
                    'm-teal-bg': '#ECF9F5',
                    'm-teal-ring': '#D3F1E8',
                    'm-purple': '#7A4CE0',
                    'm-purple-bg': '#F4F0FF',
                    'm-purple-ring': '#E6DEFD',
                    'm-orange': '#E97A12',
                    'm-orange-bg': '#FFF5EA',
                    'm-orange-ring': '#FDE5CB',
                    'new-case-from': '#0E4F8C',
                    'new-case-to': '#0B3C6E',
                },
                navy: {
    				DEFAULT: '#0A2540',
    				900: '#0A2540',
    			},
    			teal: {
    				DEFAULT: '#00A8B5',
    				400: '#33C8D6',
    				500: '#00A8B5',
    				600: '#00909B',
    				700: '#006C77',
    				50: '#E6F8F9',
    				100: '#CCF0F3',
    			}
    		},
            fontSize: {
                ...ovsTokens.fontSize,
            },
            spacing: {
                ...ovsTokens.spacing,
            },
    		borderRadius: {
    			lg: '16px',
    			md: '12px',
    			sm: '8px',
                xl: '24px',
                ...ovsTokens.borderRadius,
    		},
            backdropBlur: {
                ...ovsTokens.backdropBlur,
            },
            boxShadow: {
                surface: '0 1px 3px rgba(0,0,0,0.02)',
                medium: '0 8px 30px rgba(0,0,0,0.04)',
                floating: '0 20px 40px -10px rgba(0,0,0,0.08)',
                // The clinic's surfaces (the same depths the Company CRM uses, kept here so the clinic owns them).
                'clinic-panel': '0 1px 2px rgba(11,11,15,0.03), 0 10px 30px -14px rgba(11,11,15,0.12)',
                'clinic-card': '0 1px 2px rgba(11,11,15,0.04), 0 6px 18px -10px rgba(11,11,15,0.12)',
                'clinic-lift': '0 2px 6px rgba(11,11,15,0.05), 0 20px 44px -20px rgba(11,11,15,0.20)',
                'clinic-ink': '0 6px 22px -8px rgba(11,11,15,0.45)',
                'cx-card': '0 1px 2px rgba(15,23,42,0.04), 0 8px 24px -14px rgba(15,23,42,0.14)',
                'cx-raised': '0 2px 6px rgba(15,23,42,0.06), 0 18px 40px -18px rgba(15,23,42,0.22)',
                ...ovsTokens.boxShadow,
            },
    		keyframes: {
    			'accordion-down': {
    				from: {
    					height: 0
    				},
    				to: {
    					height: 'var(--radix-accordion-content-height)'
    				}
    			},
    			'accordion-up': {
    				from: {
    					height: 'var(--radix-accordion-content-height)'
    				},
    				to: {
    					height: 0
    				}
    			},
    			shimmer: {
    				'100%': {
    					transform: 'translateX(100%)'
    				}
    			}
    		},
    		animation: {
    			'accordion-down': 'accordion-down 0.2s ease-out',
    			'accordion-up': 'accordion-up 0.2s ease-out'
    		}
    	}
    },

    plugins: [forms, require("tailwindcss-animate")],
};
