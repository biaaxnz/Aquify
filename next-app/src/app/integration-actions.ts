"use server";

import { redirect } from "next/navigation";
import { createClient } from "@/lib/supabase/server";

type JsonRecord = Record<string, unknown>;

function firstValue(row: JsonRecord, names: string[]): unknown {
  for (const name of names) {
    const key = Object.keys(row).find((candidate) => candidate.toLowerCase() === name.toLowerCase());
    const value = key ? row[key] : undefined;
    if (value !== undefined && value !== null && value !== "" && typeof value !== "object") return value;
  }
  return null;
}

function finiteNumber(value: unknown): number | null {
  if (typeof value !== "number" && typeof value !== "string") return null;
  const parsed = Number(String(value).replace(",", "."));
  return Number.isFinite(parsed) ? parsed : null;
}

function measuredAt(value: unknown): string {
  const numeric = typeof value === "number" || (typeof value === "string" && value.trim() !== "")
    ? Number(value)
    : Number.NaN;
  const date = Number.isFinite(numeric)
    ? new Date(numeric > 1e12 ? numeric : numeric > 1e9 ? numeric * 1000 : Number.NaN)
    : new Date(String(value ?? ""));
  return Number.isNaN(date.getTime()) ? new Date().toISOString() : date.toISOString();
}

function pointCode(row: JsonRecord): string {
  return String(firstValue(row, ["point", "point_id", "titik", "site"]) ?? "").trim().slice(0, 40);
}

async function syncFirebasePath(
  supabase: Awaited<ReturnType<typeof createClient>>,
  ownerId: string,
  path: string,
  kind: "measurements" | "water",
  token: string,
): Promise<{ fetched: number; inserted: number; duplicate: number; invalid: number }> {
  const result = { fetched: 0, inserted: 0, duplicate: 0, invalid: 0 };
  let cursor: string | null = null;
  for (let batchIndex = 0; batchIndex < 5; batchIndex++) {
    const query = new URLSearchParams({ orderBy: '"$key"', limitToFirst: "101" });
    if (cursor) {
      query.set("startAt", JSON.stringify(cursor));
    }
    if (token) query.set("auth", token);
    const url = `${process.env.FIREBASE_DATABASE_URL!.replace(/\/+$/, "")}/${path.split("/").map(encodeURIComponent).join("/")}.json?${query}`;
    let response: Response;
    try {
      response = await fetch(url, { cache: "no-store", signal: AbortSignal.timeout(20000), redirect: "error" });
    } catch {
      throw new Error("Gagal menghubungi Firebase. Periksa URL dan koneksi server.");
    }
    if (!response.ok) {
      if (response.status === 401 || response.status === 403) throw new Error("Firebase menolak akses. Periksa aturan baca atau token database.");
      throw new Error(`Firebase merespons HTTP ${response.status}.`);
    }
    const body: unknown = await response.json();
    if (!body || typeof body !== "object" || Array.isArray(body)) break;
    const rows = Object.entries(body as JsonRecord).sort(([a], [b]) => a.localeCompare(b));
    const freshRows = rows.filter(([key]) => !cursor || key > cursor).slice(0, 100);
    if (!freshRows.length) break;
    cursor = freshRows[freshRows.length - 1][0];
    result.fetched += freshRows.length;
    const validRows: Array<{ key: string; row: JsonRecord; code: string }> = [];
    for (const [key, value] of freshRows) {
      if (!value || typeof value !== "object" || Array.isArray(value)) {
        result.invalid++;
        continue;
      }
      const row = value as JsonRecord;
      const code = pointCode(row);
      if (!code) {
        result.invalid++;
        continue;
      }
      validRows.push({ key, row, code });
    }

    const pointCodes = [...new Set(validRows.map(({ code }) => code))];
    const { data: points, error: pointsError } = pointCodes.length
      ? await supabase.from("points").select("id,code").eq("owner_id", ownerId).in("code", pointCodes)
      : { data: [], error: null };
    if (pointsError) throw new Error(`Gagal mencari titik Firebase: ${pointsError.message}`);
    const pointByCode = new Map((points ?? []).map((point) => [point.code, point.id]));
    const records: Array<JsonRecord & { source: string; external_key: string }> = [];
    for (const { key, row, code } of validRows) {
      const pointId = pointByCode.get(code);
      if (!pointId) {
        result.invalid++;
        continue;
      }
      const externalKey = `${path}:${key}`.slice(0, 240);
      const timestamp = measuredAt(firstValue(row, ["ts", "timestamp", "time", "t"]));
      const device = String(firstValue(row, ["device", "device_id", "dev"]) ?? "").slice(0, 100) || null;

      if (kind === "water") {
        const values = {
          tds_ppm: finiteNumber(firstValue(row, ["tds_ppm", "tds"])),
          ec_us: finiteNumber(firstValue(row, ["ec_us", "ec_us_cm", "ec", "conductivity"])),
          ph: finiteNumber(firstValue(row, ["ph"])),
          temp_c: finiteNumber(firstValue(row, ["temp_c", "water_temp_c", "temp", "suhu"])),
          turbidity_ntu: finiteNumber(firstValue(row, ["turbidity_ntu", "turbidity", "turb", "ntu"])),
        };
        if (!Object.values(values).some((item) => item !== null) || (values.ph !== null && (values.ph < 0 || values.ph > 14))) {
          result.invalid++;
          continue;
        }
        records.push({ point_id: pointId, ...values, measured_at: timestamp, device_id: device, external_key: externalKey, raw_json: row, source: "firebase" });
      } else {
        const configName = String(firstValue(row, ["config", "cfg", "mode", "konfigurasi"]) ?? "wenner").toLowerCase();
        const config = configName.startsWith("s") ? "schlumberger" : "wenner";
        const spacing = finiteNumber(firstValue(row, ["spacing_m", "spacing", "a", "ab2", "ab_2", "jarak"]));
        const mn = finiteNumber(firstValue(row, ["mn_m", "mn"]));
        const dv = finiteNumber(firstValue(row, ["dv_mv", "dv", "delta_v", "v_mv"]));
        const current = finiteNumber(firstValue(row, ["i_ma", "i", "current_ma", "arus_ma"]));
        let rho = finiteNumber(firstValue(row, ["rho_a", "rho", "rhoa"]));
        if (!spacing || spacing <= 0 || spacing > 10000) {
          result.invalid++;
          continue;
        }
        const factor = config === "schlumberger"
          ? mn && mn > 0 && mn / 2 < spacing ? Math.PI * (spacing ** 2 - (mn / 2) ** 2) / mn : null
          : 2 * Math.PI * spacing;
        if (rho === null && dv !== null && current !== null && current > 0 && factor !== null) rho = factor * Math.abs(dv / current);
        if (rho === null || rho <= 0 || rho > 1e6) {
          result.invalid++;
          continue;
        }
        const x = finiteNumber(firstValue(row, ["x_m", "x", "pos", "position", "posisi"]));
        records.push({
          point_id: pointId, config, spacing_m: spacing, mn_m: mn, dv_mv: Math.abs(dv ?? 0),
          i_ma: Math.abs(current ?? 0), rho_a: rho, x_m: x, measured_at: timestamp, device_id: device,
          external_key: externalKey, raw_json: row, source: "firebase",
        });
      }
    }
    if (records.length) {
      const table = kind === "water" ? "water_samples" : "measurements";
      const { data, error } = await supabase.from(table)
        .upsert(records, { onConflict: "source,external_key", ignoreDuplicates: true })
        .select("id");
      if (error) throw new Error(`Gagal menyimpan data Firebase: ${error.message}`);
      result.inserted += data?.length ?? 0;
      result.duplicate += records.length - (data?.length ?? 0);
    }
    if (freshRows.length < 100 || result.fetched >= 500) break;
  }
  return result;
}

export async function syncFirebase() {
  const url = process.env.FIREBASE_DATABASE_URL?.trim();
  if (!url) redirect("/sync?error=Firebase%20belum%20diatur.%20Tambahkan%20FIREBASE_DATABASE_URL%20di%20konfigurasi%20server.");
  if (!url.startsWith("https://")) redirect("/sync?error=URL%20Firebase%20harus%20menggunakan%20HTTPS.");
  const measurementPath = (process.env.FIREBASE_MEASUREMENTS_PATH?.trim() || "measurements").replace(/^\/+|\/+$/g, "");
  const waterPath = (process.env.FIREBASE_WATER_PATH?.trim() || "water").replace(/^\/+|\/+$/g, "");
  if (!measurementPath || !waterPath || [...measurementPath.split("/"), ...waterPath.split("/")].some((part) => part === "." || part === "..")) {
    redirect("/sync?error=Path%20Firebase%20tidak%20valid.");
  }
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) redirect("/login");
  const token = process.env.FIREBASE_DATABASE_TOKEN?.trim() ?? "";
  let successMessage: string;
  try {
    const measurementResult = await syncFirebasePath(supabase, user.id, measurementPath, "measurements", token);
    const waterResult = await syncFirebasePath(supabase, user.id, waterPath, "water", token);
    const result = [measurementResult, waterResult].reduce((total, item) => ({
      fetched: total.fetched + item.fetched,
      inserted: total.inserted + item.inserted,
      duplicate: total.duplicate + item.duplicate,
      invalid: total.invalid + item.invalid,
    }), { fetched: 0, inserted: 0, duplicate: 0, invalid: 0 });
    successMessage = `Firebase selesai: ${result.inserted} baru, ${result.duplicate} duplikat, ${result.invalid} tidak valid.`;
  } catch (error) {
    const message = error instanceof Error ? error.message : "Sinkronisasi gagal.";
    redirect(`/sync?error=${encodeURIComponent(message)}`);
  }
  redirect(`/sync?message=${encodeURIComponent(successMessage)}`);
}
