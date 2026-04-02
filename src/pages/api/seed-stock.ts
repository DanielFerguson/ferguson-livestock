import type { APIRoute } from "astro";
import { seedStock, setDropActive } from "../../lib/kv";

export const prerender = false;

export const POST: APIRoute = async ({ request }) => {
  const secret = request.headers.get("x-seed-secret");
  const expectedSecret = import.meta.env.STOCK_SEED_SECRET;

  if (!expectedSecret || secret !== expectedSecret) {
    return new Response(JSON.stringify({ error: "Unauthorized" }), {
      status: 401,
      headers: { "Content-Type": "application/json" },
    });
  }

  try {
    const result = await seedStock();
    await setDropActive(true);

    return new Response(
      JSON.stringify({
        success: true,
        seeded: result.seeded,
        skipped: result.skipped,
      }),
      { status: 200, headers: { "Content-Type": "application/json" } }
    );
  } catch (error) {
    console.error("Seed stock error:", error);
    return new Response(
      JSON.stringify({ error: "Failed to seed stock" }),
      { status: 500, headers: { "Content-Type": "application/json" } }
    );
  }
};
