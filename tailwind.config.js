/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./src/**/*.{js,jsx,ts,tsx}",
    "./includes/**/*.php",
  ],
  theme: {
    extend: {
      colors: {
        primary: '#006eef',
        'primary-dark': '#0056b3',
      },
      maxWidth: {
        'container': '70rem',
      },
    },
  },
  plugins: [],
}
