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

    return new Response(JSON.stringify({ active, stock, launchAt }), {
      status: 200,
      headers: {
        "Content-Type": "application/json",
        "Cache-Control": "public, s-maxage=15, stale-while-revalidate=30",
      },
    });
  } catch (error) {
    console.error("Error fetching stock:", error);
    return new Response(
      JSON.stringify({ error: "Failed to fetch stock" }),
      {
        status: 500,
        headers: {
          "Content-Type": "application/json",
          "Cache-Control": "no-store",
        },
      }
    );
  }
};
