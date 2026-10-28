import Link from "next/link";
import { redirect } from "next/navigation";
import { AppShell } from "@/components/app-shell";
import { Icon } from "@/components/icon";
import { createClient } from "@/lib/supabase/server";

function greeting() {
  const hour = new Date().getHours();
  return hour < 11 ? "Selamat pagi" : hour < 15 ? "Selamat siang" : hour < 19 ? "Selamat sore" : "Selamat malam";
}

export default async function DashboardPage() {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) redirect("/login");
  const name = String(user?.user_metadata.full_name || user?.email || "Pengguna");
  const initials = name.slice(0, 2).toUpperCase();

  const pointsResult = await supabase.from("points").select("id", { count: "exact" }).eq("owner_id", user.id);
  const pointIds = (pointsResult.data ?? []).map((point) => point.id);
  const [measurementsResult, waterResult, recentResult] = pointIds.length
    ? await Promise.all([
      supabase.from("measurements").select("id", { count: "exact", head: true }).in("point_id", pointIds),
      supabase.from("water_samples").select("id", { count: "exact", head: true }).in("point_id", pointIds),
      supabase.from("measurements").select("id,point_id,spacing_m,rho_a,measured_at,points(name,code)").in("point_id", pointIds).order("measured_at", { ascending: false }).limit(5),
    ])
    : [null, null, null];
  const loadError = pointsResult.error || measurementsResult?.error || waterResult?.error || recentResult?.error;

  return (
    <AppShell active="/dashboard" title="Beranda">
      <div className="home-head">
        <span className="av">{initials}</span>
        <div className="grow"><small>{greeting()},</small><b>{name}</b></div>
      </div>
      {loadError && <div className="alert bad" role="alert">Data dashboard belum dapat dimuat: {loadError.message}</div>}
      <div className="home-grid">
        <div>
          <section className="hero" aria-label="Ringkasan survei">
            <div className="hero-top"><span>Wilayah Kerja Aktif</span><span className="live off"><i />Siaga</span></div>
            <h2>Aquify · Eksplorasi Air Tanah</h2>
            <div className="hero-stats">
              <div><b>{pointsResult.count ?? "–"}</b><span>Titik Survey</span></div>
              <div><b>{measurementsResult?.count ?? 0}</b><span>Pengukuran</span></div>
              <div><b>{waterResult?.count ?? 0}</b><span>Sampel Air</span></div>
            </div>
          </section>
          <Link className="cta-geo" href="/geolistrik">
            <span className="cg-ic"><Icon name="activity" size={26} /></span>
            <span className="cg-t"><b>Pengukuran Tahanan Tanah</b><small>Kelola data geolistrik dan lihat hasil survei</small></span>
            <Icon name="chevron" size={20} />
          </Link>
          <nav className="quick" aria-label="Aksi cepat">
            <Link href="/survey/new"><Icon name="plus" size={22} />Survey Baru</Link>
            <Link className="q2" href="/water"><Icon name="droplet" size={22} />Cek Air</Link>
            <Link className="q3" href="/map"><Icon name="map" size={22} />Peta</Link>
            <Link href="/device"><Icon name="cpu" size={22} />Perangkat</Link>
          </nav>
          <section className="quickstart">
            <div className="quickstart-h"><h2>Mulai cepat</h2><span>1/4</span></div>
            <div className="bar"><i style={{ width: "25%" }} /></div>
            <Link className="qs done" href="/survey/new"><span className="dot"><Icon name="check" size={14} /></span><span><b>Buat titik survei</b><small>Mulai dengan mencatat lokasi survey.</small></span><Icon name="chevron" size={18} /></Link>
            <Link className="qs" href="/geolistrik"><span className="dot" /><span><b>Masukkan pengukuran</b><small>Tambahkan data hasil geolistrik.</small></span><Icon name="chevron" size={18} /></Link>
            <Link className="qs" href="/water"><span className="dot" /><span><b>Catat kualitas air</b><small>Simpan hasil uji sampel.</small></span><Icon name="chevron" size={18} /></Link>
            <Link className="qs" href="/analysis"><span className="dot" /><span><b>Tinjau analisis</b><small>Lihat ringkasan hasil dan rekomendasi.</small></span><Icon name="chevron" size={18} /></Link>
          </section>
          <div className="sec"><h2>Ringkasan Lapisan Wilayah</h2></div>
          <div className="card">
            <div className="empty-sm">Belum ada hasil analisis. Tambahkan pengukuran untuk mulai membangun ringkasan lapisan.</div>
          </div>
        </div>
        <div>
          <div className="sec" style={{ marginTop: 0 }}><h2>Pengukuran Terbaru</h2><Link className="tx-link" href="/history">Lihat semua</Link></div>
          {recentResult?.data?.length ? recentResult.data.map((reading) => {
            const point = Array.isArray(reading.points) ? reading.points[0] : reading.points;
            return (
              <Link className="li" href={`/points/${reading.point_id}`} key={reading.id}>
                <span className="li-ic g"><Icon name="activity" size={22} /></span>
                <span className="li-b"><b>{point?.name || point?.code || "Pengukuran geolistrik"}</b><small>a = {reading.spacing_m} m · ρ = {reading.rho_a} Ω·m</small></span>
                <span className="li-end"><small>{new Date(reading.measured_at).toLocaleDateString("id-ID")}</small></span>
              </Link>
            );
          }) : (
            <div className="empty"><b>Belum ada pengukuran</b>Buat titik survei dan tambahkan hasil pengukuran untuk melihat ringkasan di sini.<p><Link className="btn sm" href="/survey/new">Survey Baru</Link></p></div>
          )}
          <div className="placeholder-note">Pengukuran di dashboard berasal dari database Supabase. Data MySQL XAMPP lama belum dipindahkan.</div>
        </div>
      </div>
    </AppShell>
  );
}
