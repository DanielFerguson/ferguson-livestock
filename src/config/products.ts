export type ProductType = "box" | "extra";

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
    price: 15000,
    stripePriceId: "price_5kg_TODO", // Set from Stripe Dashboard
    initialStock: 15,
    type: "box",
  },
  "beef-mince-500g": {
    name: "500g Beef Mince",
    description: "Premium grass-fed beef mince.",
    price: 1200,
    stripePriceId: "price_mince_TODO", // Set from Stripe Dashboard
    initialStock: 10,
    type: "extra",
  },
  "beef-bones-2kg": {
    name: "2kg Beef Bones",
    description: "Great for stock, broth, or pet bones.",
    price: 1000,
    stripePriceId: "price_bones_TODO", // Set from Stripe Dashboard
    initialStock: 8,
    type: "extra",
  },
};

export const bundles: Record<string, Bundle> = {
  "beef-box-10kg": {
    name: "10kg Beef Box",
    description: "Two 5kg boxes at a discounted price. Best value.",
    stripePriceId: "price_10kg_TODO", // Set from Stripe Dashboard
    stockProduct: "beef-box-5kg",
    stockQuantity: 2,
    displayPrice: 24000,
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

/** Get all product IDs that are trackable in stock */
export function getStockProductIds(): string[] {
  return Object.keys(products);
}
