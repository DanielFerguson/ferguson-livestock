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

// Lua script for atomic stock reservation.
// Checks all stock levels then decrements all in a single Redis operation.
// This is a server-side Redis script, not JavaScript eval — it runs inside
// the Redis engine and is the standard way to achieve atomic multi-key operations.
const RESERVE_SCRIPT = [
  "for i = 1, #KEYS do",
  "  local stock = tonumber(redis.call('GET', KEYS[i])) or 0",
  "  if stock < tonumber(ARGV[i]) then return 0 end",
  "end",
  "for i = 1, #KEYS do",
  "  redis.call('DECRBY', KEYS[i], ARGV[i])",
  "end",
  "return 1",
].join("\n");

/**
 * Atomically reserve stock for a list of items.
 * Uses a Redis Lua script to check and decrement in one atomic operation.
 * Returns true if all items were reserved, false if any had insufficient stock.
 * On failure, no stock is decremented (all-or-nothing).
 */
export async function reserveStock(items: CartItem[]): Promise<boolean> {
  const keys = items.map((i) => `stock:${i.productId}`);
  const args = items.map((i) => i.quantity);
  // @ts-expect-error — Upstash eval typing is loosely typed for Lua scripts
  const result = await redis.eval(RESERVE_SCRIPT, keys, args);
  return result === 1;
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

/**
 * Atomically get and delete a reservation.
 * Returns the reservation if it existed, null if already deleted.
 * Prevents double-release when cancel-checkout and expired webhook race.
 */
export async function claimReservation(
  sessionId: string
): Promise<Reservation | null> {
  const key = `reservation:${sessionId}`;
  // GETDEL atomically returns the value and deletes the key
  const data = await redis.getdel<Reservation>(key);
  return data ?? null;
}

// --- Drop State ---

export async function isDropActive(): Promise<boolean> {
  const active = await redis.get("drop:active");
  if (active !== true && active !== "true") return false;

  // If a launch time is set, only activate after that time
  const launchAt = await getLaunchAt();
  if (launchAt && Date.now() < launchAt * 1000) return false;

  return true;
}

export async function setDropActive(active: boolean): Promise<void> {
  await redis.set("drop:active", active);
}

export async function getLaunchAt(): Promise<number | null> {
  const val = await redis.get<number>("drop:launchAt");
  return val ?? null;
}

export async function setLaunchAt(epochSeconds: number): Promise<void> {
  await redis.set("drop:launchAt", epochSeconds);
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
    if (product.type === "delivery") continue;
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
  await redis.set("drop:active", true, { nx: true });

  return { seeded, skipped };
}
