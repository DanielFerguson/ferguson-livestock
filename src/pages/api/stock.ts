import type { APIRoute } from "astro";
import { getStock, isDropActive } from "../../lib/kv";

export const prerender = false;

export const GET: APIRoute = async () => {
  try {
    const [stock, active] = await Promise.all([getStock(), isDropActive()]);

    return new Response(JSON.stringify({ active, stock }), {
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
