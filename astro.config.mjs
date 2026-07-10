// @ts-check
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';
import vercel from '@astrojs/vercel';
import tailwindcss from '@tailwindcss/vite';

// https://astro.build/config
export default defineConfig({
  site: 'https://www.fergusonlivestock.com.au',
  output: 'static',
  trailingSlash: 'never',
  adapter: vercel(),
  integrations: [sitemap({
    filter: (page) => {
      const pathname = new URL(page).pathname.replace(/\/$/, '') || '/';
      return !['/order', '/order-confirmed', '/thank-you'].includes(pathname);
    },
  })],
  vite: {
    plugins: [tailwindcss()]
  },
  image: {
    // Enable image optimization
    service: {
      entrypoint: 'astro/assets/services/sharp'
    }
  }
});
