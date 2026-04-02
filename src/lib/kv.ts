import { Redis } from "@upstash/redis";
import { products, getStockProductIds, type CartItem } from "../config/products";

const redis = new Redis({
  url: import.meta.env.UPSTASH_REDIS_REST_URL,
  token: import.meta.env.UPSTASH_REDIS_REST_TOKEN,
});

// --- Stock Operations ---

export async function getStock(): Promise<Record<string, number>> {
  const productIds = getStockProductIds();
  if (productIds.length === 0) return {};

  const pipeline = redis.pipeline();
  for (const id of productIds) {
    pipeline.get<number>(`stock:${id}`);
  }
  const results = await pipeline.exec<(number | null)[]>();

  const stock: Record<string, number> = {};
  productIds.forEach((id, i) => {
    stock[id] = results[i] ?? 0;
  });
  return stock;
}

/**
 * Atomically reserve stock for a list of items.
 * Returns true if all items were reserved, false if any had insufficient stock.
 * On failure, no stock is decremented (all-or-nothing).
 */
export async function reserveStock(items: CartItem[]): Promise<boolean> {
  // First, check all stock levels
  const stock = await getStock();
  for (const item of items) {
    if ((stock[item.productId] ?? 0) < item.quantity) {
      return false;
    }
  }

  // Decrement all atomically via pipeline
  const pipeline = redis.pipeline();
  for (const item of items) {
    pipeline.decrby(`stock:${item.productId}`, item.quantity);
  }
  const results = await pipeline.exec<number[]>();

  // Check if any went negative (race condition with concurrent request)
  const anyNegative = results.some((val) => val < 0);
  if (anyNegative) {
    // Rollback: restore all decremented values
    const rollback = redis.pipeline();
    for (const item of items) {
      rollback.incrby(`stock:${item.productId}`, item.quantity);
    }
    await rollback.exec();
    return false;
  }

  return true;
}

/** Release previously reserved stock */
export async function releaseStock(items: CartItem[]): Promise<void> {
  const pipeline = redis.pipeline();
  for (const item of items) {
    pipeline.incrby(`stock:${item.productId}`, item.quantity);
  }
  await pipeline.exec();
}

// --- Reservations ---

export interface Reservation {
  items: CartItem[];
  createdAt: number; // epoch ms
}

export async function saveReservation(
  sessionId: string,
  items: CartItem[]
): Promise<void> {
  const reservation: Reservation = {
    items,
    createdAt: Date.now(),
  };
  // TTL of 35 minutes — slightly longer than Stripe's 30-min session expiry
  // to ensure the webhook fires before the reservation auto-expires.
  // Upstash client handles JSON serialization automatically.
  await redis.set(`reservation:${sessionId}`, reservation, {
    ex: 35 * 60,
  });
}

export async function getReservation(
  sessionId: string
): Promise<Reservation | null> {
  return redis.get<Reservation>(`reservation:${sessionId}`);
}

export async function deleteReservation(sessionId: string): Promise<void> {
  await redis.del(`reservation:${sessionId}`);
}

// --- Drop State ---

export async function isDropActive(): Promise<boolean> {
  const active = await redis.get<string>("drop:active");
  return active === "true";
}

export async function setDropActive(active: boolean): Promise<void> {
  await redis.set("drop:active", active ? "true" : "false");
}

// --- Refund Idempotency ---

/** Mark a charge as refund-processed. Returns false if already processed. */
export async function markRefundProcessed(chargeId: string): Promise<boolean> {
  // NX ensures we only set once; 90-day TTL for cleanup
  const wasSet = await redis.set(`refund:${chargeId}`, "1", {
    nx: true,
    ex: 90 * 24 * 60 * 60,
  });
  return !!wasSet;
}

// --- Seeding ---

/**
 * Seed stock from product config. Only sets values that don't already exist
 * (NX flag) to avoid resetting stock mid-drop.
 */
export async function seedStock(): Promise<{
  seeded: string[];
  skipped: string[];
}> {
  const seeded: string[] = [];
  const skipped: string[] = [];

  for (const [id, product] of Object.entries(products)) {
    const wasSet = await redis.set(
      `stock:${id}`,
      product.initialStock,
      { nx: true }
    );
    if (wasSet) {
      seeded.push(id);
    } else {
      skipped.push(id);
    }
  }

  // Also set drop:active if not already set
  await redis.set("drop:active", "true", { nx: true });

  return { seeded, skipped };
}
