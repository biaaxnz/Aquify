import { NextRequest, NextResponse } from "next/server";
import { createClient } from "@/lib/supabase/server";

function safeNextPath(value: string | null): string {
  return value === "/reset-password" ? value : "/reset-password";
}

export async function GET(request: NextRequest) {
  const code = request.nextUrl.searchParams.get("code");
  if (!code) {
    return NextResponse.redirect(new URL("/forgot-password?error=Tautan%20pemulihan%20tidak%20valid.%20Minta%20tautan%20baru.", request.url));
  }

  const supabase = await createClient();
  const { error } = await supabase.auth.exchangeCodeForSession(code);
  if (error) {
    console.error("Aquify password recovery callback failed", error.message);
    return NextResponse.redirect(new URL("/forgot-password?error=Tautan%20pemulihan%20sudah%20kedaluwarsa%20atau%20tidak%20valid.%20Minta%20tautan%20baru.", request.url));
  }

  const nextPath = safeNextPath(request.nextUrl.searchParams.get("next"));
  return NextResponse.redirect(new URL(nextPath, request.url));
}
