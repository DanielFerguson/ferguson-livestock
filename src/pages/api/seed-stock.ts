import type { APIRoute } from "astro";
import { seedStock, setDropActive, setLaunchAt } from "../../lib/kv";

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
    // Parse optional launchAt from request body
    let launchAt: number | null = null;
    try {
      const body = await request.json();
      if (body.launchAt && typeof body.launchAt === "number") {
        launchAt = body.launchAt;
      }
    } catch {
      // No body or invalid JSON — that's fine, launchAt is optional
    }

    const result = await seedStock();
    await setDropActive(true);

    if (launchAt) {
      await setLaunchAt(launchAt);
    }

    return new Response(
      JSON.stringify({
        success: true,
        seeded: result.seeded,
        skipped: result.skipped,
        ...(launchAt ? { launchAt } : {}),
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
