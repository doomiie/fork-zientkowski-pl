/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./*.html",
    "./**/*.html",
    "./admin/**/*.php",
    "./backend/**/*.php",
    "./products/**/*.html"
  ],
  safelist: [
    // Classes używane w dynamicznie wstrzykiwanej treści programu
    'list-disc', 'list-decimal', 'pl-5'
  ],
  theme: {
    extend: {
      screens: { xs: "400px" },
      colors: {
        accent: "rgb(4, 3, 39)",
        dark: "rgb(4, 3, 39)",
        light: "#F9F9F9"
      }
    }
  },
  plugins: []
};
