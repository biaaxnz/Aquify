import Link from "next/link";
import { createPoint } from "@/app/actions";
import { AppShell } from "@/components/app-shell";
import { LocationPicker } from "@/components/location-picker";

export default async function NewSurveyPage({
  searchParams,
}: {
  searchParams: Promise<{ error?: string }>;
}) {
  const params = await searchParams;
  return (
    <AppShell active="/survey/new" title="Survey Baru">
      {params.error && <div className="alert bad" role="alert">{params.error}</div>}
      <section className="card">
        <h2>Informasi lokasi survey</h2>
        <p className="hint">Buat titik lokasi terlebih dahulu. Titik ini digunakan untuk mengelompokkan pengukuran geolistrik dan sampel air.</p>
        <form className="form-grid" action={createPoint} style={{ marginTop: 16 }}>
          <div className="field full"><label htmlFor="name">Nama lokasi / proyek</label><input id="name" name="name" maxLength={120} required placeholder="Contoh: Sumur Desa Sukamaju" /></div>
          <div className="field"><label htmlFor="survey_type">Jenis survey</label><select id="survey_type" name="survey_type" defaultValue="geovolt"><option value="geovolt">Geolistrik</option><option value="water">Kualitas air</option></select></div>
          <div className="field full"><label>Lokasi titik (opsional)</label><LocationPicker /></div>
          <div className="field full"><button className="btn" type="submit">Simpan titik survey</button></div>
        </form>
      </section>
      <p className="hint"><Link href="/dashboard">Kembali ke beranda</Link></p>
    </AppShell>
  );
}
