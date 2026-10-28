"use server";

import { redirect } from "next/navigation";
import { createClient } from "@/lib/supabase/server";
import { analyzeSounding, assessWater, buildSounding } from "@/lib/analysis";

function text(data: FormData, key: string): string {
  const value = data.get(key);
  return typeof value === "string" ? value.trim() : "";
}

function number(data: FormData, key: string): number | null {
  const raw = text(data, key).replace(",", ".");
  if (!raw) return null;
  const value = Number(raw);
  return Number.isFinite(value) ? value : null;
}

export async function createPoint(formData: FormData) {
  const name = text(formData, "name");
  const type = text(formData, "survey_type") === "water" ? "water" : "geovolt";
  const latitude = number(formData, "latitude");
  const longitude = number(formData, "longitude");
  if (!name) redirect("/survey/new?error=Nama%20lokasi%20wajib%20diisi");
  if ((latitude !== null && Math.abs(latitude) > 90) || (longitude !== null && Math.abs(longitude) > 180)) {
    redirect("/survey/new?error=Koordinat%20di%20luar%20rentang");
  }

  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) redirect("/login");
  const code = `${type === "water" ? "KA" : "GL"}-${crypto.randomUUID().slice(0, 8).toUpperCase()}`;
  const { data, error } = await supabase.from("points").insert({
    owner_id: user.id, code, name, survey_type: type, latitude, longitude,
  }).select("id").single();
  if (error) redirect(`/survey/new?error=${encodeURIComponent(error.message)}`);
  redirect(`/points/${data.id}`);
}

export async function addMeasurement(formData: FormData) {
  const pointId = text(formData, "point_id");
  const config = text(formData, "config") === "schlumberger" ? "schlumberger" : "wenner";
  const spacing = number(formData, "spacing_m");
  const mn = number(formData, "mn_m");
  const voltage = number(formData, "dv_mv");
  const current = number(formData, "i_ma");
  const device = text(formData, "device_id");
  const position = number(formData, "x_m");
  if (!pointId || spacing === null || spacing <= 0 || voltage === null || current === null || current <= 0) {
    redirect("/geolistrik?error=Isi%20titik%2C%20spasi%2C%20tegangan%2C%20dan%20arus%20dengan%20benar");
  }
  let factor: number;
  if (config === "schlumberger") {
    if (mn === null || mn <= 0 || mn / 2 >= spacing) redirect("/geolistrik?error=Konfigurasi%20Schlumberger%20memerlukan%20MN%20positif%20dan%20MN%2F2%20lebih%20kecil%20dari%20AB%2F2");
    factor = Math.PI * (spacing * spacing - (mn! / 2) ** 2) / mn!;
  } else {
    factor = 2 * Math.PI * spacing;
  }
  const rho = factor * Math.abs(voltage / current);
  if (!Number.isFinite(rho) || rho <= 0) redirect("/geolistrik?error=Nilai%20resistivitas%20tidak%20valid");

  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) redirect("/login");
  const { data: point, error: pointError } = await supabase.from("points").select("id")
    .eq("id", pointId).eq("owner_id", user.id).maybeSingle();
  if (pointError) redirect(`/geolistrik?error=${encodeURIComponent(pointError.message)}`);
  if (!point) redirect("/geolistrik?error=Titik%20survey%20tidak%20ditemukan%20di%20akun%20ini");
  const { error } = await supabase.from("measurements").insert({
    point_id: pointId, config, spacing_m: spacing, mn_m: mn, dv_mv: Math.abs(voltage),
    i_ma: Math.abs(current), rho_a: rho, x_m: position, device_id: device || null, source: "manual",
  });
  if (error) redirect(`/geolistrik?error=${encodeURIComponent(error.message)}`);
  redirect("/geolistrik?message=Pengukuran%20berhasil%20disimpan");
}

export async function addWaterSample(formData: FormData) {
  const pointId = text(formData, "point_id");
  const values = {
    tds_ppm: number(formData, "tds_ppm"),
    ec_us: number(formData, "ec_us"),
    ph: number(formData, "ph"),
    temp_c: number(formData, "temp_c"),
    turbidity_ntu: number(formData, "turbidity_ntu"),
  };
  if (!pointId) redirect("/water?error=Pilih%20titik%20survey");
  if (!Object.values(values).some((value) => value !== null)) redirect("/water?error=Isi%20minimal%20satu%20parameter%20air");
  if (values.ph !== null && (values.ph < 0 || values.ph > 14)) redirect("/water?error=Nilai%20pH%20harus%20antara%200%20dan%2014");

  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) redirect("/login");
  const { data: point, error: pointError } = await supabase.from("points").select("id")
    .eq("id", pointId).eq("owner_id", user.id).maybeSingle();
  if (pointError) redirect(`/water?error=${encodeURIComponent(pointError.message)}`);
  if (!point) redirect("/water?error=Titik%20survey%20tidak%20ditemukan%20di%20akun%20ini");
  const { error } = await supabase.from("water_samples").insert({
    point_id: pointId, ...values, source: "manual",
  });
  if (error) redirect(`/water?error=${encodeURIComponent(error.message)}`);
  redirect("/water?message=Sampel%20air%20berhasil%20disimpan");
}

export async function runRuleAnalysis(formData: FormData) {
  const pointId = text(formData, "point_id");
  if (!pointId) redirect("/analysis?error=Pilih%20titik%20survey");
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) redirect("/login");
  const [{ data: point, error: pointError }, measurements, water] = await Promise.all([
    supabase.from("points").select("id").eq("id", pointId).eq("owner_id", user.id).maybeSingle(),
    supabase.from("measurements").select("spacing_m,rho_a").eq("point_id", pointId).order("spacing_m"),
    supabase.from("water_samples").select("tds_ppm,ec_us,ph").eq("point_id", pointId).order("measured_at", { ascending: false }).limit(1).maybeSingle(),
  ]);
  if (pointError) redirect(`/analysis?error=${encodeURIComponent(pointError.message)}`);
  if (!point) redirect("/analysis?error=Titik%20survey%20tidak%20ditemukan");
  if (measurements.error) redirect(`/analysis?error=${encodeURIComponent(measurements.error.message)}`);
  if (water.error) redirect(`/analysis?error=${encodeURIComponent(water.error.message)}`);
  const sounding = buildSounding(measurements.data ?? []);
  const waterAssessment = water.data ? assessWater(water.data) : null;
  const result = analyzeSounding(sounding, waterAssessment);
  const best = result.best;
  const { error } = await supabase.from("analyses").insert({
    point_id: pointId,
    mode: "rule",
    score: result.score,
    verdict: result.verdict,
    best_depth: best?.depth ?? null,
    best_rho: best?.rho ?? null,
    result: { ...result, sounding, water: waterAssessment },
  });
  if (error) redirect(`/analysis?error=${encodeURIComponent(error.message)}`);
  redirect(`/analysis?message=Analisis%20aturan%20tersimpan%20untuk%20${encodeURIComponent(pointId)}`);
}

export async function askGemini(formData: FormData) {
  const pointId = text(formData, "point_id");
  const analysisId = text(formData, "analysis_id");
  if (!pointId || !analysisId) redirect("/analysis?error=Jalankan%20analisis%20aturan%20terlebih%20dahulu");
  const apiKey = process.env.GEMINI_API_KEY?.trim();
  if (!apiKey) redirect("/analysis?error=Gemini%20belum%20diatur.%20Tambahkan%20GEMINI_API_KEY%20di%20konfigurasi%20server.");

  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) redirect("/login");
  const [point, analysis, measurements, water] = await Promise.all([
    supabase.from("points").select("code,name,latitude,longitude").eq("id", pointId).eq("owner_id", user.id).maybeSingle(),
    supabase.from("analyses").select("result,ai_generated_at").eq("id", analysisId).eq("point_id", pointId).eq("mode", "rule").maybeSingle(),
    supabase.from("measurements").select("spacing_m,rho_a,measured_at").eq("point_id", pointId).order("spacing_m").limit(100),
    supabase.from("water_samples").select("tds_ppm,ec_us,ph,temp_c,turbidity_ntu,measured_at").eq("point_id", pointId).order("measured_at", { ascending: false }).limit(5),
  ]);
  if (point.error || analysis.error || measurements.error || water.error) {
    redirect(`/analysis?error=${encodeURIComponent(point.error?.message ?? analysis.error?.message ?? measurements.error?.message ?? water.error?.message ?? "Gagal memuat data analisis")}`);
  }
  if (!point.data || !analysis.data) redirect("/analysis?error=Data%20titik%20atau%20analisis%20tidak%20ditemukan");
  if (analysis.data.ai_generated_at && Date.now() - new Date(analysis.data.ai_generated_at).getTime() < 60_000) {
    redirect("/analysis?error=Tunggu%20satu%20menit%20sebelum%20meminta%20Gemini%20untuk%20hasil%20yang%20sama.");
  }
  const { data: ownedPoints, error: ownedPointsError } = await supabase.from("points").select("id").eq("owner_id", user.id);
  if (ownedPointsError) redirect(`/analysis?error=${encodeURIComponent(ownedPointsError.message)}`);
  const pointIds = (ownedPoints ?? []).map((item) => item.id);
  if (!pointIds.length) redirect("/analysis?error=Tidak%20ada%20titik%20survey");
  const { count, error: rateLimitError } = await supabase.from("analyses")
    .select("id", { count: "exact", head: true })
    .in("point_id", pointIds)
    .not("ai_generated_at", "is", null)
    .gte("ai_generated_at", new Date(Date.now() - 60 * 60 * 1000).toISOString());
  if (rateLimitError) redirect(`/analysis?error=${encodeURIComponent(rateLimitError.message)}`);
  if ((count ?? 0) >= 20) redirect("/analysis?error=Batas%2020%20permintaan%20Gemini%20per%20jam%20tercapai.");

  const baseUrl = "https://generativelanguage.googleapis.com/v1beta/models";
  const model = process.env.GEMINI_MODEL?.trim() || "gemini-3.6-flash";
  let response: Response;
  try {
    response = await fetch(`${baseUrl}/${encodeURIComponent(model)}:generateContent`, {
      method: "POST",
      headers: { "content-type": "application/json", "x-goog-api-key": apiKey },
      body: JSON.stringify({
        systemInstruction: {
          parts: [{ text: "Kamu asisten interpretasi data geolistrik dan kualitas air untuk Aquify. Jawab dalam bahasa Indonesia, bedakan data terukur dari dugaan, jelaskan keterbatasan resistivitas, jangan menjamin ada air atau memberi instruksi pengeboran sebagai kepastian. Sertakan rekomendasi verifikasi lapangan. Hasil hanya indikatif dan bukan pengganti inversi geofisika, uji laboratorium, atau penilaian ahli." }],
        },
        contents: [{
          role: "user",
          parts: [{ text: JSON.stringify({
            lokasi: point.data,
            analisis_aturan: analysis.data.result,
            pengukuran_geolistrik: measurements.data ?? [],
            sampel_air: water.data ?? [],
            tugas: "Jelaskan temuan, risiko/ketidakpastian, dan langkah verifikasi berikutnya secara ringkas dengan subjudul.",
          }) }],
        }],
        generationConfig: { temperature: 0.2, maxOutputTokens: 1200 },
      }),
      signal: AbortSignal.timeout(45000),
      cache: "no-store",
    });
  } catch {
    redirect("/analysis?error=Tidak%20dapat%20menghubungi%20Gemini.%20Periksa%20koneksi%20server%20dan%20coba%20lagi.");
  }
  const payload = await response.json().catch(() => null) as {
    candidates?: Array<{ content?: { parts?: Array<{ text?: string }> } }>;
    error?: { message?: string };
  } | null;
  const answer = payload?.candidates?.[0]?.content?.parts?.map((part) => part.text ?? "").join("").trim();
  if (!response.ok || !answer) {
    console.error("Aquify Gemini request failed", response.status, payload?.error?.message ?? "Empty model response");
    const message = response.status === 429
      ? "Kuota Gemini sedang habis atau dibatasi. Coba lagi nanti."
      : response.status === 401 || response.status === 403
        ? "Kunci Gemini ditolak. Periksa GEMINI_API_KEY pada konfigurasi server."
        : "Gemini gagal membuat jawaban. Periksa model dan pengaturan kunci API.";
    redirect(`/analysis?error=${encodeURIComponent(message)}`);
  }
  const { error } = await supabase.from("analyses").update({
    ai_text: answer.slice(0, 12000),
    ai_generated_at: new Date().toISOString(),
  }).eq("id", analysisId).eq("point_id", pointId);
  if (error) redirect(`/analysis?error=${encodeURIComponent(error.message)}`);
  redirect(`/analysis?message=Analisis%20Gemini%20selesai%20untuk%20${encodeURIComponent(pointId)}`);
}
