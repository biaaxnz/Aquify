import Image from "next/image";
import Link from "next/link";
import { redirect } from "next/navigation";
import { signIn } from "@/app/auth-actions";
import { AuthBrand } from "@/components/auth-brand";
import { createClient } from "@/lib/supabase/server";

export default async function LoginPage({
  searchParams,
}: {
  searchParams: Promise<{ error?: string; message?: string }>;
}) {
  const params = await searchParams;
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (user) redirect("/dashboard");

  return (
    <div className="auth-shell">
      <AuthBrand />
      <main className="auth-main">
        <Link className="mobile-brand guest-brand" href="/">
          <Image src="/assets/aquify_badge_hd.png" alt="Logo Aquify" width={42} height={42} unoptimized priority />
          <span>Aquify</span>
        </Link>
        <p className="eyebrow">Selamat datang kembali</p>
        <h1 className="auth-title">Masuk ke Aquify</h1>
        <p className="auth-sub">Masuk untuk melihat dan mengelola data surveimu di Aquify.</p>
        {params.error && <p className="notice" role="alert">{params.error}</p>}
        {params.message && <p className="notice info">{params.message}</p>}
        <form className="auth-form" action={signIn}>
          <div className="field"><label htmlFor="email">Email</label><input id="email" type="email" name="email" autoComplete="email" required /></div>
          <div className="field"><label htmlFor="password">Kata sandi</label><input id="password" type="password" name="password" autoComplete="current-password" required /></div>
          <p className="link-r"><Link href="/forgot-password">Lupa kata sandi?</Link></p>
          <button className="btn" type="submit">Masuk</button>
        </form>
        <p className="auth-foot">Belum punya akun? <Link href="/register">Daftar sekarang</Link></p>
      </main>
    </div>
  );
}
