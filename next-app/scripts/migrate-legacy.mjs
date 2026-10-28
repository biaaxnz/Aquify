import { spawnSync } from "node:child_process";
import { readFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const appDir = path.resolve(scriptDir, "..");
const rootDir = path.resolve(appDir, "..");
const dryRun = process.argv.includes("--dry-run");

function parseEnv(contents) {
  const result = {};
  for (const line of contents.split(/\r?\n/)) {
    const match = line.match(/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$/);
    if (!match) continue;
    let value = match[2].trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    } else {
      value = value.replace(/\s+#.*$/, "");
    }
    result[match[1]] = value;
  }
  return result;
}

function parseJson(value) {
  if (typeof value !== "string" || value.trim() === "") return null;
  try {
    return JSON.parse(value);
  } catch {
    return null;
  }
}

function nullableNumber(value) {
  if (value === null || value === undefined || value === "") return null;
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

function timestamp(value) {
  if (!value) return new Date().toISOString();
  const text = String(value).trim();
  const normalized = /^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(?:\.\d+)?$/.test(text)
    ? `${text.replace(" ", "T")}+07:00`
    : text;
  const date = new Date(normalized);
  if (Number.isNaN(date.getTime())) throw new Error("Ada waktu data lama yang tidak valid; migrasi dihentikan.");
  return date.toISOString();
}

async function requestJson(baseUrl, serviceKey, pathName, options = {}) {
  const response = await fetch(`${baseUrl}${pathName}`, {
    ...options,
    headers: {
      apikey: serviceKey,
      authorization: `Bearer ${serviceKey}`,
      ...(options.body ? { "content-type": "application/json" } : {}),
      ...(options.headers ?? {}),
    },
  });
  if (!response.ok) {
    const detail = (await response.text()).slice(0, 500);
    throw new Error(`Supabase menolak permintaan (${response.status}): ${detail}`);
  }
  if (response.status === 204) return [];
  const text = await response.text();
  return text ? JSON.parse(text) : [];
}

async function listAuthUsers(baseUrl, serviceKey) {
  const users = [];
  for (let page = 1; page <= 100; page++) {
    const batch = await requestJson(
      baseUrl,
      serviceKey,
      `/auth/v1/admin/users?page=${page}&per_page=1000`,
    );
    const rows = Array.isArray(batch) ? batch : batch.users;
    if (!Array.isArray(rows)) throw new Error("Supabase mengembalikan daftar akun dengan format tidak dikenal.");
    users.push(...rows);
    if (rows.length < 1000) return users;
  }
  throw new Error("Daftar akun Supabase melebihi batas halaman migrasi.");
}

async function insertRows(baseUrl, serviceKey, table, rows, conflictColumns) {
  let inserted = 0;
  for (let offset = 0; offset < rows.length; offset += 100) {
    const params = new URLSearchParams({ on_conflict: conflictColumns });
    const result = await requestJson(baseUrl, serviceKey, `/rest/v1/${table}?${params}`, {
      method: "POST",
      headers: { prefer: "resolution=ignore-duplicates,return=representation" },
      body: JSON.stringify(rows.slice(offset, offset + 100)),
    });
    inserted += Array.isArray(result) ? result.length : 0;
  }
  return inserted;
}

async function selectRows(baseUrl, serviceKey, table, query) {
  return requestJson(baseUrl, serviceKey, `/rest/v1/${table}?${query}`);
}

async function main() {
  const envText = await readFile(path.join(appDir, ".env.local"), "utf8").catch(() => "");
  const env = { ...parseEnv(envText), ...process.env };
  const supabaseUrl = env.NEXT_PUBLIC_SUPABASE_URL?.trim().replace(/\/+$/, "");
  const serviceKey = env.SUPABASE_SERVICE_ROLE_KEY?.trim() || env.SUPABASE_SECRET_KEY?.trim();
  if (!supabaseUrl || !serviceKey) {
    throw new Error(
      "Konfigurasi belum lengkap. Isi NEXT_PUBLIC_SUPABASE_URL dan SUPABASE_SERVICE_ROLE_KEY di next-app/.env.local. Jangan kirim kunci ke chat.",
    );
  }
  if (!/^https:\/\/[a-z0-9.-]+$/.test(supabaseUrl)) throw new Error("NEXT_PUBLIC_SUPABASE_URL harus URL HTTPS proyek Supabase.");

  const php = env.PHP_EXECUTABLE?.trim() || (process.platform === "win32" ? "C:\\xampp\\php\\php.exe" : "php");
  const exportResult = spawnSync(php, [path.join(scriptDir, "export-legacy-mysql.php")], {
    cwd: rootDir,
    encoding: "utf8",
    maxBuffer: 32 * 1024 * 1024,
    windowsHide: true,
  });
  if (exportResult.error || exportResult.status !== 0) {
    throw new Error(exportResult.stderr?.trim() || "Gagal mengekspor data lama dari MySQL.");
  }
  let legacy;
  try {
    legacy = JSON.parse(exportResult.stdout);
  } catch {
    throw new Error("Data ekspor MySQL tidak dapat dibaca sebagai JSON.");
  }

  const authUsers = await listAuthUsers(supabaseUrl, serviceKey);
  const ownerIds = new Map();
  for (const owner of legacy.owners) {
    const matches = authUsers.filter((user) => user.email?.toLowerCase() === owner.email.toLowerCase());
    if (matches.length !== 1) {
      throw new Error(
        `Email pemilik data lama harus cocok dengan tepat satu akun Supabase (ditemukan ${matches.length}). Tidak ada data yang diubah.`,
      );
    }
    ownerIds.set(owner.email.toLowerCase(), matches[0].id);
  }

  const targetOwnerId = (legacyOwnerEmail) => {
    const id = ownerIds.get(legacyOwnerEmail.toLowerCase());
    if (!id) throw new Error("Ada catatan yang pemiliknya tidak dapat dipetakan. Tidak ada data yang diubah.");
    return id;
  };
  const legacyOwnerById = new Map(legacy.owners.map((owner) => [String(owner.legacy_id), owner]));
  const pointOwnerByLegacyId = new Map();
  const pointRecords = legacy.points.map((point) => {
    const legacyOwner = legacyOwnerById.get(String(point.owner_id));
    if (!legacyOwner) throw new Error("Ada titik yang pemiliknya tidak dapat dipetakan. Tidak ada data yang diubah.");
    const ownerId = targetOwnerId(legacyOwner.email);
    pointOwnerByLegacyId.set(String(point.id), ownerId);
    return {
      owner_id: ownerId,
      code: point.code,
      name: point.name,
      survey_type: point.survey_type === "water" ? "water" : "geovolt",
      latitude: nullableNumber(point.lat),
      longitude: nullableNumber(point.lng),
      notes: point.notes,
      team: point.team,
      plan: parseJson(point.plan_json) ?? {},
      created_at: timestamp(point.created_at),
    };
  });

  for (const record of [...legacy.measurements, ...legacy.water_samples, ...legacy.analyses, ...legacy.sensor_readings]) {
    if (record.point_id !== null && !pointOwnerByLegacyId.has(String(record.point_id))) {
      throw new Error("Ada data yang menunjuk ke titik lama yang tidak ikut diekspor. Tidak ada data yang diubah.");
    }
  }

  if (dryRun) {
    console.log("Pemeriksaan migrasi selesai; belum ada data yang ditulis.");
    console.log(`Titik: ${legacy.points.length}; pengukuran: ${legacy.measurements.length}; sampel air: ${legacy.water_samples.length}; analisis: ${legacy.analyses.length}; pembacaan sensor: ${legacy.sensor_readings.length}.`);
    return;
  }

  const profiles = new Map();
  for (const owner of legacy.owners) {
    const ownerId = targetOwnerId(owner.email);
    profiles.set(ownerId, { id: ownerId, full_name: owner.full_name || "" });
  }
  await insertRows(supabaseUrl, serviceKey, "profiles", [...profiles.values()], "id");
  await insertRows(supabaseUrl, serviceKey, "points", pointRecords, "owner_id,code");

  const ownedPoints = [];
  for (const [ownerId] of profiles) {
    const query = new URLSearchParams({
      select: "id,owner_id,code",
      owner_id: `eq.${ownerId}`,
      limit: "1000",
    });
    ownedPoints.push(...await selectRows(supabaseUrl, serviceKey, "points", query));
  }
  const targetPointByLegacyId = new Map();
  const targetPointByOwnerAndCode = new Map(ownedPoints.map((point) => [`${point.owner_id ?? ""}:${point.code}`, point.id]));
  const pointsWithOwners = new Map();
  for (const point of legacy.points) {
    const legacyOwner = legacyOwnerById.get(String(point.owner_id));
    if (!legacyOwner) throw new Error("Ada titik yang pemiliknya tidak dapat dipetakan.");
    const ownerId = targetOwnerId(legacyOwner.email);
    pointsWithOwners.set(String(point.id), { ownerId, code: point.code });
  }
  for (const [legacyId, value] of pointsWithOwners) {
    const id = targetPointByOwnerAndCode.get(`${value.ownerId}:${value.code}`);
    if (!id) throw new Error("Titik berhasil dikirim tetapi tidak dapat ditemukan kembali di Supabase.");
    targetPointByLegacyId.set(legacyId, id);
  }

  const measurements = legacy.measurements.map((row) => ({
    point_id: targetPointByLegacyId.get(String(row.point_id)),
    device_id: row.device_id,
    config: row.config === "schlumberger" ? "schlumberger" : "wenner",
    spacing_m: nullableNumber(row.spacing_m),
    mn_m: nullableNumber(row.mn_m),
    dv_mv: nullableNumber(row.dv_mv) ?? 0,
    i_ma: nullableNumber(row.i_ma) ?? 0,
    rho_a: nullableNumber(row.rho_a),
    x_m: nullableNumber(row.x_m),
    source: row.source || "mysql_import",
    external_key: `mysql:${row.id}`,
    raw_json: parseJson(row.raw_json),
    measured_at: timestamp(row.measured_at),
    created_at: timestamp(row.created_at),
  }));
  const waterSamples = legacy.water_samples.map((row) => ({
    point_id: targetPointByLegacyId.get(String(row.point_id)),
    device_id: row.device_id,
    tds_ppm: nullableNumber(row.tds_ppm),
    ec_us: nullableNumber(row.ec_us),
    ph: nullableNumber(row.ph),
    temp_c: nullableNumber(row.temp_c),
    turbidity_ntu: nullableNumber(row.turbidity_ntu),
    source: row.source || "mysql_import",
    external_key: `mysql:${row.id}`,
    raw_json: parseJson(row.raw_json),
    measured_at: timestamp(row.measured_at),
    created_at: timestamp(row.created_at),
  }));

  const pointIds = [...targetPointByLegacyId.values()];
  const existingAnalyses = [];
  if (pointIds.length) {
    for (let offset = 0; ; offset += 1000) {
      const analysesQuery = new URLSearchParams({
        select: "point_id,result",
        point_id: `in.(${pointIds.join(",")})`,
        limit: "1000",
        offset: String(offset),
      });
      const batch = await selectRows(supabaseUrl, serviceKey, "analyses", analysesQuery);
      existingAnalyses.push(...batch);
      if (batch.length < 1000) break;
    }
  }
  const existingAnalysisKeys = new Set(
    existingAnalyses.map((row) => row.result?._aquify_mysql_id).filter((value) => value !== undefined).map(String),
  );
  const analyses = legacy.analyses
    .filter((row) => !existingAnalysisKeys.has(String(row.id)))
    .map((row) => {
      const sourceResult = parseJson(row.result_json);
      const result = sourceResult && typeof sourceResult === "object" && !Array.isArray(sourceResult)
        ? { ...sourceResult, _aquify_mysql_id: String(row.id) }
        : { _aquify_mysql_id: String(row.id), _aquify_legacy_result: sourceResult };
      return {
        point_id: targetPointByLegacyId.get(String(row.point_id)),
        mode: row.mode === "ai" ? "ai" : "rule",
        score: Math.max(0, Math.min(100, Math.trunc(nullableNumber(row.score) ?? 0))),
        verdict: row.verdict || "",
        best_depth: nullableNumber(row.best_depth),
        best_rho: nullableNumber(row.best_rho),
        result,
        ai_text: row.ai_text,
        created_at: timestamp(row.created_at),
      };
    });

  const deviceReadings = legacy.sensor_readings.map((row) => ({
    point_id: row.point_id === null ? null : targetPointByLegacyId.get(String(row.point_id)),
    device_id: row.device_id,
    reading_type: ["water", "geoelectric", "other"].includes(row.reading_type) ? row.reading_type : "other",
    source: "mysql_import",
    external_key: `mysql:sensor:${row.id}`,
    measured_at: timestamp(row.measured_at ?? row.created_at),
    payload: {
      reading_uuid: row.reading_uuid,
      latitude: nullableNumber(row.latitude),
      longitude: nullableNumber(row.longitude),
      voltage_mv: nullableNumber(row.voltage_mv),
      current_ma: nullableNumber(row.current_ma),
      electrode_spacing_m: nullableNumber(row.electrode_spacing_m),
      mn_m: nullableNumber(row.mn_m),
      k_factor_m: nullableNumber(row.k_factor_m),
      apparent_resistivity_ohm_m: nullableNumber(row.apparent_resistivity_ohm_m),
      ph: nullableNumber(row.ph),
      tds_ppm: nullableNumber(row.tds_ppm),
      ec_us: nullableNumber(row.ec_us),
      temp_c: nullableNumber(row.temp_c),
      turbidity_ntu: nullableNumber(row.turbidity_ntu),
      raw_json: parseJson(row.raw_json),
    },
  }));

  const inserted = {
    profiles: 0,
    points: pointRecords.length,
    measurements: await insertRows(supabaseUrl, serviceKey, "measurements", measurements, "source,external_key"),
    water_samples: await insertRows(supabaseUrl, serviceKey, "water_samples", waterSamples, "source,external_key"),
    analyses: 0,
    device_readings: await insertRows(supabaseUrl, serviceKey, "device_readings", deviceReadings, "source,external_key"),
  };
  if (analyses.length) {
    for (let offset = 0; offset < analyses.length; offset += 100) {
      await requestJson(supabaseUrl, serviceKey, "/rest/v1/analyses", {
        method: "POST",
        body: JSON.stringify(analyses.slice(offset, offset + 100)),
      });
    }
    inserted.analyses = analyses.length;
  }

  console.log("Pemindahan data MySQL ke Supabase selesai.");
  console.log(`Titik dipetakan: ${legacy.points.length}; pengukuran baru: ${inserted.measurements}; sampel air baru: ${inserted.water_samples}; analisis baru: ${inserted.analyses}; pembacaan sensor baru: ${inserted.device_readings}.`);
  console.log("Data MySQL XAMPP lama tidak dihapus.");
}

main().catch((error) => {
  console.error(error instanceof Error ? error.message : "Pemindahan gagal.");
  process.exitCode = 1;
});
