/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./*.html",
    "./**/*.html",
    "./admin/**/*.php",
    "./backend/**/*.php",
    "./products/**/*.html"
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

