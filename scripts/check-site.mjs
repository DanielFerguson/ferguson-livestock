import { readFile, readdir } from "node:fs/promises";
import { join, relative } from "node:path";

const root = new URL("../", import.meta.url);
const sourceDir = new URL("../src/", import.meta.url);
const clientDir = new URL("../dist/client/", import.meta.url);
const canonicalHost = "https://www.fergusonlivestock.com.au";

const failures = [];

async function walk(directory, extensions) {
  const files = [];

  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) {
      files.push(...await walk(path, extensions));
    } else if (extensions.some((extension) => entry.name.endsWith(extension))) {
      files.push(path);
    }
  }

  return files;
}

function fail(message) {
  failures.push(message);
}

function occurrences(text, pattern) {
  return [...text.matchAll(pattern)].length;
}

const forbidden = [
  /early April/gi,
  /Saturday 4th April/gi,
  /Sunday 5th April/gi,
  /Delivery is always free/gi,
  /We only sell beef in boxes/gi,
  /ratingCount\s*:\s*["']10["']/g,
  /lowPrice\s*:\s*["']150["']/g,
  /highPrice\s*:\s*["']240["']/g,
  /googletagmanager\.com/gi,
  /connect\.facebook\.net/gi,
  /facebook\.com\/tr/gi,
  /\bgtag\s*\(/g,
  /\bfbq\s*\(/g,
];

for (const file of await walk(sourceDir.pathname, [".astro", ".ts", ".js"])) {
  const contents = await readFile(file, "utf8");
  for (const pattern of forbidden) {
    if (pattern.test(contents)) {
      fail(`${relative(root.pathname, file)} still contains ${pattern}`);
    }
    pattern.lastIndex = 0;
  }
}

const indexHtmlPath = join(clientDir.pathname, "index.html");
const indexHtml = await readFile(indexHtmlPath, "utf8");

if (occurrences(indexHtml, /<title(?:\s[^>]*)?>/g) !== 1) {
  fail("Homepage must contain exactly one <title>.");
}
if (occurrences(indexHtml, /<meta name="description"/g) !== 1) {
  fail("Homepage must contain exactly one meta description.");
}
if (occurrences(indexHtml, /<link rel="canonical"/g) !== 1) {
  fail("Homepage must contain exactly one canonical link.");
}
if (occurrences(indexHtml, /<h1(?:\s[^>]*)?>/g) !== 1) {
  fail("Homepage must contain exactly one H1.");
}
if (!indexHtml.includes(`<link rel="canonical" href="${canonicalHost}/">`)) {
  fail("Homepage canonical must use the selected www hostname.");
}
if (indexHtml.includes('"aggregateRating"')) {
  fail("Homepage must not publish aggregate-rating schema without visible evidence.");
}

const thankYouHtml = await readFile(join(clientDir.pathname, "thank-you/index.html"), "utf8");
if (!thankYouHtml.includes('<meta name="robots" content="noindex, follow">')) {
  fail("Thank-you page must be noindex, follow.");
}

const manifest = JSON.parse(await readFile(new URL("../public/site.webmanifest", import.meta.url), "utf8"));
if (manifest.name !== "Ferguson Livestock" || !manifest.short_name) {
  fail("Web app manifest must contain the Ferguson Livestock identity.");
}

for (const file of await walk(clientDir.pathname, [".html"])) {
  const contents = await readFile(file, "utf8");
  const path = relative(clientDir.pathname, file);

  if (occurrences(contents, /<html lang="en-AU">/g) !== 1) {
    fail(`${path} must declare Australian English.`);
  }
  if (occurrences(contents, /<title(?:\s[^>]*)?>/g) !== 1) {
    fail(`${path} must contain exactly one title.`);
  }
  if (occurrences(contents, /<meta name="description"/g) !== 1) {
    fail(`${path} must contain exactly one meta description.`);
  }
  if (occurrences(contents, /<link rel="canonical"/g) !== 1) {
    fail(`${path} must contain exactly one canonical link.`);
  }
  if (occurrences(contents, /<h1(?:\s[^>]*)?>/g) !== 1) {
    fail(`${path} must contain exactly one H1.`);
  }

  const ids = [...contents.matchAll(/\sid="([^"]+)"/g)].map((match) => match[1]);
  const duplicateIds = ids.filter((id, index) => ids.indexOf(id) !== index);
  if (duplicateIds.length > 0) {
    fail(`${path} contains duplicate IDs: ${[...new Set(duplicateIds)].join(", ")}.`);
  }

  for (const imageTag of contents.matchAll(/<img\b[^>]*>/g)) {
    if (!/\salt(?:\s|=)/.test(imageTag[0])) {
      fail(`${path} contains an image without an alt attribute.`);
    }
  }

  for (const schemaTag of contents.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)) {
    try {
      JSON.parse(schemaTag[1]);
    } catch {
      fail(`${path} contains malformed JSON-LD.`);
    }
  }

  if (contents.includes("posthog") || contents.includes("hotjar")) {
    fail(`${path} must not load retired PostHog or Hotjar tracking.`);
  }
}

const sitemapFiles = (await readdir(clientDir.pathname))
  .filter((file) => /^sitemap-\d+\.xml$/.test(file));

if (sitemapFiles.length === 0) {
  fail("No generated URL sitemap was found.");
} else {
  const sitemap = (await Promise.all(
    sitemapFiles.map((file) => readFile(join(clientDir.pathname, file), "utf8")),
  )).join("\n");

  if (!sitemap.includes(`<loc>${canonicalHost}</loc>`)) {
    fail("Sitemap must include the canonical homepage.");
  }
  if (sitemap.includes("/thank-you") || sitemap.includes("/order-confirmed")) {
    fail("Sitemap must exclude confirmation pages.");
  }
  if (sitemap.includes("https://fergusonlivestock.com.au")) {
    fail("Sitemap must not contain the non-www hostname.");
  }
}

for (const file of await walk(clientDir.pathname, [".html", ".xml", ".js"])) {
  const contents = await readFile(file, "utf8");
  for (const pattern of forbidden) {
    if (pattern.test(contents)) {
      fail(`${relative(root.pathname, file)} still contains ${pattern}`);
    }
    pattern.lastIndex = 0;
  }
}

if (failures.length > 0) {
  console.error("Site checks failed:\n");
  failures.forEach((failure) => console.error(`- ${failure}`));
  process.exitCode = 1;
} else {
  console.log("Site checks passed.");
}
