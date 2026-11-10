import Image from "next/image";
import Link from "next/link";
import { updatePassword } from "@/app/auth-actions";
import { AuthBrand } from "@/components/auth-brand";
import { createClient } from "@/lib/supabase/server";

export default async function ResetPasswordPage({
  searchParams,
}: {
  searchParams: Promise<{ error?: string; message?: string }>;
}) {
  const params = await searchParams;
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();

  return (
    <div className="auth-shell">
      <AuthBrand />
      <main className="auth-main">
        <Link className="mobile-brand guest-brand" href="/">
          <Image src="/assets/aquify_badge_hd.png" alt="Logo Aquify" width={42} height={42} unoptimized priority />
          <span>Aquify</span>
        </Link>
        <p className="eyebrow">Pemulihan akun</p>
        <h1 className="auth-title">Buat kata sandi baru</h1>
        {params.error && <p className="notice" role="alert">{params.error}</p>}
        {params.message && <p className="notice info" role="status">{params.message}</p>}
        {params.message ? (
          <p className="auth-foot"><Link href="/dashboard">Lanjut ke beranda</Link></p>
        ) : user ? (
          <>
            <p className="auth-sub">Gunakan minimal 8 karakter. Masukkan kata sandi yang sama pada kedua kolom.</p>
            <form className="auth-form" action={updatePassword}>
              <div className="field">
                <label htmlFor="password">Kata sandi baru</label>
                <input id="password" type="password" name="password" autoComplete="new-password" minLength={8} required />
              </div>
              <div className="field">
                <label htmlFor="password_confirmation">Ulangi kata sandi baru</label>
                <input id="password_confirmation" type="password" name="password_confirmation" autoComplete="new-password" minLength={8} required />
              </div>
              <button className="btn" type="submit">Simpan kata sandi baru</button>
            </form>
          </>
        ) : (
          <>
            <p className="auth-sub">Sesi pemulihan tidak ditemukan atau tautannya sudah kedaluwarsa. Minta tautan pemulihan baru untuk melanjutkan.</p>
            <p className="auth-foot"><Link href="/forgot-password">Minta tautan baru</Link></p>
          </>
        )}
      </main>
    </div>
  );
}
