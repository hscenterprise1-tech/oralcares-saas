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
