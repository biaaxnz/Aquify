"use server";

import { createClient } from "@/lib/supabase/server";
import { redirect } from "next/navigation";

function formText(formData: FormData, key: string): string {
  const value = formData.get(key);
  return typeof value === "string" ? value.trim() : "";
}

function authErrorMessage(message: string): string {
  const normalized = message.toLowerCase();
  if (normalized.includes("already registered") || normalized.includes("user already exists")) {
    return "Email ini sudah terdaftar. Silakan masuk.";
  }
  if (normalized.includes("database error saving new user") || normalized.includes("error creating user")) {
    return "Database Supabase belum siap. Jalankan seluruh isi next-app/supabase/schema.sql di SQL Editor Supabase.";
  }
  if (normalized.includes("email rate limit") || normalized.includes("rate limit")) {
    return "Terlalu banyak permintaan email. Tunggu beberapa menit sebelum mencoba lagi.";
  }
  if (normalized.includes("invalid email")) return "Format email tidak valid.";
  if (normalized.includes("password should be at least") || normalized.includes("weak password")) {
    return "Kata sandi terlalu pendek atau lemah. Gunakan minimal 8 karakter.";
  }
  if (normalized.includes("email not confirmed")) {
    return "Email belum dikonfirmasi. Buka email konfirmasi Supabase sebelum masuk.";
  }
  if (normalized.includes("invalid login credentials")) return "Email atau kata sandi tidak cocok.";
  return message;
}

export async function signIn(formData: FormData) {
  const email = formText(formData, "email").toLowerCase();
  const password = formText(formData, "password");
  if (!email || !password) redirect("/login?error=Lengkapi%20email%20dan%20kata%20sandi");

  let failure: string | null = null;
  try {
    const supabase = await createClient();
    const { error } = await supabase.auth.signInWithPassword({ email, password });
    failure = error ? authErrorMessage(error.message) : null;
  } catch (error) {
    console.error("Aquify sign-in request failed", error);
    failure = "Tidak dapat menghubungi Supabase. Periksa koneksi internet dan konfigurasi proyek.";
  }
  if (failure) redirect(`/login?error=${encodeURIComponent(failure)}`);
  redirect("/dashboard");
}

export async function signUp(formData: FormData) {
  const name = formText(formData, "name");
  const email = formText(formData, "email").toLowerCase();
  const password = formText(formData, "password");
  if (!name || !email || password.length < 8) {
    redirect("/register?error=Isi%20nama%20dan%20email%2C%20kata%20sandi%20minimal%208%20karakter");
  }

  let signupError: string | null = null;
  let hasSession = false;
  try {
    const supabase = await createClient();
    const { data, error } = await supabase.auth.signUp({
      email,
      password,
      options: { data: { full_name: name } },
    });
    signupError = error ? authErrorMessage(error.message) : null;
    hasSession = Boolean(data.session);
  } catch (error) {
    console.error("Aquify sign-up request failed", error);
    redirect("/register?error=Tidak%20dapat%20menghubungi%20Supabase.%20Periksa%20koneksi%20internet%20dan%20konfigurasi%20proyek");
  }
  if (signupError) redirect(`/register?error=${encodeURIComponent(signupError)}`);
  if (hasSession) redirect("/dashboard");
  redirect("/login?message=Periksa%20email%20untuk%20konfirmasi%20pendaftaran");
}

export async function signOut() {
  const supabase = await createClient();
  await supabase.auth.signOut();
  redirect("/login");
}
