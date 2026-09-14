"use client";

import { AppShell } from "@/lib/ui";

export default function AppLayout({ children }: { children: React.ReactNode }) {
  return <AppShell>{children}</AppShell>;
}
