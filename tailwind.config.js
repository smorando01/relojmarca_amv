/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ["./index.html", "./src/**/*.{js,jsx,ts,tsx}", "./App.jsx"],
  theme: {
    extend: {
      colors: {
        "brand-primary": "#00BFE0",
        "brand-primary-dark": "#0090C0",
        "brand-accent": "#80D040",
        "brand-alert": "#FF6B6B",
        "brand-dark": "#1A2B3C",
      },
    },
  },
  plugins: [],
};
