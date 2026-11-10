"use server";

import { createClient } from "@/lib/supabase/server";
import { redirect } from "next/navigation";

function formText(formData: FormData, key: string): string {
  const value = formData.get(key);
  return typeof value === "string" ? value.trim() : "";
}

function formPassword(formData: FormData, key: string): string {
  const value = formData.get(key);
  return typeof value === "string" ? value : "";
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

export async function requestPasswordReset(formData: FormData) {
  const email = formText(formData, "email").toLowerCase();
  if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    redirect("/forgot-password?error=Masukkan%20alamat%20email%20yang%20valid");
  }

  const appUrl = process.env.NEXT_PUBLIC_APP_URL?.trim();
  if (!appUrl) {
    console.error("Aquify password recovery requires NEXT_PUBLIC_APP_URL.");
    redirect("/forgot-password?error=Fitur%20pemulihan%20belum%20dikonfigurasi.%20Hubungi%20administrator.");
  }

  let failure: string | null = null;
  try {
    const supabase = await createClient();
    const { error } = await supabase.auth.resetPasswordForEmail(email, {
      redirectTo: `${appUrl.replace(/\/+$/, "")}/auth/callback?next=/reset-password`,
    });
    if (error) failure = authErrorMessage(error.message);
  } catch (error) {
    console.error("Aquify password recovery request failed", error);
    failure = "Tidak dapat menghubungi layanan pemulihan akun. Periksa koneksi internet lalu coba lagi.";
  }

  if (failure) redirect(`/forgot-password?error=${encodeURIComponent(failure)}`);
  redirect("/forgot-password?message=Jika%20email%20terdaftar%2C%20tautan%20pemulihan%20akan%20dikirim.%20Periksa%20kotak%20masuk%20atau%20folder%20spam.");
}

export async function updatePassword(formData: FormData) {
  const password = formPassword(formData, "password");
  const confirmation = formPassword(formData, "password_confirmation");
  if (password.length < 8) {
    redirect("/reset-password?error=Kata%20sandi%20baru%20minimal%208%20karakter");
  }
  if (password !== confirmation) {
    redirect("/reset-password?error=Konfirmasi%20kata%20sandi%20belum%20sama");
  }

  const supabase = await createClient();
  const { data: { user }, error: userError } = await supabase.auth.getUser();
  if (userError || !user) {
    redirect("/forgot-password?error=Sesi%20pemulihan%20tidak%20valid%20atau%20sudah%20kedaluwarsa.%20Minta%20tautan%20baru.");
  }
  const { error } = await supabase.auth.updateUser({ password });
  if (error) {
    console.error("Aquify password update failed", error.message);
    redirect(`/reset-password?error=${encodeURIComponent(authErrorMessage(error.message))}`);
  }

  redirect("/reset-password?message=Kata%20sandi%20berhasil%20diperbarui.%20Kamu%20dapat%20melanjutkan%20ke%20beranda.");
}

export async function signOut() {
  const supabase = await createClient();
  await supabase.auth.signOut();
  redirect("/login");
}
