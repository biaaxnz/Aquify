import Image from "next/image";
import Link from "next/link";
import { signUp } from "@/app/auth-actions";
import { AuthBrand } from "@/components/auth-brand";

export default async function RegisterPage({
  searchParams,
}: {
  searchParams: Promise<{ error?: string }>;
}) {
  const params = await searchParams;
  return (
    <div className="auth-shell">
      <AuthBrand />
      <main className="auth-main">
        <Link className="mobile-brand guest-brand" href="/">
          <Image src="/assets/aquify_badge_hd.png" alt="Logo Aquify" width={42} height={42} unoptimized priority />
          <span>Aquify</span>
        </Link>
        <p className="eyebrow">Buat akun</p>
        <h1 className="auth-title">Gabung dengan Aquify</h1>
        <p className="auth-sub">Buat akun untuk menyimpan survei dan data pengukuranmu.</p>
        {params.error && <p className="notice" role="alert">{params.error}</p>}
        <form className="auth-form" action={signUp}>
          <div className="field"><label htmlFor="name">Nama lengkap</label><input id="name" type="text" name="name" autoComplete="name" maxLength={120} required /></div>
          <div className="field"><label htmlFor="email">Email</label><input id="email" type="email" name="email" autoComplete="email" required /></div>
          <div className="field"><label htmlFor="password">Kata sandi</label><input id="password" type="password" name="password" autoComplete="new-password" minLength={8} required /></div>
          <button className="btn" type="submit">Buat akun</button>
        </form>
        <p className="auth-foot">Sudah punya akun? <Link href="/login">Masuk</Link></p>
      </main>
    </div>
  );
}
