"use client";

import { createBrowserClient } from "@supabase/ssr";
import { useState, type FormEvent } from "react";

const SUCCESS_MESSAGE =
  "Tautan pemulihan sudah dikirim ke email. Silakan periksa kotak masuk dan folder spam.";

function resetErrorMessage(message: string): string {
  const normalized = message.toLowerCase();
  if (normalized.includes("rate limit")) {
    return "Terlalu banyak permintaan email. Tunggu beberapa menit sebelum mencoba lagi.";
  }
  if (normalized.includes("invalid email")) return "Format email tidak valid.";
  return "Permintaan pemulihan tidak dapat diproses. Coba lagi beberapa saat.";
}

export function ResetRequestForm() {
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setMessage(null);

    const formData = new FormData(event.currentTarget);
    const email = String(formData.get("email") ?? "").trim().toLowerCase();
    const supabaseUrl = process.env.NEXT_PUBLIC_SUPABASE_URL;
    const supabaseKey =
      process.env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY ||
      process.env.NEXT_PUBLIC_SUPABASE_ANON_KEY;

    if (!supabaseUrl || !supabaseKey) {
      console.error("Aquify password recovery requires public Supabase configuration.");
      setError("Fitur pemulihan belum dikonfigurasi. Hubungi administrator.");
      return;
    }

    setPending(true);
    try {
      const supabase = createBrowserClient(supabaseUrl, supabaseKey);
      const { error: requestError } = await supabase.auth.resetPasswordForEmail(email, {
        redirectTo: `${window.location.origin}/auth/callback`,
      });

      if (requestError) {
        console.error("Aquify password recovery request failed", requestError.message);
        setError(resetErrorMessage(requestError.message));
        return;
      }

      setMessage(SUCCESS_MESSAGE);
    } catch (requestError) {
      console.error("Aquify password recovery request failed", requestError);
      setError("Tidak dapat menghubungi layanan pemulihan akun. Periksa koneksi internet lalu coba lagi.");
    } finally {
      setPending(false);
    }
  }

  return (
    <>
      {error && <p className="notice" role="alert">{error}</p>}
      {message && <p className="notice info" role="status">{message}</p>}
      <form className="auth-form" onSubmit={handleSubmit}>
        <div className="field">
          <label htmlFor="email">Email akun</label>
          <input id="email" type="email" name="email" autoComplete="email" required disabled={pending} />
        </div>
        <button className="btn" type="submit" disabled={pending}>
          {pending ? "Mengirim tautan..." : "Kirim tautan pemulihan"}
        </button>
      </form>
    </>
  );
}
