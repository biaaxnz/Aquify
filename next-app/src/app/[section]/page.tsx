import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { addMeasurement, addWaterSample, askGemini, runRuleAnalysis } from "@/app/actions";
import { syncFirebase } from "@/app/integration-actions";
import { AppShell } from "@/components/app-shell";
import { Icon } from "@/components/icon";
import { SurveyMap } from "@/components/survey-map";
import { LayerColumn, Section2DPlot, SoundingPlot } from "@/components/resistivity-plots";
import { analyzeSounding, assessWater, buildSounding, type RuleAnalysis } from "@/lib/analysis";
import { createClient } from "@/lib/supabase/server";

const pageInfo: Record<string, { title: string; subtitle: string; icon: string }> = {
  geolistrik: { title: "Pengukuran Tahanan Tanah", subtitle: "Masukkan data pengukuran geolistrik dan tinjau resistivitas semu.", icon: "activity" },
  map: { title: "Peta Potensi Air", subtitle: "Titik survey yang memiliki koordinat akan ditampilkan di sini.", icon: "map" },
  history: { title: "Riwayat Survey", subtitle: "Riwayat pengukuran geolistrik dan sampel air.", icon: "clock" },
  water: { title: "Cek Kualitas Air", subtitle: "Catat data parameter kualitas air dari sampel lapangan.", icon: "droplet" },
  analysis: { title: "Analisa AI", subtitle: "Analisis aturan, kurva resistivitas, dan interpretasi Gemini untuk titik surveimu.", icon: "sparkles" },
  device: { title: "Status Perangkat", subtitle: "Riwayat data ESP32 yang tersimpan pada server.", icon: "cpu" },
  notifications: { title: "Notifikasi", subtitle: "Pemberitahuan dan status ruang kerja.", icon: "bell" },
  sync: { title: "Sinkron Firebase", subtitle: "Status integrasi sumber data eksternal.", icon: "refresh" },
  settings: { title: "Pengaturan", subtitle: "Informasi akun dan status layanan.", icon: "settings" },
};

function Notice({ error, message }: { error?: string; message?: string }) {
  if (error) return <div className="alert bad" role="alert">{error}</div>;
  if (message) return <div className="alert info" role="status">{message}</div>;
  return null;
}

export default async function ModulePage({
  params,
  searchParams,
}: {
  params: Promise<{ section: string }>;
  searchParams: Promise<{ error?: string; message?: string; point?: string }>;
}) {
  const { section } = await params;
  const query = await searchParams;
  const info = pageInfo[section];
  if (!info) notFound();

  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) redirect("/login");

  const [pointsResult, profileResult] = await Promise.all([
    supabase.from("points").select("id,code,name,survey_type,latitude,longitude,created_at").eq("owner_id", user.id).order("created_at", { ascending: false }),
    supabase.from("profiles").select("full_name,role,created_at").eq("id", user.id).maybeSingle(),
  ]);
  const points = pointsResult.data ?? [];
  const pointIds = points.map((point) => point.id);
  const [measurementsResult, waterResult, analysesResult, devicesResult] = pointIds.length
    ? await Promise.all([
      supabase.from("measurements").select("id,point_id,config,spacing_m,dv_mv,i_ma,rho_a,x_m,measured_at,points(name,code)").in("point_id", pointIds).order("measured_at", { ascending: false }).limit(1000),
      supabase.from("water_samples").select("id,point_id,tds_ppm,ec_us,ph,temp_c,turbidity_ntu,measured_at,points(name,code)").in("point_id", pointIds).order("measured_at", { ascending: false }).limit(100),
      supabase.from("analyses").select("id,point_id,mode,score,verdict,best_depth,best_rho,result,ai_text,created_at,points(name,code)").in("point_id", pointIds).order("created_at", { ascending: false }).limit(100),
      supabase.from("device_readings").select("id,point_id,device_id,reading_type,source,payload,measured_at").in("point_id", pointIds).order("measured_at", { ascending: false }).limit(50),
    ])
    : [null, null, null, null];
  const queryError = pointsResult.error || profileResult.error || measurementsResult?.error || waterResult?.error || analysesResult?.error || devicesResult?.error;
  const measurements = measurementsResult?.data ?? [];
  const water = waterResult?.data ?? [];
  const analyses = analysesResult?.data ?? [];
  const devices = devicesResult?.data ?? [];
  const selectedPoint = points.find((point) => point.id === query.point) ?? points[0] ?? null;
  const selectedMeasurements = selectedPoint ? measurements.filter((row) => row.point_id === selectedPoint.id) : [];

  return (
    <AppShell active={`/${section}`} title={info.title}>
      <div className="page-title"><h2>{info.title}</h2><p>{info.subtitle}</p></div>
      <Notice error={query.error ?? (queryError?.message)} message={query.message} />

      {section === "geolistrik" && <>
        {selectedPoint && <section className="card">
          <form method="get" action="/geolistrik" className="form-grid">
            <div className="field"><label htmlFor="section-point">Titik untuk kurva 2D</label><select id="section-point" name="point" defaultValue={selectedPoint.id}>{points.map((point) => <option key={point.id} value={point.id}>{point.code} · {point.name}</option>)}</select></div>
            <div className="field" style={{ alignSelf: "end" }}><button className="btn sm" type="submit">Tampilkan data titik</button></div>
          </form>
        </section>}
        <section className="card">
          <h2>Tambah pengukuran</h2>
          <p className="hint">Resistivitas semu dihitung dengan faktor geometri Wenner atau Schlumberger.</p>
          {points.length ? <form className="form-grid" action={addMeasurement} style={{ marginTop: 16 }}>
            <div className="field full"><label htmlFor="point_id">Titik survey</label><select id="point_id" name="point_id" required defaultValue={selectedPoint?.id ?? ""}><option value="" disabled>Pilih titik</option>{points.map((point) => <option value={point.id} key={point.id}>{point.code} · {point.name}</option>)}</select></div>
            <div className="field"><label htmlFor="config">Konfigurasi</label><select id="config" name="config"><option value="wenner">Wenner</option><option value="schlumberger">Schlumberger</option></select></div>
            <div className="field"><label htmlFor="spacing_m">Spasi / AB÷2 (m)</label><input id="spacing_m" name="spacing_m" type="number" step="0.01" min="0.01" required /></div>
            <div className="field"><label htmlFor="mn_m">MN penuh (m, Schlumberger)</label><input id="mn_m" name="mn_m" type="number" step="0.001" min="0.001" /></div>
            <div className="field"><label htmlFor="dv_mv">Beda tegangan (mV)</label><input id="dv_mv" name="dv_mv" type="number" step="any" required /></div>
            <div className="field"><label htmlFor="i_ma">Arus (mA)</label><input id="i_ma" name="i_ma" type="number" step="any" min="0.000001" required /></div>
            <div className="field"><label htmlFor="device_id">ID perangkat (opsional)</label><input id="device_id" name="device_id" maxLength={100} /></div>
            <div className="field"><label htmlFor="x_m">Posisi sepanjang lintasan (m)</label><input id="x_m" name="x_m" type="number" step="any" /><small className="hint">Wajib diisi untuk membuat penampang 2D; gunakan posisi konsisten, misalnya 0, 5, 10 m.</small></div>
            <div className="field full"><button className="btn" type="submit">Simpan pengukuran</button></div>
          </form> : <div className="empty"><b>Buat titik survey dahulu</b>Pengukuran harus terhubung ke titik. <Link href="/survey/new">Buat titik survey</Link></div>}
        </section>
        <div className="sec"><h2>Data pengukuran {selectedPoint ? `· ${selectedPoint.name}` : ""}</h2><span className="muted">{selectedMeasurements.length} data</span></div>
        {selectedMeasurements.length ? <div className="card table-wrap"><table className="data-table"><thead><tr><th>Titik</th><th>Array</th><th>Spasi</th><th>Posisi x</th><th>ρ semu</th><th>Waktu</th></tr></thead><tbody>
          {selectedMeasurements.map((row) => { const p = Array.isArray(row.points) ? row.points[0] : row.points; return <tr key={row.id}><td>{p?.name ?? "Titik"}</td><td>{row.config}</td><td>{row.spacing_m} m</td><td>{row.x_m ?? "–"}</td><td>{Number(row.rho_a).toFixed(2)} Ω·m</td><td>{new Date(row.measured_at).toLocaleString("id-ID")}</td></tr>; })}
        </tbody></table></div> : <div className="empty"><b>Belum ada pengukuran</b>Data yang kamu input akan muncul di sini.</div>}
        <section className="card"><h2>Penampang resistivitas 2D {selectedPoint ? `· ${selectedPoint.name}` : ""}</h2><p className="data-caption">Penampang pseudo-2D memakai data dari satu titik terpilih. Ini hasil interpolasi, bukan hasil inversi RES2DINV.</p><Section2DPlot rows={selectedMeasurements} /></section>
      </>}

      {section === "water" && <>
        <section className="card">
          <h2>Catat sampel air</h2>
          {points.length ? <form className="form-grid" action={addWaterSample} style={{ marginTop: 16 }}>
            <div className="field full"><label htmlFor="point_id">Titik survey</label><select id="point_id" name="point_id" required defaultValue=""><option value="" disabled>Pilih titik</option>{points.map((point) => <option value={point.id} key={point.id}>{point.code} · {point.name}</option>)}</select></div>
            <div className="field"><label htmlFor="tds_ppm">TDS (ppm)</label><input id="tds_ppm" name="tds_ppm" type="number" min="0" step="any" /></div>
            <div className="field"><label htmlFor="ec_us">EC (µS/cm)</label><input id="ec_us" name="ec_us" type="number" min="0" step="any" /></div>
            <div className="field"><label htmlFor="ph">pH</label><input id="ph" name="ph" type="number" min="0" max="14" step="any" /></div>
            <div className="field"><label htmlFor="temp_c">Suhu (°C)</label><input id="temp_c" name="temp_c" type="number" step="any" /></div>
            <div className="field"><label htmlFor="turbidity_ntu">Kekeruhan (NTU)</label><input id="turbidity_ntu" name="turbidity_ntu" type="number" min="0" step="any" /></div>
            <div className="field full"><button className="btn teal" type="submit">Simpan sampel</button></div>
          </form> : <div className="empty"><b>Buat titik survey dahulu</b><Link href="/survey/new">Buat titik survey</Link></div>}
        </section>
        <div className="sec"><h2>Sampel terbaru</h2></div>
        {water.length ? <div className="card table-wrap"><table className="data-table"><thead><tr><th>Titik</th><th>TDS</th><th>EC</th><th>pH</th><th>Suhu</th><th>Penilaian indikatif</th><th>Waktu</th></tr></thead><tbody>
          {water.map((row) => {
            const p = Array.isArray(row.points) ? row.points[0] : row.points;
            const assessment = assessWater(row);
            return <tr key={row.id}><td>{p?.name ?? "Titik"}</td><td>{row.tds_ppm ?? "–"}</td><td>{row.ec_us ?? "–"}</td><td>{row.ph ?? "–"}</td><td>{row.temp_c ?? "–"}</td><td>{assessment.tdsClass ?? (assessment.notes.join(" ") || "Data belum cukup")}</td><td>{new Date(row.measured_at).toLocaleDateString("id-ID")}</td></tr>;
          })}
        </tbody></table></div> : <div className="empty"><b>Belum ada sampel</b>Tambahkan hasil uji air untuk melihatnya di sini.</div>}
        <p className="hint">Penilaian ini hanya acuan awal dari TDS/pH, bukan sertifikasi air layak minum; lakukan uji laboratorium.</p>
      </>}

      {section === "map" && <div className="stack">
        {points.some((point) => point.latitude !== null && point.longitude !== null)
          ? <SurveyMap points={points.filter((point) => point.latitude !== null && point.longitude !== null).map((point) => {
            const analysis = analyses.find((row) => row.point_id === point.id);
            return { id: point.id, code: point.code, name: point.name, latitude: point.latitude!, longitude: point.longitude!, verdict: analysis?.verdict ?? null, depth: analysis?.best_depth ?? null, rho: analysis?.best_rho ?? null, score: analysis?.score ?? null };
          })} />
          : <div className="empty"><b>Belum ada titik berkoordinat</b>Tambahkan lokasi lewat peta saat membuat survey.<Link href="/survey/new">Buat titik survey</Link></div>}
      </div>}

      {section === "history" && <>
        <div className="statcards"><div><b>{measurements.length}</b><small>Pengukuran geolistrik</small></div><div><b>{water.length}</b><small>Sampel air</small></div></div>
        <div className="sec"><h2>Titik survey</h2><Link className="tx-link" href="/points">Lihat titik</Link></div>
        {points.length ? points.map((point) => <Link className="li" href={`/points/${point.id}`} key={point.id}><span className="li-ic g"><Icon name="activity" /></span><span className="li-b"><b>{point.name}</b><small>{point.code}</small></span><Icon name="chevron" /></Link>) : <div className="empty"><b>Belum ada survey</b><Link href="/survey/new">Buat survey pertama</Link></div>}
      </>}

      {section === "analysis" && <>
        <div className="alert info">Analisis resistivitas bersifat indikatif. Validasi dengan inversi geofisika, pengukuran lapangan, dan uji kualitas air sebelum mengambil keputusan.</div>
        {points.filter((point) => point.survey_type === "geovolt").map((point) => {
          const pointMeasurements = measurements.filter((row) => row.point_id === point.id);
          const pointWater = water.find((row) => row.point_id === point.id);
          const sounding = buildSounding(pointMeasurements);
          const waterAssessment = pointWater ? assessWater(pointWater) : null;
          const preview = analyzeSounding(sounding, waterAssessment);
          const savedAnalysis = analyses.find((row) => row.point_id === point.id && row.mode === "rule");
          const savedResult = savedAnalysis?.result && typeof savedAnalysis.result === "object" && !Array.isArray(savedAnalysis.result)
            ? savedAnalysis.result as Partial<RuleAnalysis>
            : null;
          const evaluated = savedResult && Array.isArray(savedResult.layers) ? savedResult : preview;
          return <section className="card analysis-result" key={point.id}>
            <div><h2>{point.name}</h2><p className="data-caption">{point.code} · {pointMeasurements.length} pengukuran · {sounding.length} jarak berbeda</p></div>
            <form className="module-actions" action={runRuleAnalysis}><input type="hidden" name="point_id" value={point.id} /><button className="btn sm" type="submit">Jalankan analisis aturan</button></form>
            <div className="statcards"><div><b>{evaluated.verdict ?? "Data kurang"}</b><small>Kesimpulan indikatif</small></div><div><b>{evaluated.score ?? 0}/100</b><small>Skor kualitas data/indikasi</small></div><div><b>{evaluated.best?.depth ? `${evaluated.best.depth.toFixed(1)} m` : "–"}</b><small>Kedalaman target indikatif</small></div><div><b>{evaluated.best?.rho ? `${evaluated.best.rho.toFixed(1)} Ω·m` : "–"}</b><small>ρa target</small></div></div>
            {sounding.length > 1 && <><h3>Kurva sounding</h3><SoundingPlot sounding={sounding} /></>}
            {evaluated.layers?.length ? <><h3>Lapisan indikatif</h3><LayerColumn layers={evaluated.layers} /></> : null}
            {evaluated.warnings?.length ? <div className="notice-stack"><b>Perhatian</b><ul>{evaluated.warnings.map((item, index) => <li key={index}>{item}</li>)}</ul></div> : null}
            {evaluated.notes?.length ? <details><summary>Catatan interpretasi</summary><ul>{evaluated.notes.map((item, index) => <li key={index}>{item}</li>)}</ul></details> : null}
            {savedAnalysis && <form action={askGemini}>
              <input type="hidden" name="point_id" value={point.id} /><input type="hidden" name="analysis_id" value={savedAnalysis.id} />
              <button className="btn teal sm" type="submit">Tanya Gemini tentang hasil ini</button>
              <p className="hint">Data titik dan pengukuran terkait akan dikirim ke Google Gemini untuk membuat interpretasi.</p>
            </form>}
            {savedAnalysis?.ai_text && <div className="placeholder-note"><b>Interpretasi Gemini</b><p style={{ whiteSpace: "pre-wrap" }}>{savedAnalysis.ai_text}</p></div>}
          </section>;
        })}
        {!points.some((point) => point.survey_type === "geovolt") && <div className="empty"><b>Belum ada titik geolistrik</b><Link href="/survey/new">Buat survey</Link></div>}
      </>}

      {section === "device" && <>
        <div className="alert info">Kirim JSON ke endpoint ini melalui HTTPS. Token wajib dikirim sebagai header Authorization; jangan menaruh token di kode yang dibagikan atau repositori publik.</div>
        <div className="card"><h2>Endpoint ESP32</h2><p><code>{typeof process.env.NEXT_PUBLIC_APP_URL === "string" ? process.env.NEXT_PUBLIC_APP_URL : "https://domain-aplikasi-kamu"}/api/device/ingest</code></p><p className="hint">Header: <code>Authorization: Bearer &lt;ESP32_INGEST_TOKEN&gt;</code> · <code>Content-Type: application/json</code></p><p className="hint">Status endpoint: {process.env.ESP32_INGEST_TOKEN && process.env.SUPABASE_SERVICE_ROLE_KEY ? "konfigurasi tersedia" : "tambahkan ESP32_INGEST_TOKEN dan SUPABASE_SERVICE_ROLE_KEY di server"}</p><pre className="code-panel">{`{"point":"GL-ABC12345","device":"ESP32-01","measurements":[{"config":"wenner","spacing_m":1,"dv_mv":22.4,"i_ma":2.1,"x_m":5}]}`}</pre><p className="hint">Tiap titik harus memakai kode survey yang sudah dibuat di akun Aquify. Kiriman dapat berisi maksimal 200 baris.</p></div>
        {devices.length ? <div className="card table-wrap"><table className="data-table"><thead><tr><th>Perangkat</th><th>Jenis</th><th>Sumber</th><th>Waktu</th></tr></thead><tbody>{devices.map((row) => <tr key={row.id}><td>{row.device_id ?? "ESP32"}</td><td>{row.reading_type}</td><td>{row.source}</td><td>{new Date(row.measured_at).toLocaleString("id-ID")}</td></tr>)}</tbody></table></div> : <div className="empty"><b>Belum ada perangkat</b>Belum ada data perangkat yang dikirim.</div>}
      </>}

      {section === "notifications" && <div className="empty"><b>Belum ada notifikasi</b>Pemberitahuan dan notifikasi push belum dipindahkan dari versi PHP.</div>}
      {section === "sync" && <div className="card">
        <h2>Sinkron Firebase Realtime Database</h2>
        <p className="muted">Tarik data baru dari path measurements dan water di Firebase. Setiap baris harus berisi kode survey pada field point, point_id, titik, atau site; data duplikat akan dilewati.</p>
        <p className="hint">Status konfigurasi: {process.env.FIREBASE_DATABASE_URL ? "URL siap" : "URL belum diatur"} · {process.env.FIREBASE_DATABASE_TOKEN ? "token tersedia" : "tanpa token (aturan Firebase harus mengizinkan baca)"}</p>
        <form action={syncFirebase}><button className="btn" type="submit">Sinkronkan sekarang</button></form>
        <p className="hint">Isi <code>FIREBASE_DATABASE_URL</code>, opsional <code>FIREBASE_DATABASE_TOKEN</code>, dan path di konfigurasi server. Data maksimum 500 baris per jenis dalam satu proses.</p>
      </div>}
      {section === "settings" && <div className="card">
        <h2>Akun</h2><p><b>Email:</b> {user.email}</p><p><b>Nama:</b> {profileResult.data?.full_name || user.user_metadata.full_name || "—"}</p><p><b>Peran:</b> {profileResult.data?.role ?? "viewer"}</p>
        <div className="sec"><h2>Status layanan</h2></div>
        <div className="li"><span className="li-ic g"><Icon name="check" /></span><span className="li-b"><b>Supabase Auth + PostgreSQL</b><small>Terhubung</small></span></div>
        <div className="li"><span className="li-ic m"><Icon name="refresh" /></span><span className="li-b"><b>Firebase</b><small>{process.env.FIREBASE_DATABASE_URL ? "URL tersedia di server" : "Belum dikonfigurasi"}</small></span></div>
        <div className="li"><span className="li-ic m"><Icon name="sparkles" /></span><span className="li-b"><b>Gemini</b><small>{process.env.GEMINI_API_KEY ? "Kunci tersedia di server" : "Belum dikonfigurasi"}</small></span></div>
      </div>}
    </AppShell>
  );
}
