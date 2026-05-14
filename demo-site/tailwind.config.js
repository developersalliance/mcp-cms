/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './*.php',
    './services/**/*.php',
    './portfolio/**/*.php',
    './team/**/*.php',
    './extensions/**/*.php',
    './blog/**/*.php',
    // Exclude admin
    '!./cms/admin/**/*.php',
  ],
  theme: {
    extend: {
      colors: {
        primary: '#c01d18',
        secondary: '#f5f5f5',
      },
      fontFamily: {
        heading: ['Titillium Web', 'Raleway', 'Helvetica Neue', 'Helvetica', 'Arial', 'sans-serif'],
        body: ['Open Sans', 'Helvetica Neue', 'Helvetica', 'Arial', 'sans-serif'],
      },
    },
  },
  plugins: [
    require('daisyui'),
  ],
  daisyui: {
    themes: ['light'],
  },
}
