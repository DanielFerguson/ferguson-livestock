export type ProductType = "box" | "extra" | "delivery";

export interface Product {
  name: string;
  description: string;
  price: number; // cents
  stripePriceId: string;
  initialStock: number;
  type: ProductType;
  boxDetails?: BoxDetails;
}

export interface BoxDetails {
  weightKg: number;
  perKgPrice: number; // cents
  contents: string[];
  bestFor: string;
  freezerGuidance: string;
}

export interface Bundle {
  name: string;
  description: string;
  stripePriceId: string;
  stockProduct: string; // key in `products`
  stockQuantity: number; // how many units it consumes
  displayPrice: number; // cents — for UI display
  boxDetails: BoxDetails;
}

export interface CartItem {
  productId: string;
  quantity: number;
}

export const products: Record<string, Product> = {
  "beef-box-5kg": {
    name: "5kg Beef Box",
    description: "A balanced mix of steaks, slow-cook cuts, roast, sausages and mince.",
    price: 16000,
    stripePriceId: "price_1TGtXNJwsTUhe334TgfuNp1L",
    initialStock: 5,
    type: "box",
    boxDetails: {
      weightKg: 5,
      perKgPrice: 3200,
      contents: [
        "Approximately 750g primary cuts — scotch, porterhouse, eye fillet or T-bone",
        "Approximately 1.5kg secondary cuts — rump, osso buco, schnitzel, diced beef, ribs or oyster blade",
        "Approximately 1.5kg roast",
        "Approximately 500g sausages",
        "Approximately 1kg mince",
      ],
      bestFor: "Couples and smaller households",
      freezerGuidance: "Allow roughly one standard freezer drawer.",
    },
  },
  "beef-mince-500g": {
    name: "500g Beef Mince",
    description: "Versatile Murray Grey beef mince.",
    price: 1200,
    stripePriceId: "price_1THfA0JwsTUhe334cteeVgYY",
    initialStock: 2,
    type: "extra",
  },
  "beef-bones-2kg": {
    name: "2kg Beef Bones",
    description: "Great for stock, broth, or pet bones.",
    price: 1000,
    stripePriceId: "price_1THfAFJwsTUhe334IWOXsw4g",
    initialStock: 3,
    type: "extra",
  },
  "sausage-packs-6": {
    name: "Sausage Pack (6)",
    description: "Six premium beef sausages.",
    price: 1200,
    stripePriceId: "price_1THvIHJwsTUhe334u3KX47WK",
    initialStock: 20,
    type: "extra",
  },
  "rump-steak-2": {
    name: "Rump Steak Pack (2)",
    description: "Two Murray Grey rump steaks.",
    price: 2000,
    stripePriceId: "price_1THvIbJwsTUhe334rBEfKgUy",
    initialStock: 10,
    type: "extra",
  },
  porterhouse: {
    name: "Porterhouse Steak",
    description: "Premium porterhouse steak.",
    price: 1800,
    stripePriceId: "price_1THvIqJwsTUhe3344UJW62Mn",
    initialStock: 10,
    type: "extra",
  },
  "diced-chuck": {
    name: "500g Diced Chuck",
    description: "Diced chuck steak, great for slow cooking.",
    price: 1300,
    stripePriceId: "price_1THvJ4JwsTUhe334rAaI2H5E",
    initialStock: 15,
    type: "extra",
  },
  "delivery-fee": {
    name: "Delivery",
    description: "Flat fee delivery to your door in the Ballarat region.",
    price: 1500,
    stripePriceId: "price_1THfWaJwsTUhe334shBtSR18",
    initialStock: 999999,
    type: "delivery",
  },
};

export const bundles: Record<string, Bundle> = {
  "beef-box-10kg": {
    name: "10kg Beef Box",
    description: "A larger balanced mix of steaks, slow-cook cuts, roasts, sausages and mince.",
    stripePriceId: "price_1TGtWLJwsTUhe334H1KnicPE",
    stockProduct: "beef-box-5kg",
    stockQuantity: 2,
    displayPrice: 27500,
    boxDetails: {
      weightKg: 10,
      perKgPrice: 2750,
      contents: [
        "Approximately 1.5kg primary cuts — scotch, porterhouse, eye fillet or T-bone",
        "Approximately 3kg secondary cuts — rump, osso buco, schnitzel, diced beef, ribs or oyster blade",
        "Approximately 3kg roast",
        "Approximately 1kg sausages",
        "Approximately 2kg mince",
      ],
      bestFor: "Families and regular beef eaters",
      freezerGuidance: "Allow roughly two standard freezer drawers.",
    },
  },
};

export function formatAud(cents: number): string {
  return new Intl.NumberFormat("en-AU", {
    style: "currency",
    currency: "AUD",
    maximumFractionDigits: cents % 100 === 0 ? 0 : 2,
  }).format(cents / 100);
}

export function formatPerKg(cents: number): string {
  return `${formatAud(cents)}/kg`;
}

/** Resolve a box selection ("5kg" | "10kg") to stock requirements */
export function resolveBoxStock(box: "5kg" | "10kg"): CartItem {
  if (box === "10kg") {
    const bundle = bundles["beef-box-10kg"];
    return { productId: bundle.stockProduct, quantity: bundle.stockQuantity };
  }
  return { productId: "beef-box-5kg", quantity: 1 };
}

/** Get the Stripe Price ID for a box selection */
export function getBoxPriceId(box: "5kg" | "10kg"): string {
  if (box === "10kg") {
    return bundles["beef-box-10kg"].stripePriceId;
  }
  return products["beef-box-5kg"].stripePriceId;
}

/** Get all product IDs that are trackable in stock (excludes delivery) */
export function getStockProductIds(): string[] {
  return Object.entries(products)
    .filter(([, p]) => p.type !== "delivery")
    .map(([id]) => id);
}
