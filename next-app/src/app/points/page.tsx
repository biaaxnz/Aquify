import Link from "next/link";
import { redirect } from "next/navigation";
import { AppShell } from "@/components/app-shell";
import { Icon } from "@/components/icon";
import { createClient } from "@/lib/supabase/server";

export default async function PointsPage() {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) redirect("/login");
  const { data: points, error } = await supabase.from("points")
    .select("id,code,name,survey_type,latitude,longitude,created_at")
    .eq("owner_id", user.id)
    .order("created_at", { ascending: false });
  if (error) throw new Error(`Gagal memuat titik survey: ${error.message}`);

  return (
    <AppShell active="/history" title="Daftar Titik Survey">
      <div className="page-title"><h2>Lokasi Survey</h2><p>Semua titik yang tersimpan di akunmu.</p></div>
      <p><Link className="btn" href="/survey/new"><Icon name="plus" size={18} />Survey Baru</Link></p>
      {points.length ? points.map((point) => (
        <Link className="li" href={`/points/${point.id}`} key={point.id}>
          <span className={`li-ic ${point.survey_type === "water" ? "w" : "g"}`}><Icon name={point.survey_type === "water" ? "droplet" : "activity"} size={22} /></span>
          <span className="li-b"><b>{point.name}</b><small>{point.code} · {point.survey_type === "water" ? "Kualitas air" : "Geolistrik"}</small></span>
          <Icon name="chevron" size={18} />
        </Link>
      )) : <div className="empty"><b>Belum ada titik survey</b>Buat titik pertama untuk mulai mencatat pengukuran.<p><Link className="btn sm" href="/survey/new">Buat titik</Link></p></div>}
    </AppShell>
  );
}
