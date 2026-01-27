/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        terrava: {
          primary: "#1F6F5C",
          secondary: "#2D3A3A",
          accent: "#C9A24D",
          neutral: "#E6ECEA",
        },
        primary: "#1F6F5C",
      },
    },
  },
  plugins: [],
}
