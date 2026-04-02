// @ts-check
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';
import vercel from '@astrojs/vercel';
import tailwindcss from '@tailwindcss/vite';

// https://astro.build/config
export default defineConfig({
  site: 'https://fergusonlivestock.com.au',
  output: 'static',
  trailingSlash: 'never',
  adapter: vercel(),
  integrations: [sitemap({
    filter: (page) => !page.includes('/order') && !page.includes('/order-confirmed'),
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