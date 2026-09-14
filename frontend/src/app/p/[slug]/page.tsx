import { publicApi } from "@/lib/api";
import { LandingPage } from "@/lib/types";
import { LandingView } from "./LandingView";

export const dynamic = "force-dynamic";

export default async function PublicLandingPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const res = await fetch(`${publicApi}/public/landing-pages/${slug}`, { cache: "no-store" });
  if (!res.ok) {
    return <div className="p-10 text-center text-zinc-400">Page not found.</div>;
  }
  const page = (await res.json()) as LandingPage;
  return <LandingView page={page} />;
}
