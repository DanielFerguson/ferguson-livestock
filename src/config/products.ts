export type ProductType = "box" | "extra" | "delivery";

export interface Product {
  name: string;
  description: string;
  price: number; // cents
  stripePriceId: string;
  initialStock: number;
  type: ProductType;
}

export interface Bundle {
  name: string;
  description: string;
  stripePriceId: string;
  stockProduct: string; // key in `products`
  stockQuantity: number; // how many units it consumes
  displayPrice: number; // cents — for UI display
}

export interface CartItem {
  productId: string;
  quantity: number;
}

export const products: Record<string, Product> = {
  "beef-box-5kg": {
    name: "5kg Beef Box",
    description: "Mixed cuts — steaks, roasts, mince, sausages. Vacuum-sealed.",
    price: 16000,
    stripePriceId: "price_1TGtXNJwsTUhe334TgfuNp1L",
    initialStock: 5,
    type: "box",
  },
  "beef-mince-500g": {
    name: "500g Beef Mince",
    description: "Premium grass-fed beef mince.",
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
    name: "Sausage Packs (6)",
    description: "Six premium beef sausages.",
    price: 1200,
    stripePriceId: "PLACEHOLDER_SAUSAGE_PACKS_6",
    initialStock: 20,
    type: "extra",
  },
  "rump-steak-2": {
    name: "Rump Steak (2)",
    description: "Two grass-fed rump steaks.",
    price: 2000,
    stripePriceId: "PLACEHOLDER_RUMP_STEAK_2",
    initialStock: 10,
    type: "extra",
  },
  "porterhouse": {
    name: "Porterhouse",
    description: "Premium porterhouse steak.",
    price: 1800,
    stripePriceId: "PLACEHOLDER_PORTERHOUSE",
    initialStock: 10,
    type: "extra",
  },
  "diced-chuck": {
    name: "Diced Chuck",
    description: "Diced chuck steak, great for slow cooking.",
    price: 1300,
    stripePriceId: "PLACEHOLDER_DICED_CHUCK",
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
    description: "Two 5kg boxes at a discounted price. Best value.",
    stripePriceId: "price_1TGtWLJwsTUhe334H1KnicPE",
    stockProduct: "beef-box-5kg",
    stockQuantity: 2,
    displayPrice: 27500,
  },
};

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
