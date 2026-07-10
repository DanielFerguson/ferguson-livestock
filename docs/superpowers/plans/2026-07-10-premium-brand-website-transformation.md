# Ferguson Livestock Premium Brand Website Transformation — Implementation Plan

> **For agentic workers:** Implement task-by-task in the order below. Keep the checkboxes current, preserve the live stock/checkout guarantees, and verify each release gate before continuing.

**Goal:** Transform Ferguson Livestock from a long, generic farm-direct landing page into a polished founder-led brand and commerce site centred on Daniel and Tahlia, award-winning Murray Grey breeding, honest farm-direct value, and a personal local-farmer experience.

**North-star position:** Premium without pretence — good cattle, honest beef, raised nearby and delivered by the farmers who raised it.

**Primary brand line:** `Raised here. Delivered by us.`

**Architecture:** Keep Astro, TypeScript, Stripe and Upstash Redis. Introduce one shared business/catalogue configuration, page-specific SEO and structured data, server-rendered availability where practical, a concise homepage, evergreen supporting pages, and a clearer responsive order experience. Preserve atomic stock reservation, Stripe session handling, cancellation and refund behavior.

**Tech stack:** Astro 5, TypeScript, Tailwind CSS 4, Stripe Checkout, Upstash Redis, Vercel, self-hosted Fontsource fonts.

**Audit baseline:** 2026-07-10 source review, live-site review, 1280px desktop review, 390px mobile review, live canonical/robots/sitemap inspection, and successful `bun run build`.

---

## Outcomes and release principles

The finished site must:

- Lead with Daniel and Tahlia and their ambition to build a future in farming.
- Prove quality through specific cattle, practices, products and achievements rather than repeated adjectives.
- Present one accurate price, delivery rule, pickup arrangement and availability state everywhere.
- Feel editorial, restrained and rural rather than like a card-heavy direct-to-consumer template.
- Make the current conversion action obvious on the first mobile screen.
- Give search engines and answer engines the same facts customers see.
- Keep transactional and confirmation pages out of search indexes.
- Preserve the existing safe stock reservation and Stripe checkout lifecycle.
- Meet practical accessibility, responsive and reduced-motion expectations.
- Include privacy, delivery and refund information appropriate to the data and payments collected.

The implementation must not:

- Publish invented founder history, customer reviews, awards, environmental claims or product facts.
- Call the beef award-winning unless the beef itself has won the stated award; describe the breeders or cattle accurately.
- Use `grass-fed`, `organic`, `dry-aged`, `hormone-free`, carbon or health claims unless the exact wording is verified.
- Create thin suburb doorway pages.
- Add `llms.txt` or special “AI schema” as a substitute for normal search quality work.
- Weaken stock atomicity, Stripe signature verification, cancellation idempotency or refund stock restoration.

---

## Business facts to confirm before final copy is published

Implementation may begin using conservative wording, but these facts must be confirmed before the final copy gate:

- [ ] Current 5kg price and per-kilogram price.
- [ ] Current 10kg price and per-kilogram price.
- [ ] Ballarat-region delivery fee, delivery radius/postcodes and any free-delivery conditions.
- [ ] Farm pickup rules, address disclosure preference, days and time windows.
- [ ] Next-drop wording and whether a public estimated date should ever be shown.
- [ ] Exact feeding practice during dry periods and the approved pasture/grass terminology.
- [ ] Whether added growth promotants, routine antibiotics or other husbandry claims are accurate.
- [ ] Processor name/disclosure preference and who performs slaughter, hanging/ageing, butchering, sealing and packing.
- [ ] Whether the 7–10 day process is technically dry ageing or simply hanging/conditioning.
- [ ] Ferguson Livestock founding year and the origin story Daniel and Tahlia want public.
- [ ] Daniel and Tahlia's preferred public roles and direct contact method.
- [ ] Three flagship cattle/show achievements and the supporting photographs or result sources.
- [ ] Whether current testimonials are genuine, approved for use and attributable.
- [ ] The intended refund/replacement promise and temperature/seal issue process.
- [ ] Preferred canonical hostname: `www.fergusonlivestock.com.au` or apex.
- [ ] Which analytics/advertising platforms are genuinely needed.

Until confirmed, use the current checkout configuration as the operational source of truth: 5kg `$160`, 10kg `$275`, delivery `$15`, farm pickup free. Do not duplicate those values in prose.

---

## Target information architecture

| Route | Purpose | Indexing |
|---|---|---|
| `/` | Concise brand, founders, product summary, proof and current CTA | Index |
| `/beef-boxes` | Evergreen product detail, box contents, prices, availability and buying guidance | Index |
| `/our-story` | Daniel and Tahlia's story, timeline, ambitions and roles | Index |
| `/murray-grey-beef` | Breed expertise, breeding philosophy and selected achievements | Index |
| `/how-we-farm` | Verified husbandry, processing, packing and provenance | Index |
| `/delivery` | Service area, fee, pickup, timing and postcode guidance | Index |
| `/awards` | Selected cattle achievements with dates, photos and sources | Index |
| `/faq` | Accurate customer questions without self-awarded marketing questions | Index |
| `/contact` | Visible business details and direct local-farmer contact path | Index |
| `/guides` | Original storage, freezer, cut and cooking guidance | Index |
| `/order` | Live transactional ordering interface | Noindex, follow |
| `/thank-you` | Waitlist confirmation | Noindex, follow |
| `/order-confirmed` | Payment confirmation | Noindex, follow |
| `/privacy` | Collection, analytics and marketing disclosure | Index |
| `/delivery-and-refunds` | Delivery, pickup, replacement and refund policy | Index |

The first release may ship `/`, `/beef-boxes`, `/our-story`, `/delivery`, `/faq`, `/contact`, `/privacy` and `/delivery-and-refunds`; the remaining editorial pages can follow without blocking the core redesign.

---

## Task 1: Freeze the baseline and add regression checks

**Files:**

- Modify: `package.json`
- Create: `scripts/check-site.mjs`
- Create: `docs/content/business-facts.md`

- [ ] Record the confirmed business facts and claim wording in `docs/content/business-facts.md`.
- [ ] Add a `check` script that builds the site and runs deterministic HTML/site assertions.
- [ ] Assert that expired phrases such as `early April`, `Saturday 4th April`, `$150`, `$240` and `Delivery is always free` do not appear in built public pages unless reconfirmed.
- [ ] Assert that public indexable pages have one title, description, canonical and H1.
- [ ] Assert that confirmation/transaction routes emit `noindex`.
- [ ] Assert that sitemap URLs use the selected canonical hostname and exclude confirmation routes.
- [ ] Assert that visible/schema prices are generated from the catalogue source.
- [ ] Capture before screenshots at 390×844, 768×1024 and 1440×900 for homepage and order page.

**Verification:**

- `bun run build`
- `bun run check`
- Existing stock and checkout endpoints remain unchanged.

**Release gate:** Baseline checks fail on known stale content before later tasks fix them; no live behavior changes yet.

---

## Task 2: Create one source of truth for business and catalogue data

**Files:**

- Modify: `src/config/products.ts`
- Create: `src/config/business.ts`
- Create: `src/config/content.ts`
- Modify: `src/components/AnnouncementBar.astro`
- Modify: `src/components/Hero.astro`
- Modify: `src/components/FAQ.astro`
- Modify: `src/components/HowItWorks.astro`
- Modify: `src/components/WhyChooseUs.astro`
- Modify: `src/components/FinalCTA.astro`
- Modify: `src/pages/order.astro`

- [ ] Define typed business identity, contact, locality, delivery, pickup and claim data in `business.ts`.
- [ ] Extend the catalogue so display price, per-kilogram price, box contents, household fit, freezer guidance, availability and schema offers come from product/bundle records.
- [ ] Represent transient drop/pickup dates as data, never inline prose.
- [ ] Add formatting helpers for AUD prices, delivery language and availability labels.
- [ ] Replace duplicated prices, dates, delivery language and product rules throughout the site.
- [ ] Use conservative approved terms such as `pasture-raised` and `no added growth promotants` only when confirmed.
- [ ] Remove expired April copy and stale pickup windows.
- [ ] Remove the statement that individual cuts are unavailable while extras are sold.
- [ ] Clarify the responsibility split between farm, processor and butcher.

**Verification:**

- Search the source and built output for contradictory historical values.
- Exercise box-open, extras-only and fully-sold-out states.
- Confirm displayed totals still match Stripe price configuration.

**Release gate:** A single edit can change a commercial fact everywhere it is displayed or marked up.

---

## Task 3: Repair canonical, indexing, sitemap and structured-data foundations

**Files:**

- Modify: `astro.config.mjs`
- Modify: `public/robots.txt`
- Modify: `src/components/Layout.astro`
- Create: `src/lib/seo.ts`
- Create: `src/components/StructuredData.astro`
- Modify: all page frontmatter under `src/pages/`

- [ ] Select one canonical hostname and align Astro `site`, canonicals, Open Graph URLs, schema IDs, robots and sitemap.
- [ ] Configure the alternate hostname to use a permanent `301` or `308` redirect.
- [ ] Add page-level `canonicalUrl`, `robots`, Open Graph image alt and schema inputs to `Layout`.
- [ ] Set `lang="en-AU"`.
- [ ] Remove the global Product/Offer/aggregate-rating graph from `Layout`.
- [ ] Emit homepage Organization/LocalBusiness/WebSite/WebPage data only on the homepage.
- [ ] Use the accurate business type; do not describe the operation as a restaurant-style `FoodEstablishment`.
- [ ] Emit separate Product/Offer records on `/beef-boxes` using current catalogue data.
- [ ] Remove aggregate rating until a genuine numeric score and count are visible.
- [ ] Trim `areaServed` to the real service region rather than a duplicated list of towns.
- [ ] Add page-specific WebPage/AboutPage/BreadcrumbList/Article data where appropriate.
- [ ] Add `noindex, follow` to `/order`, `/thank-you` and `/order-confirmed`.
- [ ] Exclude confirmation pages from the sitemap and include evergreen public pages.
- [ ] Keep Cloudflare crawler policy as an explicit business decision; do not assume the repository robots file is the final live response.

**Verification:**

- Inspect live redirect chains for apex and `www`.
- Validate built JSON-LD as JSON and with Google's Rich Results Test where applicable.
- Inspect the generated sitemap and all canonical links.
- Confirm that structured data matches visible text and availability.

**Release gate:** One canonical host, accurate page-specific schema, and no transactional confirmation pages in the search index.

---

## Task 4: Establish the premium-without-pretence design system

**Files:**

- Modify: `src/styles/global.css`
- Create: `src/components/ui/Container.astro`
- Create: `src/components/ui/SectionHeading.astro`
- Create: `src/components/ui/Button.astro`
- Create: `src/components/ui/ProofStrip.astro`
- Create: `src/components/ui/EditorialImage.astro`
- Modify: `src/components/Header.astro`
- Modify: `src/components/Footer.astro`
- Replace or add: responsive logo assets under `src/assets/brand/`

- [ ] Refine the palette to deep forest, warm bone, muted eucalyptus and one clay/brass accent.
- [ ] Define consistent typography, spacing, content widths, radii, rules, focus states and button treatments.
- [ ] Reserve Cormorant Garamond for major headings, quotations and key numbers.
- [ ] Reduce gradients, pills, circular icon badges, oversized radii and card shadows.
- [ ] Remove globally loaded unused font weights and scope the handwriting font to pages that need it.
- [ ] Create responsive full, horizontal, monogram and one-colour logo lockups; use SVG where possible.
- [ ] If new logo artwork is not approved, use a clean typographic wordmark temporarily rather than inventing a final identity.
- [ ] Build a real `<nav>` with Beef Boxes, Our Story, How It Works, Delivery and FAQ links.
- [ ] Make logo links point to `/`, not `#`.
- [ ] Make the header CTA page- and availability-aware.
- [ ] Expand the footer with contact, service area, policies, navigation and current year.
- [ ] Add reduced-motion styles globally.

**Verification:**

- Keyboard navigation and visible focus states.
- Header and navigation at 320px through wide desktop.
- No horizontal overflow.
- Contrast check for normal text and interactive elements.

**Release gate:** Shared design primitives are stable before page-by-page redesign begins.

---

## Task 5: Rebuild the homepage around the founders and current action

**Files:**

- Modify: `src/pages/index.astro`
- Rewrite: `src/components/Hero.astro`
- Rewrite: `src/components/MeetTheFergusons.astro`
- Rewrite: `src/components/AccoladesMarquee.astro` or replace it
- Rewrite: `src/components/Testimonials.astro`
- Rewrite: `src/components/WhatsInTheBox.astro`
- Rewrite: `src/components/HowItWorks.astro`
- Rewrite: `src/components/WhyMurrayGrey.astro`
- Rewrite: `src/components/Guarantee.astro`
- Rewrite: `src/components/FAQ.astro`
- Remove from homepage: `Comparison.astro`
- Merge/remove from homepage: `WhyChooseUs.astro`, `HealthBenefits.astro`, `PaddockToPlate.astro`, `OurPractices.astro`, `SupportLocal.astro`, `FinalCTA.astro`

- [ ] Replace the opening with a founder-led hero using `Raised here. Delivered by us.`.
- [ ] Name Daniel and Tahlia in the first paragraph.
- [ ] Use a strong founder/cattle image and surface a real product image above the fold.
- [ ] Render exactly one current availability state, preferably server-side.
- [ ] Use the correct primary CTA for drop-open, extras-only and sold-out states.
- [ ] Add a secondary `Meet Daniel & Tahlia` CTA.
- [ ] Add a concise proof strip for Snake Valley, Murray Grey, selected achievements and personal service.
- [ ] Move the founder story ahead of generic product education.
- [ ] Replace defensive supermarket language with positive, specific Ferguson evidence.
- [ ] Present exact box content, approximate weights, household fit, freezer space and variation notes.
- [ ] Merge raising, processing, packing and delivery into one three- or four-step journey.
- [ ] Replace the moving awards ticker with a restrained static proof block and three selected achievements.
- [ ] Replace the autoplay eight-image carousel with a curated two- or three-image editorial composition.
- [ ] Show only genuine, attributable customer proof.
- [ ] Reduce FAQs to the six to eight questions that materially affect purchase confidence.
- [ ] End with one conversion module, not a waitlist followed by another CTA back to the same form.
- [ ] Target a homepage no longer than roughly 8–12 mobile viewports, subject to content quality.

**Verification:**

- Screenshot comparison at the baseline viewports.
- First mobile viewport shows brand, product context and a primary action.
- Page contains one H1 and a logical heading hierarchy.
- No hidden contradictory availability copy in the delivered HTML.

**Release gate:** The homepage tells the Ferguson story before category education and is materially shorter than the baseline.

---

## Task 6: Redesign the live order experience without weakening checkout safety

**Files:**

- Modify: `src/pages/order.astro`
- Modify if needed: `src/pages/api/stock.ts`
- Preserve behavior in: `src/pages/api/checkout.ts`
- Preserve behavior in: `src/pages/api/cancel-checkout.ts`
- Preserve behavior in: `src/pages/api/webhooks/stripe.ts`

- [ ] Change the hero/title by state: boxes, extras-only or fully sold out.
- [ ] Use product imagery for boxes and extras.
- [ ] Convert dense cut lists into a scannable breakdown with approximate weights.
- [ ] Add `best for`, freezer guidance and variability disclosure.
- [ ] Use a desktop two-column layout with a sticky order summary.
- [ ] Add a mobile sticky total/continue bar without obscuring content.
- [ ] Keep delivery/pickup terms sourced from shared configuration.
- [ ] Remove stale pickup dates.
- [ ] Make unavailable sections genuinely disabled with `disabled`, `inert` and status announcements rather than opacity/pointer events alone.
- [ ] Increase quantity controls to at least comfortable 44px targets.
- [ ] Add `Questions? Text Daniel or Tahlia` when the public contact method is confirmed.
- [ ] Fetch or render stock once and share it between announcement, hero and order state where possible.
- [ ] Preserve extras-only checkout, atomic stock reservation, Stripe failure rollback, cancellation release, expiry release and refund restoration.

**Verification:**

- Box + extras + delivery checkout.
- Box + pickup checkout.
- Extras-only checkout.
- Full sold-out waitlist state.
- Insufficient-stock/409 update.
- Stripe creation failure rollback.
- Cancelled and expired session stock restoration.
- Successful session confirmation guard.
- Keyboard and screen-reader state changes.

**Release gate:** The order flow is clearer while every stock and payment safety property remains intact.

---

## Task 7: Add the evergreen trust and search pages

**Files:**

- Create: `src/pages/beef-boxes.astro`
- Create: `src/pages/our-story.astro`
- Create: `src/pages/delivery.astro`
- Create: `src/pages/faq.astro`
- Create: `src/pages/contact.astro`
- Create: `src/pages/privacy.astro`
- Create: `src/pages/delivery-and-refunds.astro`
- Later create: `src/pages/murray-grey-beef.astro`
- Later create: `src/pages/how-we-farm.astro`
- Later create: `src/pages/awards.astro`
- Later create: `src/pages/guides/`

- [ ] Write the founder story from confirmed facts: origin, first cattle, why Murray Grey, first drop, selected milestones and future ambition.
- [ ] Keep `young farmers having a go` as an emotional idea expressed through confident language such as `building a future in farming`.
- [ ] Build an evergreen product page whose core explanation remains useful during sold-out periods.
- [ ] Publish exact delivery region, fee, pickup and timing information.
- [ ] Add an honest contact page with visible name, locality, email and confirmed phone/SMS channel.
- [ ] Publish privacy information covering waitlist data, Klaviyo, analytics and advertising tools actually in use.
- [ ] Publish delivery, temperature/seal issue, replacement and refund terms.
- [ ] Add selected independent evidence links for cattle/show achievements.
- [ ] Create original guides for box contents, freezer space, storage and cooking less-familiar cuts.
- [ ] Add meaningful internal links among all pages.

**Verification:**

- Every public page has unique title, description, H1, canonical and appropriate schema.
- No invented or placeholder facts ship.
- Footer and header make every important page discoverable.

**Release gate:** The site has a small authoritative information graph rather than one oversized landing page.

---

## Task 8: Strengthen local SEO and answer-engine clarity

**Files:**

- Modify: public pages and shared SEO data
- Create: `docs/operations/local-search-checklist.md`

- [ ] Add an `At a glance` factual block with owners, farm locality, Ballarat proximity, breed, products, price source, delivery/pickup rules, last updated and contact.
- [ ] Use direct customer questions as headings followed by short factual answers and supporting detail.
- [ ] Remove `What's the best beef delivery service...` and other self-awarded questions.
- [ ] Add named authors and modified dates to guides.
- [ ] Make business name, locality, contact and service area consistent across site data.
- [ ] Document Google Business Profile work: correct category/model, service area, products, current images, drop posts and genuine review requests.
- [ ] Document relevant citation/backlink opportunities: Murray Grey associations, show societies, local food guides and regional media.
- [ ] Consider Merchant Center only when product availability and shipping data can remain current.
- [ ] Treat Cloudflare AI crawler controls as an explicit policy choice.
- [ ] Keep `llms.txt` optional and low priority after crawlability, facts and internal architecture are correct.

**Verification:**

- Search Console sitemap submission and URL inspection after deployment.
- Business Profile links and website details match.
- Search snippets do not combine mutually exclusive drop states.

**Release gate:** Local and generative search surfaces receive clear, current and verifiable facts.

---

## Task 9: Rationalise analytics, privacy, performance and accessibility

**Files:**

- Modify: `src/components/Layout.astro`
- Modify: `src/components/posthog.astro`
- Modify: `src/components/WaitlistForm.astro`
- Modify: `src/pages/api/subscribe.ts`
- Modify: `src/styles/global.css`
- Modify: `public/site.webmanifest`
- Remove or reconcile: `public/manifest.json`
- Create: `src/pages/404.astro`

- [ ] Decide the minimum analytics stack; avoid loading GA, Hotjar, Meta Pixel and PostHog unconditionally together.
- [ ] Initialise PostHog once and remove the broken `/undefined/static/array.js` request.
- [ ] Defer non-essential/session-recording tools until after critical rendering and the appropriate consent state.
- [ ] Stop logging waitlist phone numbers, postcodes and third-party profile payloads.
- [ ] Link privacy information next to the waitlist consent action.
- [ ] Validate and normalise phone numbers without exposing them in logs.
- [ ] Reduce homepage HTML and remove the oversized duplicated service-area schema.
- [ ] Trim Fontsource languages/weights and scope Caveat rather than loading all variants globally.
- [ ] Correct header logo image dimensions and responsive candidates.
- [ ] Ensure offscreen images are lazy and sized; keep the hero image prioritised.
- [ ] Fix gray-on-cream contrast.
- [ ] Remove the FAQ's fixed 300px content cap and use correct hidden/expanded semantics.
- [ ] Honour `prefers-reduced-motion` for fades, carousel and ticker.
- [ ] Ensure every page's skip link reaches `#main-content`.
- [ ] Add live regions for order stock, errors and state transitions.
- [ ] Reconcile the linked manifest, names, theme colors and icon paths.
- [ ] Add a custom 404 page and dynamic footer year.

**Verification:**

- No console errors or broken analytics asset requests.
- Keyboard-only homepage and order completion.
- Reduced-motion browser check.
- Accessibility audit with no high-impact errors.
- Mobile and desktop performance measurement after deployment.

**Release gate:** Tracking is intentional, data handling is transparent, and core journeys work without visual-only cues.

---

## Task 10: Production QA and launch

**Files:**

- Update: this plan's checkboxes
- Create: `docs/operations/site-release-checklist.md`

- [ ] Run the full build and regression checks.
- [ ] Validate all product prices against Stripe price IDs.
- [ ] Validate live stock and all three availability modes.
- [ ] Validate checkout, cancellation, expiry, webhook and refund behavior in the appropriate Stripe environment.
- [ ] Test homepage, product, story, delivery, order, waitlist and confirmation journeys at 320px, 390px, 768px, 1024px and wide desktop.
- [ ] Verify navigation, focus, contrast, reduced motion and form errors.
- [ ] Verify redirects, canonicals, sitemap, robots and `noindex` after deployment.
- [ ] Validate page-specific structured data.
- [ ] Check social sharing images and their current price/delivery language.
- [ ] Confirm no placeholder founder facts, customer names or temporary claims remain.
- [ ] Confirm the live Cloudflare robots response matches the intended search/AI policy.
- [ ] Submit the canonical sitemap in Search Console and request recrawl of key pages.
- [ ] Record before/after screenshots and key site measurements.

**Final acceptance:**

- The homepage is founder-led, materially shorter and has an above-fold mobile action.
- All commercial facts agree across visible copy, checkout, metadata and schema.
- The live site uses one canonical hostname and a clean index/sitemap model.
- The order flow retains its current safety guarantees.
- Public claims and testimonials are verified.
- The production build, site checks and manual responsive/accessibility checks pass.

---

## Suggested commit sequence

1. `test: add site content and SEO regression checks`
2. `refactor: centralise business and catalogue content`
3. `fix: align canonical indexing and structured data`
4. `feat: establish premium Ferguson design system`
5. `feat: rebuild homepage around the Ferguson story`
6. `feat: refine responsive beef ordering experience`
7. `feat: add evergreen brand trust and policy pages`
8. `feat: strengthen local search and content architecture`
9. `fix: rationalise analytics performance and accessibility`
10. `docs: add production site release checklist`

Each commit should leave `bun run build` passing. Do not combine checkout safety changes with broad visual changes in the same commit.
