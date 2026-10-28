import Link from "next/link";
import { notFound } from "next/navigation";
import { redirect } from "next/navigation";
import { addMeasurement, addWaterSample, runRuleAnalysis } from "@/app/actions";
import { AppShell } from "@/components/app-shell";
import { LayerColumn, Section2DPlot, SoundingPlot } from "@/components/resistivity-plots";
import { createClient } from "@/lib/supabase/server";
import { assessWater, buildSounding } from "@/lib/analysis";

export default async function PointDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) redirect("/login");
  const { data: point, error } = await supabase.from("points").select("id,code,name,survey_type,latitude,longitude,notes,created_at").eq("id", id).eq("owner_id", user.id).maybeSingle();
  if (error) throw new Error(`Gagal memuat titik survey: ${error.message}`);
  if (!point) notFound();

  const [measurementResult, sampleResult, analysisResult] = await Promise.all([
    supabase.from("measurements").select("id,spacing_m,dv_mv,i_ma,rho_a,x_m,config,measured_at").eq("point_id", id).order("measured_at", { ascending: false }),
    supabase.from("water_samples").select("id,tds_ppm,ec_us,ph,temp_c,turbidity_ntu,measured_at").eq("point_id", id).order("measured_at", { ascending: false }),
    supabase.from("analyses").select("id,mode,score,verdict,best_depth,best_rho,result,ai_text,created_at").eq("point_id", id).order("created_at", { ascending: false }).limit(10),
  ]);
  if (measurementResult.error) throw new Error(`Gagal memuat pengukuran: ${measurementResult.error.message}`);
  if (sampleResult.error) throw new Error(`Gagal memuat sampel air: ${sampleResult.error.message}`);
  if (analysisResult.error) throw new Error(`Gagal memuat analisis: ${analysisResult.error.message}`);
  const rows = measurementResult.data ?? [];
  const waterRows = sampleResult.data ?? [];
  const savedAnalysis = analysisResult.data?.find((row) => row.mode === "rule");
  const sounding = buildSounding(rows);
  const latestWater = waterRows[0] ? assessWater(waterRows[0]) : null;

  return (
    <AppShell active="/history" title="Detail Survey">
      <div className="page-title"><h2>{point.name}</h2><p>Kode {point.code} · {point.survey_type === "water" ? "Kualitas air" : "Geolistrik"}</p></div>
      {(point.latitude !== null && point.longitude !== null) && <p className="hint">Koordinat: {point.latitude}, {point.longitude}</p>}
      <div className="statcards">
        <div><b>{rows.length}</b><small>Data geolistrik</small></div>
        <div><b>{waterRows.length}</b><small>Sampel air</small></div>
      </div>
      <div className="sec"><h2>Pengukuran geolistrik</h2><Link className="tx-link" href="/geolistrik">Input data</Link></div>
      {rows.length ? <div className="card table-wrap"><table className="data-table"><thead><tr><th>Jarak</th><th>Tegangan</th><th>Arus</th><th>ρ semu</th><th>Posisi lintasan</th><th>Waktu</th></tr></thead><tbody>
        {rows.map((row) => <tr key={row.id}><td>{row.spacing_m} m</td><td>{row.dv_mv} mV</td><td>{row.i_ma} mA</td><td>{row.rho_a} Ω·m</td><td>{row.x_m ?? "–"}</td><td>{new Date(row.measured_at).toLocaleString("id-ID")}</td></tr>)}
      </tbody></table></div> : <div className="empty"><b>Belum ada pengukuran</b>Tambahkan hasil geolistrik lewat menu Pengukuran.</div>}
      <div className="sec"><h2>Sampel kualitas air</h2><Link className="tx-link" href="/water">Input sampel</Link></div>
      {waterRows.length ? <div className="card table-wrap"><table className="data-table"><thead><tr><th>TDS</th><th>EC</th><th>pH</th><th>Suhu</th><th>Waktu</th></tr></thead><tbody>
        {waterRows.map((row) => <tr key={row.id}><td>{row.tds_ppm ?? "–"}</td><td>{row.ec_us ?? "–"}</td><td>{row.ph ?? "–"}</td><td>{row.temp_c ?? "–"}</td><td>{new Date(row.measured_at).toLocaleString("id-ID")}</td></tr>)}
      </tbody></table></div> : <div className="empty"><b>Belum ada sampel air</b>Catat hasil uji kualitas air melalui menu Cek Kualitas Air.</div>}
      <section className="card analysis-result">
        <div><h2>Analisis dan penampang</h2><p className="data-caption">Estimasi kedalaman memakai aturan praktis 0,5 × spasi elektroda, bukan inversi geofisika.</p></div>
        <form action={runRuleAnalysis}><input type="hidden" name="point_id" value={point.id} /><button className="btn sm" type="submit">Jalankan analisis aturan</button></form>
        {savedAnalysis ? <div className="statcards"><div><b>{savedAnalysis.verdict}</b><small>Kesimpulan indikatif</small></div><div><b>{savedAnalysis.score}/100</b><small>Skor indikatif</small></div><div><b>{savedAnalysis.best_depth ? `${savedAnalysis.best_depth} m` : "–"}</b><small>Kedalaman target</small></div></div> : <p className="hint">Jalankan analisis aturan untuk menyimpan hasil.</p>}
        {sounding.length > 1 && <><h3>Kurva sounding</h3><SoundingPlot sounding={sounding} /></>}
        {sounding.length > 0 && <><h3>Penampang 2D</h3><Section2DPlot rows={rows} /></>}
        {savedAnalysis?.result && typeof savedAnalysis.result === "object" && !Array.isArray(savedAnalysis.result) && Array.isArray((savedAnalysis.result as { layers?: unknown }).layers)
          ? <LayerColumn layers={(savedAnalysis.result as { layers: Parameters<typeof LayerColumn>[0]["layers"] }).layers} />
          : null}
        {latestWater?.notes.map((note) => <p className="hint" key={note}>{note}</p>)}
        <Link className="tx-link" href="/analysis">Buka analisis dan Gemini</Link>
        {savedAnalysis?.ai_text && <div className="placeholder-note"><b>Interpretasi Gemini</b><p style={{ whiteSpace: "pre-wrap" }}>{savedAnalysis.ai_text}</p></div>}
      </section>
      <div className="sec"><h2>Tambah pengukuran</h2></div>
      <section className="card">
        <form className="form-grid" action={addMeasurement}>
          <input type="hidden" name="point_id" value={point.id} />
          <div className="field"><label htmlFor="config">Konfigurasi</label><select id="config" name="config"><option value="wenner">Wenner</option><option value="schlumberger">Schlumberger</option></select></div>
          <div className="field"><label htmlFor="spacing_m">Spasi / AB÷2 (m)</label><input id="spacing_m" name="spacing_m" type="number" step="0.01" min="0.01" required /></div>
          <div className="field"><label htmlFor="mn_m">MN penuh (m, Schlumberger)</label><input id="mn_m" name="mn_m" type="number" step="0.001" min="0.001" /></div>
          <div className="field"><label htmlFor="dv_mv">Beda tegangan (mV)</label><input id="dv_mv" name="dv_mv" type="number" step="any" required /></div>
          <div className="field"><label htmlFor="i_ma">Arus (mA)</label><input id="i_ma" name="i_ma" type="number" step="any" min="0.000001" required /></div>
          <div className="field"><label htmlFor="device_id">ID perangkat (opsional)</label><input id="device_id" name="device_id" maxLength={100} /></div>
          <div className="field"><label htmlFor="x_m">Posisi sepanjang lintasan (m)</label><input id="x_m" name="x_m" type="number" step="any" /></div>
          <div className="field full"><button className="btn" type="submit">Simpan pengukuran</button></div>
        </form>
      </section>
      <section className="card">
        <h2>Tambah sampel air untuk titik ini</h2>
        <form className="form-grid" action={addWaterSample}>
          <input type="hidden" name="point_id" value={point.id} />
          <div className="field"><label htmlFor="tds_ppm">TDS (ppm)</label><input id="tds_ppm" name="tds_ppm" type="number" step="any" min="0" /></div>
          <div className="field"><label htmlFor="ec_us">EC (µS/cm)</label><input id="ec_us" name="ec_us" type="number" step="any" min="0" /></div>
          <div className="field"><label htmlFor="ph">pH</label><input id="ph" name="ph" type="number" step="any" min="0" max="14" /></div>
          <div className="field"><label htmlFor="temp_c">Suhu (°C)</label><input id="temp_c" name="temp_c" type="number" step="any" /></div>
          <div className="field"><label htmlFor="turbidity_ntu">Kekeruhan (NTU)</label><input id="turbidity_ntu" name="turbidity_ntu" type="number" step="any" min="0" /></div>
          <div className="field full"><button className="btn teal" type="submit">Simpan sampel air</button></div>
        </form>
      </section>
    </AppShell>
  );
}
