import { business } from "../config/business";
import { bundles, products } from "../config/products";

interface WebPageSchemaOptions {
  url: string;
  title: string;
  description: string;
  type?: "WebPage" | "AboutPage" | "ContactPage";
  image?: string;
}

export function buildWebPageSchema({
  url,
  title,
  description,
  type = "WebPage",
  image = `${business.siteUrl}/og-image.jpg`,
}: WebPageSchemaOptions): Record<string, unknown> {
  return {
    "@context": "https://schema.org",
    "@type": type,
    "@id": `${url}#webpage`,
    url,
    name: title,
    description,
    isPartOf: { "@id": `${business.siteUrl}/#website` },
    about: { "@id": `${business.siteUrl}/#business` },
    primaryImageOfPage: {
      "@type": "ImageObject",
      url: image,
    },
    inLanguage: "en-AU",
  };
}

export function buildHomeStructuredData(
  title: string,
  description: string,
): Record<string, unknown> {
  const homeUrl = `${business.siteUrl}/`;

  const { "@context": _context, ...homePage } = buildWebPageSchema({
    url: homeUrl,
    title,
    description,
  });

  return {
    "@context": "https://schema.org",
    "@graph": [
      {
        "@type": "Organization",
        "@id": `${business.siteUrl}/#organization`,
        name: business.name,
        url: homeUrl,
        logo: {
          "@type": "ImageObject",
          url: `${business.siteUrl}/android-chrome-512x512.png`,
          width: 512,
          height: 512,
        },
        image: `${business.siteUrl}/og-image.jpg`,
        sameAs: [business.social.facebook],
        contactPoint: {
          "@type": "ContactPoint",
          telephone: business.phone.international,
          contactType: "sales",
          areaServed: "AU-VIC",
          availableLanguage: "en-AU",
        },
      },
      {
        "@type": "LocalBusiness",
        "@id": `${business.siteUrl}/#business`,
        name: business.name,
        description,
        url: homeUrl,
        telephone: business.phone.international,
        email: business.email,
        image: `${business.siteUrl}/og-image.jpg`,
        sameAs: [business.social.facebook],
        address: {
          "@type": "PostalAddress",
          addressLocality: business.location.locality,
          addressRegion: business.location.region,
          postalCode: business.location.postalCode,
          addressCountry: business.location.country,
        },
        areaServed: [
          { "@type": "City", name: business.location.nearbyCity },
          { "@type": "Place", name: business.location.locality },
        ],
        priceRange: "$$",
        currenciesAccepted: "AUD",
        paymentAccepted: "Credit Card",
      },
      {
        "@type": "WebSite",
        "@id": `${business.siteUrl}/#website`,
        url: homeUrl,
        name: business.name,
        description,
        publisher: { "@id": `${business.siteUrl}/#organization` },
        inLanguage: "en-AU",
      },
      homePage,
    ],
  };
}

export function buildBeefBoxStructuredData(options: {
  pageUrl: string;
  title: string;
  description: string;
  fiveKgAvailable: boolean;
  tenKgAvailable: boolean;
}): Record<string, unknown>[] {
  const fiveKg = products["beef-box-5kg"];
  const tenKg = bundles["beef-box-10kg"];

  return [
    buildWebPageSchema({
      url: options.pageUrl,
      title: options.title,
      description: options.description,
    }),
    {
      "@context": "https://schema.org",
      "@graph": [
        {
          "@type": "Product",
          "@id": `${options.pageUrl}#5kg-box`,
          name: fiveKg.name,
          description: fiveKg.description,
          image: `${business.siteUrl}/og-image.jpg`,
          brand: { "@type": "Brand", name: business.name },
          material: "Pasture-raised Murray Grey beef",
          offers: {
            "@type": "Offer",
            url: `${business.siteUrl}/order`,
            priceCurrency: "AUD",
            price: (fiveKg.price / 100).toFixed(2),
            availability: options.fiveKgAvailable
              ? "https://schema.org/InStock"
              : "https://schema.org/OutOfStock",
            seller: { "@id": `${business.siteUrl}/#organization` },
          },
        },
        {
          "@type": "Product",
          "@id": `${options.pageUrl}#10kg-box`,
          name: tenKg.name,
          description: tenKg.description,
          image: `${business.siteUrl}/og-image.jpg`,
          brand: { "@type": "Brand", name: business.name },
          material: "Pasture-raised Murray Grey beef",
          offers: {
            "@type": "Offer",
            url: `${business.siteUrl}/order`,
            priceCurrency: "AUD",
            price: (tenKg.displayPrice / 100).toFixed(2),
            availability: options.tenKgAvailable
              ? "https://schema.org/InStock"
              : "https://schema.org/OutOfStock",
            seller: { "@id": `${business.siteUrl}/#organization` },
          },
        },
      ],
    },
  ];
}
