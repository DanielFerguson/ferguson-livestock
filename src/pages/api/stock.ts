import type { APIRoute } from "astro";
import { getStock, isDropActive, getLaunchAt } from "../../lib/kv";

export const prerender = false;

export const GET: APIRoute = async () => {
  try {
    const [stock, active, launchAt] = await Promise.all([
      getStock(),
      isDropActive(),
      getLaunchAt(),
    ]);

    console.log("Stock API response:", { active, launchAt, stockKeys: Object.keys(stock) });

    return new Response(JSON.stringify({ active, stock, launchAt }), {
      status: 200,
      headers: { "Content-Type": "application/json" },
    });
  } catch (error) {
    console.error("Error fetching stock:", error);
    return new Response(
      JSON.stringify({ error: "Failed to fetch stock" }),
      { status: 500, headers: { "Content-Type": "application/json" } }
    );
  }
};
