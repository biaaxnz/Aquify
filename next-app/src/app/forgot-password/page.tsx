import Image from "next/image";
import Link from "next/link";
import { requestPasswordReset } from "@/app/auth-actions";
import { AuthBrand } from "@/components/auth-brand";

export default async function ForgotPasswordPage({
  searchParams,
}: {
  searchParams: Promise<{ error?: string; message?: string }>;
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
        <p className="eyebrow">Pemulihan akun</p>
        <h1 className="auth-title">Lupa kata sandi?</h1>
        <p className="auth-sub">Masukkan email akun Aquify. Kami akan mengirim tautan untuk membuat kata sandi baru.</p>
        {params.error && <p className="notice" role="alert">{params.error}</p>}
        {params.message && <p className="notice info" role="status">{params.message}</p>}
        <form className="auth-form" action={requestPasswordReset}>
          <div className="field">
            <label htmlFor="email">Email akun</label>
            <input id="email" type="email" name="email" autoComplete="email" required />
          </div>
          <button className="btn" type="submit">Kirim tautan pemulihan</button>
        </form>
        <p className="auth-foot"><Link href="/login">Kembali ke halaman masuk</Link></p>
      </main>
    </div>
  );
}
