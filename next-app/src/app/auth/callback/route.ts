import { NextRequest, NextResponse } from "next/server";
import { createClient } from "@/lib/supabase/server";

function safeNextPath(value: string | null): string {
  return value === "/reset-password" ? value : "/reset-password";
}

export async function GET(request: NextRequest) {
  const searchParams = request.nextUrl.searchParams;
  const code = request.nextUrl.searchParams.get("code");
  const tokenHash = searchParams.get("token_hash");
  const type = searchParams.get("type");
  const errorDescription = searchParams.get("error_description");
  if (errorDescription || searchParams.has("error")) {
    const message = errorDescription?.toLowerCase().includes("expired")
      ? "Tautan pemulihan sudah kedaluwarsa. Minta tautan baru."
      : "Tautan pemulihan tidak dapat digunakan. Minta tautan baru.";
    return NextResponse.redirect(new URL(`/forgot-password?error=${encodeURIComponent(message)}`, request.url));
  }

  if (!code && !(tokenHash && type === "recovery")) {
    return NextResponse.redirect(new URL(
      "/forgot-password?error=Tautan%20pemulihan%20tidak%20memuat%20kode%20yang%20diperlukan.%20Minta%20tautan%20baru%20atau%20hubungi%20administrator.",
      request.url,
    ));
  }

  const supabase = await createClient();
  const { error } = code
    ? await supabase.auth.exchangeCodeForSession(code)
    : await supabase.auth.verifyOtp({ token_hash: tokenHash!, type: "recovery" });
  if (error) {
    console.error("Aquify password recovery callback failed", error.message);
    const normalizedError = error.message.toLowerCase();
    const message = normalizedError.includes("verifier") || normalizedError.includes("pkce")
      ? "Tautan dibuka di browser berbeda dari tempat permintaan dibuat. Minta tautan baru, lalu buka di browser yang sama dan gunakan satu kali."
      : "Tautan pemulihan sudah kedaluwarsa atau sudah digunakan. Minta tautan baru dan buka sekali saja.";
    return NextResponse.redirect(new URL(
      `/forgot-password?error=${encodeURIComponent(message)}`,
      request.url,
    ));
  }

  const nextPath = safeNextPath(searchParams.get("next"));
  return NextResponse.redirect(new URL(nextPath, request.url));
}
