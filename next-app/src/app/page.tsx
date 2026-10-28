import Image from "next/image";
import Link from "next/link";
import { createClient } from "@/lib/supabase/server";

export default async function HomePage() {
  const hasSupabaseConfig =
    Boolean(process.env.NEXT_PUBLIC_SUPABASE_URL) &&
    Boolean(process.env.NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY || process.env.NEXT_PUBLIC_SUPABASE_ANON_KEY);
  let signedIn = false;
  if (hasSupabaseConfig) {
    const supabase = await createClient();
    const { data } = await supabase.auth.getUser();
    signedIn = Boolean(data.user);
  }

  return (
    <main className="welcome">
      <div className="w-hero"><Image className="auth-logo" src="/assets/aquify_badge_hd.png" alt="Logo Aquify" width={205} height={205} priority /></div>
      <section className="w-body">
        <Link className="mobile-brand guest-brand" href="/">
          <Image src="/assets/aquify_badge_hd.png" alt="" width={42} height={42} />
          <span>Aquify</span>
        </Link>
        <h1 className="w-title">Eksplorasi air tanah, lebih terarah.</h1>
        <p className="lead">Kelola survei geolistrik, pantau kualitas air, dan simpan data pengukuran dalam satu ruang kerja.</p>
        <Link className="btn teal block" href={signedIn ? "/dashboard" : "/register"}>{signedIn ? "Buka dashboard" : "Mulai gunakan Aquify"}</Link>
        <Link className="btn ghost block" href="/login">Saya sudah punya akun</Link>
        <small>Smart Groundwater Exploration System</small>
      </section>
    </main>
  );
}
