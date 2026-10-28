import { createHash, timingSafeEqual } from "node:crypto";
import { createClient } from "@supabase/supabase-js";
import { NextResponse } from "next/server";

export const runtime = "nodejs";

type JsonRecord = Record<string, unknown>;

function isRecord(value: unknown): value is JsonRecord {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}

function pick(row: JsonRecord, names: string[]): unknown {
  for (const name of names) {
    const key = Object.keys(row).find((candidate) => candidate.toLowerCase() === name.toLowerCase());
    const value = key ? row[key] : undefined;
    if (value !== undefined && value !== null && value !== "" && typeof value !== "object") return value;
  }
  return null;
}

function toNumber(value: unknown): number | null {
  if (typeof value !== "number" && typeof value !== "string") return null;
  const parsed = Number(String(value).replace(",", "."));
  return Number.isFinite(parsed) ? parsed : null;
}

function timestamp(value: unknown): string {
  const numeric = typeof value === "number" || (typeof value === "string" && value.trim() !== "")
    ? Number(value)
    : Number.NaN;
  const date = Number.isFinite(numeric)
    ? new Date(numeric > 1e12 ? numeric : numeric > 1e9 ? numeric * 1000 : Number.NaN)
    : new Date(String(value ?? ""));
  return Number.isNaN(date.getTime()) ? new Date().toISOString() : date.toISOString();
}

function matchesToken(given: string, expected: string): boolean {
  const givenBytes = Buffer.from(given);
  const expectedBytes = Buffer.from(expected);
  return givenBytes.length === expectedBytes.length && timingSafeEqual(givenBytes, expectedBytes);
}

export async function POST(request: Request) {
  const expectedToken = process.env.ESP32_INGEST_TOKEN?.trim();
  const serviceRoleKey = process.env.SUPABASE_SERVICE_ROLE_KEY?.trim();
  const supabaseUrl = process.env.NEXT_PUBLIC_SUPABASE_URL?.trim();
  if (!expectedToken || !serviceRoleKey || !supabaseUrl) {
    return NextResponse.json({ error: "Endpoint belum dikonfigurasi oleh administrator." }, { status: 503 });
  }
  const authHeader = request.headers.get("authorization") ?? "";
  const givenToken = authHeader.startsWith("Bearer ") ? authHeader.slice(7).trim() : "";
  if (!givenToken || !matchesToken(givenToken, expectedToken)) {
    return NextResponse.json({ error: "Token perangkat tidak valid." }, { status: 401 });
  }
  const contentLength = Number(request.headers.get("content-length") ?? 0);
  if (contentLength > 128_000) return NextResponse.json({ error: "Ukuran payload melebihi 128 KB." }, { status: 413 });
  const bodyText = await request.text();
  if (Buffer.byteLength(bodyText, "utf8") > 128_000) return NextResponse.json({ error: "Ukuran payload melebihi 128 KB." }, { status: 413 });
  let body: unknown;
  try {
    body = JSON.parse(bodyText);
  } catch {
    return NextResponse.json({ error: "Body harus berupa JSON yang valid." }, { status: 400 });
  }
  if (!isRecord(body)) return NextResponse.json({ error: "Body harus berupa objek JSON." }, { status: 400 });

  const measurements = Array.isArray(body.measurements) ? body.measurements : [];
  const water = Array.isArray(body.water) ? body.water : [];
  const genericReadings = Array.isArray(body.readings) ? body.readings : [];
  const generic = measurements.length || water.length || genericReadings.length ? [] : [body];
  const records = [
    ...measurements.map((row) => ({ kind: "geoelectric" as const, row })),
    ...water.map((row) => ({ kind: "water" as const, row })),
    ...genericReadings.map((row) => ({ kind: "other" as const, row })),
    ...generic.map((row) => ({ kind: "other" as const, row })),
  ];
  if (!records.length || records.length > 200 || records.some(({ row }) => !isRecord(row))) {
    return NextResponse.json({ error: "Kirim 1–200 objek pada measurements, water, readings, atau satu objek pengukuran." }, { status: 400 });
  }

  const supabase = createClient(supabaseUrl, serviceRoleKey, {
    auth: { autoRefreshToken: false, persistSession: false },
  });
  const status = { ok: true, received: records.length, inserted: 0, duplicate: 0, invalid: 0 };
  const codes = [...new Set(records.flatMap(({ row }) => isRecord(row)
    ? [String(pick(row, ["point", "point_code", "point_id", "titik", "site"]) ?? "").trim().slice(0, 40)].filter(Boolean)
    : []))];
  const { data: matchingPoints, error: pointsError } = codes.length
    ? await supabase.from("points").select("id,code").in("code", codes)
    : { data: [], error: null };
  if (pointsError) {
    console.error("Aquify device ingest point lookup failed", pointsError.message);
    return NextResponse.json({ error: "Gagal memeriksa titik tujuan." }, { status: 500 });
  }
  const pointIdsByCode = new Map<string, string[]>();
  for (const point of matchingPoints ?? []) {
    pointIdsByCode.set(point.code, [...(pointIdsByCode.get(point.code) ?? []), point.id]);
  }
  const measurementRows: JsonRecord[] = [];
  const waterRows: JsonRecord[] = [];
  const historyRows: JsonRecord[] = [];

  for (let index = 0; index < records.length; index++) {
    const { kind: requestedKind, row } = records[index];
    if (!isRecord(row)) {
      status.invalid++;
      continue;
    }
    const code = String(pick(row, ["point", "point_code", "point_id", "titik", "site"]) ?? "").trim().slice(0, 40);
    if (!code) {
      status.invalid++;
      continue;
    }
    const pointIds = pointIdsByCode.get(code);
    if (!pointIds || pointIds.length !== 1) {
      status.invalid++;
      continue;
    }
    const pointId = pointIds[0];
    const raw = JSON.stringify(row);
    const externalKey = String(pick(row, ["id", "event_id", "reading_uuid", "key"]) ?? createHash("sha256").update(raw).update(`:${index}`).digest("hex")).slice(0, 240);
    const measuredAt = timestamp(pick(row, ["ts", "timestamp", "time", "measured_at"]));
    const device = String(pick(row, ["device", "device_id", "dev"]) ?? "").slice(0, 100) || null;
    const declaredType = String(pick(row, ["reading_type", "type"]) ?? "").toLowerCase();
    const readingType = requestedKind === "other"
      ? declaredType === "water" || declaredType === "geoelectric"
        ? declaredType
        : ["tds_ppm", "tds_mg_l", "tds", "ph", "ec_us", "turbidity"].some((key) => key in row)
          ? "water"
          : "geoelectric"
      : requestedKind;

    if (readingType === "water") {
      const sample = {
        tds_ppm: toNumber(pick(row, ["tds_ppm", "tds_mg_l", "tds"])),
        ec_us: toNumber(pick(row, ["ec_us", "ec_us_cm", "ec", "conductivity"])),
        ph: toNumber(pick(row, ["ph"])),
        temp_c: toNumber(pick(row, ["temp_c", "water_temp_c", "temp", "suhu"])),
        turbidity_ntu: toNumber(pick(row, ["turbidity_ntu", "turbidity", "turb", "ntu"])),
      };
      if (!Object.values(sample).some((value) => value !== null) || (sample.ph !== null && (sample.ph < 0 || sample.ph > 14))) {
        status.invalid++;
        continue;
      }
      waterRows.push({
        point_id: pointId, ...sample, measured_at: measuredAt, device_id: device, source: "esp32", external_key: externalKey, raw_json: row,
      });
    } else {
      const configName = String(pick(row, ["config", "cfg", "mode", "konfigurasi"]) ?? "wenner").toLowerCase();
      const config = configName.startsWith("s") ? "schlumberger" : "wenner";
      const spacing = toNumber(pick(row, ["spacing_m", "spacing", "a", "ab2", "ab_2", "jarak"]));
      const mn = toNumber(pick(row, ["mn_m", "mn"]));
      const voltage = toNumber(pick(row, ["dv_mv", "dv", "delta_v", "v_mv"]));
      const current = toNumber(pick(row, ["i_ma", "i", "current_ma", "arus_ma"]));
      let rho = toNumber(pick(row, ["rho_a", "rho", "rhoa", "apparent_resistivity_ohm_m"]));
      if (!spacing || spacing <= 0 || spacing > 10000) {
        status.invalid++;
        continue;
      }
      const factor = config === "wenner"
        ? 2 * Math.PI * spacing
        : mn && mn > 0 && mn / 2 < spacing ? Math.PI * (spacing ** 2 - (mn / 2) ** 2) / mn : null;
      if (rho === null && voltage !== null && current !== null && current !== 0 && factor !== null) rho = factor * Math.abs(voltage / current);
      if (rho === null || rho <= 0 || rho > 1e6) {
        status.invalid++;
        continue;
      }
      const x = toNumber(pick(row, ["x_m", "x", "pos", "position", "posisi"]));
      measurementRows.push({
        point_id: pointId, config, spacing_m: spacing, mn_m: mn, dv_mv: Math.abs(voltage ?? 0), i_ma: Math.abs(current ?? 0),
        rho_a: rho, x_m: x, measured_at: measuredAt, device_id: device, source: "esp32", external_key: externalKey, raw_json: row,
      });
    }
    historyRows.push({
      point_id: pointId, device_id: device, reading_type: readingType, source: "esp32",
      payload: row, measured_at: measuredAt, external_key: externalKey,
    });
  }

  const [measurementResult, waterResult] = await Promise.all([
    measurementRows.length
      ? supabase.from("measurements").upsert(measurementRows, { onConflict: "source,external_key", ignoreDuplicates: true }).select("id")
      : Promise.resolve({ data: [], error: null }),
    waterRows.length
      ? supabase.from("water_samples").upsert(waterRows, { onConflict: "source,external_key", ignoreDuplicates: true }).select("id")
      : Promise.resolve({ data: [], error: null }),
  ]);
  if (measurementResult.error || waterResult.error) {
    console.error("Aquify device ingest save failed", measurementResult.error?.message ?? waterResult.error?.message);
    return NextResponse.json({ error: "Gagal menyimpan data perangkat." }, { status: 500 });
  }
  const measurementsInserted = measurementResult.data?.length ?? 0;
  const waterInserted = waterResult.data?.length ?? 0;
  status.inserted = measurementsInserted + waterInserted;
  status.duplicate = measurementRows.length + waterRows.length - status.inserted;

  if (historyRows.length) {
    const { error } = await supabase.from("device_readings")
      .upsert(historyRows, { onConflict: "source,external_key", ignoreDuplicates: true });
    if (error) {
      console.error("Aquify device ingest history insert failed", error.message);
      return NextResponse.json({ error: "Data pengukuran tersimpan, tetapi riwayat perangkat gagal diperbarui." }, { status: 500 });
    }
  }
  return NextResponse.json(status, { status: 200 });
}
