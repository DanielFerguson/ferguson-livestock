# Ferguson Livestock Business Facts

This file is the publishing authority for commercial facts and sensitive marketing claims. Site copy, metadata and structured data must not contradict it.

## Confirmed operational facts

These values currently drive checkout and are treated as authoritative until Daniel or Tahlia confirms a change.

| Fact | Current value | Source |
|---|---:|---|
| 5kg beef box | $160 ($32/kg) | `src/config/products.ts` |
| 10kg beef box | $275 ($27.50/kg) | `src/config/products.ts` bundle |
| Ballarat-region delivery | $15 flat fee | `src/config/products.ts` |
| Farm pickup | Free | Current order flow |
| Farm locality | Snake Valley, Victoria 3351 | Existing public site and business schema |
| Nearby centre | Ballarat | Existing delivery and location copy |
| Breed | Murray Grey | Existing public site and cattle imagery |
| Order model | Periodic beef-box drops plus available individual cuts | Current stock and order flow |
| Payment | Stripe Checkout | Current checkout implementation |

## Approved positioning

- Brand position: premium without pretence.
- Primary brand line: **Raised here. Delivered by us.**
- Story: Daniel and Tahlia are young Snake Valley farmers building a future around Murray Grey cattle.
- Value promise: excellent beef, honest farm-direct value and personal local service.
- Achievement wording: describe Ferguson Livestock as Murray Grey breeders with award-winning cattle; do not call the beef award-winning without separate evidence.

## Safe default wording

Use these formulations until more specific claims are confirmed:

- `pasture-raised Murray Grey cattle`
- `personally delivered across the Ballarat region`
- `processed and professionally packed through a licensed local facility`
- `no feedlot finishing` only if reconfirmed before release
- `no added growth promotants` only if reconfirmed before release
- `conditioned for 7–10 days` only if the processor confirms the method and duration

Do not use `grass-fed`, `grass-finished`, `organic`, `dry-aged`, `hormone-free`, health-superiority claims, carbon-sequestration claims or local-economic multipliers without evidence and approved wording.

## Facts requiring confirmation before final-copy release

- Founding year and origin story.
- First Murray Grey cattle and why the breed was chosen.
- Exact feeding practice during dry periods.
- Added growth promotant and routine-antibiotic practices.
- Processor/butcher responsibility split and public naming preference.
- Hanging/conditioning/ageing method.
- Delivery postcodes/radius and any conditional free delivery.
- Farm pickup disclosure, schedule and directions.
- Preferred public phone/SMS number and Daniel/Tahlia roles.
- Three flagship cattle achievements and source material.
- Authenticity, permission and attribution for customer testimonials.
- Replacement/refund promise for seal, temperature or quality issues.
- Final analytics and advertising stack.

## Content maintenance rule

Transient dates, availability, prices, delivery fees and product contents must come from typed site configuration. Do not repeat them as unrelated hard-coded prose in components, metadata, FAQs, social artwork or JSON-LD.
