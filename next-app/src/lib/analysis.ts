export type Measurement = {
  spacing_m: number;
  rho_a: number;
};

export type SoundingPoint = {
  spacing: number;
  rho: number;
  count: number;
  spread: number;
  depth: number;
};

export type ResistivityClass = {
  key: "saline" | "brackish" | "aquifer" | "dry" | "rock";
  label: string;
  color: string;
};

export type Layer = {
  from: number;
  to: number;
  key: ResistivityClass["key"];
  label: string;
  color: string;
  rho: number;
  points: number;
};

export type WaterAssessment = {
  tds: number | null;
  ec: number | null;
  ph: number | null;
  temp: number | null;
  turbidity: number | null;
  tdsClass: string | null;
  overallClass: string;
  summary: string;
  parameters: Array<{
    label: string;
    value: number | null;
    unit: string;
    range: string;
    status: string;
    concern: boolean;
    grade: 0 | 1 | 2 | null;
  }>;
  waterResistivity: number | null;
  expectedResistivity: [number, number] | null;
  saline: boolean;
  notes: string[];
};

export type RuleAnalysis = {
  count: number;
  layers: Layer[];
  minima: number[];
  best: { depth: number; rho: number; spacing: number; class: string; key: string } | null;
  score: number;
  verdict: string;
  drillDepth: number | null;
  warnings: string[];
  notes: string[];
  maxDepth: number;
};

function median(values: number[]): number {
  const sorted = [...values].sort((a, b) => a - b);
  const middle = Math.floor(sorted.length / 2);
  return sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
}

export function classifyResistivity(rho: number): ResistivityClass {
  if (rho < 10) return { key: "saline", label: "Lempung / air asin", color: "#A8432F" };
  if (rho < 25) return { key: "brackish", label: "Air payau / lempung basah", color: "#C98A2B" };
  if (rho < 150) return { key: "aquifer", label: "Akuifer potensial", color: "#0E7C86" };
  if (rho < 500) return { key: "dry", label: "Kering / padat", color: "#8C9079" };
  return { key: "rock", label: "Batuan keras", color: "#4B4F57" };
}

export function buildSounding(rows: Measurement[], depthFactor = 0.5): SoundingPoint[] {
  const grouped = new Map<string, number[]>();
  for (const row of rows) {
    if (!Number.isFinite(row.rho_a) || row.rho_a <= 0 || !Number.isFinite(row.spacing_m) || row.spacing_m <= 0) continue;
    const spacing = row.spacing_m.toFixed(2);
    grouped.set(spacing, [...(grouped.get(spacing) ?? []), row.rho_a]);
  }
  return [...grouped.entries()]
    .sort(([a], [b]) => Number(a) - Number(b))
    .map(([spacingText, values]) => {
      const rho = median(values);
      return {
        spacing: Number(spacingText),
        rho,
        count: values.length,
        spread: rho > 0 ? (Math.max(...values) - Math.min(...values)) / rho : 0,
        depth: depthFactor * Number(spacingText),
      };
    });
}

export function assessWater(sample: {
  tds_ppm: number | null;
  ec_us: number | null;
  ph: number | null;
  temp_c?: number | null;
  turbidity_ntu?: number | null;
}): WaterAssessment {
  const tds = sample.tds_ppm;
  const measuredEc = sample.ec_us ?? null;
  const ec = measuredEc ?? (tds === null ? null : tds / 0.64);
  const ph = sample.ph;
  const temp = sample.temp_c ?? null;
  const turbidity = sample.turbidity_ntu ?? null;
  const parameters: WaterAssessment["parameters"] = [
    {
      label: "pH",
      value: ph,
      unit: "",
      range: "7,0–7,9 paling baik; 6,5–7,0 atau 8,0–8,5 baik",
      status: ph === null ? "Belum diukur" : ph < 6.5 || ph > 8.5 ? "Perlu perhatian" : ph >= 7 && ph < 8 ? "Paling baik (indikatif)" : "Baik (indikatif)",
      concern: ph !== null && (ph < 6.5 || ph > 8.5),
      grade: ph === null ? null : ph < 6.5 || ph > 8.5 ? 0 : ph >= 7 && ph < 8 ? 2 : 1,
    },
    {
      label: "TDS",
      value: tds,
      unit: "ppm",
      range: "10–<50 paling baik; 50–300 baik; >300 perlu perhatian",
      status: tds === null ? "Belum diukur" : tds > 300 ? "Perlu perhatian" : tds < 10 ? "Di luar rentang acuan" : tds < 50 ? "Paling baik (indikatif)" : "Baik (indikatif)",
      concern: tds !== null && tds > 300,
      grade: tds === null || tds < 10 ? null : tds > 300 ? 0 : tds < 50 ? 2 : 1,
    },
    {
      label: "EC",
      value: measuredEc,
      unit: "µS/cm",
      range: "<100 paling baik; 100–400 baik; >400 perlu perhatian",
      status: measuredEc === null ? "Belum diukur" : measuredEc > 400 ? "Perlu perhatian" : measuredEc < 100 ? "Paling baik (indikatif)" : "Baik (indikatif)",
      concern: measuredEc !== null && measuredEc > 400,
      grade: measuredEc === null ? null : measuredEc > 400 ? 0 : measuredEc < 100 ? 2 : 1,
    },
    {
      label: "Suhu",
      value: temp,
      unit: "°C",
      range: "20–22 paling baik; 23–28 baik; <15 perlu perhatian; pembanding suhu udara belum tersedia",
      status: temp === null ? "Belum diukur" : temp < 15 ? "Perlu perhatian" : temp >= 20 && temp <= 22 ? "Paling baik (indikatif)" : temp >= 23 && temp <= 28 ? "Baik (indikatif)" : "Perlu konteks",
      concern: temp !== null && temp < 15,
      grade: temp === null || (temp >= 15 && temp < 20) || (temp > 22 && temp < 23) || temp > 28
        ? null
        : temp < 15 ? 0 : temp >= 20 && temp <= 22 ? 2 : 1,
    },
    {
      label: "Kekeruhan",
      value: turbidity,
      unit: "NTU",
      range: "<1 paling baik; 1–3 baik; >3 perlu perhatian",
      status: turbidity === null ? "Belum diukur" : turbidity > 3 ? "Perlu perhatian" : turbidity < 1 ? "Paling baik (indikatif)" : "Baik (indikatif)",
      concern: turbidity !== null && turbidity > 3,
      grade: turbidity === null ? null : turbidity > 3 ? 0 : turbidity < 1 ? 2 : 1,
    },
  ];
  const hasAnyValue = parameters.some((parameter) => parameter.value !== null);
  const hasConcern = parameters.some((parameter) => parameter.concern);
  const hasIncompleteIndicator = parameters.some((parameter) => parameter.value === null || parameter.grade === null);
  const overallClass = !hasAnyValue
    ? "Data belum cukup"
    : hasConcern
      ? "Kurang / perlu tindak lanjut"
      : hasIncompleteIndicator
        ? "Data belum lengkap / perlu interpretasi"
        : parameters.every((parameter) => parameter.grade === 2)
          ? "Paling baik (indikatif)"
          : "Baik (indikatif)";
  const tdsClass = parameters[1].status;
  const concerns = parameters.filter((parameter) => parameter.concern || (parameter.value !== null && parameter.grade === null));
  const summary = concerns.length
    ? concerns.map((parameter) => `${parameter.label}: ${parameter.status.toLowerCase()}`).join("; ")
    : hasIncompleteIndicator ? "Lengkapi semua indikator dan tinjau rentang yang memerlukan konteks." : "Semua indikator terukur berada pada rentang acuan baik.";
  const notes = concerns.map((parameter) => `${parameter.label} ${parameter.value}${parameter.unit ? ` ${parameter.unit}` : ""}: ${parameter.status.toLowerCase()}.`);
  const waterResistivity = ec !== null && ec > 0 ? 10000 / ec : null;
  return {
    tds,
    ec,
    ph,
    temp,
    turbidity,
    tdsClass,
    overallClass,
    summary,
    parameters,
    waterResistivity,
    expectedResistivity: waterResistivity === null ? null : [3 * waterResistivity, 9 * waterResistivity],
    saline: tds !== null && tds >= 1200,
    notes,
  };
}

function buildLayers(sounding: SoundingPoint[]): Layer[] {
  const layers: Array<Omit<Layer, "rho"> & { values: number[] }> = [];
  sounding.forEach((point, index) => {
    const classification = classifyResistivity(point.rho);
    const from = index === 0 ? 0 : (sounding[index - 1].depth + point.depth) / 2;
    const to = index === sounding.length - 1 ? point.depth * 1.15 : (point.depth + sounding[index + 1].depth) / 2;
    const previous = layers[layers.length - 1];
    if (previous?.key === classification.key) {
      previous.to = to;
      previous.values.push(point.rho);
      previous.points += 1;
    } else {
      layers.push({ from, to, key: classification.key, label: classification.label, color: classification.color, values: [point.rho], points: 1 });
    }
  });
  return layers.map(({ values, ...layer }) => ({ ...layer, rho: median(values) }));
}

export function analyzeSounding(sounding: SoundingPoint[], water: WaterAssessment | null = null): RuleAnalysis {
  const count = sounding.length;
  const result: RuleAnalysis = {
    count, layers: [], minima: [], best: null, score: 0, verdict: "Data kurang",
    drillDepth: null, warnings: [], notes: [], maxDepth: count ? sounding[count - 1].depth : 0,
  };
  if (count < 3) {
    result.warnings.push(`Butuh minimal 3 jarak elektroda berbeda untuk membaca kurva sounding (saat ini ${count}).`);
    return result;
  }

  result.layers = buildLayers(sounding);
  for (let i = 1; i < count - 1; i++) {
    if (sounding[i].rho < 0.85 * Math.min(sounding[i - 1].rho, sounding[i + 1].rho)) result.minima.push(i);
  }
  if (sounding[count - 1].rho < 0.8 * sounding[count - 2].rho) {
    result.notes.push("Resistivitas masih turun di jarak terbesar: ada lapisan konduktif lebih dalam yang belum tertutup kurva. Tambah jarak elektroda.");
  }

  let candidates = sounding.flatMap((point, index) => classifyResistivity(point.rho).key === "aquifer" ? [index] : []);
  const brackishOnly = candidates.length === 0;
  if (brackishOnly) candidates = sounding.flatMap((point, index) => classifyResistivity(point.rho).key === "brackish" ? [index] : []);
  candidates.sort((a, b) => Number(!result.minima.includes(a)) - Number(!result.minima.includes(b)) || sounding[a].rho - sounding[b].rho);
  const bestIndex = candidates[0] ?? null;
  const spreadMedian = median(sounding.map((point) => point.spread));
  let score = 0;

  if (bestIndex !== null) {
    const point = sounding[bestIndex];
    const classification = classifyResistivity(point.rho);
    result.best = { depth: point.depth, rho: point.rho, spacing: point.spacing, class: classification.label, key: classification.key };
    score = brackishOnly ? 25 : 50;
    if (result.minima.includes(bestIndex)) score += 15;
    if (result.layers.some((layer) => layer.key === classification.key && layer.points >= 2 && point.depth >= layer.from && point.depth <= layer.to)) score += 10;
    if (bestIndex > 0 && sounding[0].rho > 1.5 * point.rho) score += 5;
    result.drillDepth = Math.max(5, Math.ceil((point.depth * 1.3) / 5) * 5);
    if (brackishOnly) result.warnings.push("Tidak ada lapisan 25–150 Ω·m; lapisan konduktif yang ada kemungkinan air payau atau lempung basah.");
    if (point.rho < 10) result.warnings.push("Resistivitas sangat rendah (<10 Ω·m): bisa lempung jenuh atau air asin, bukan air tawar yang mudah dipompa.");
  } else {
    score = 10;
    result.notes.push("Seluruh kurva berada di atas 150 Ω·m (kering/padat/batuan) atau di bawah 10 Ω·m (lempung/asin). Tidak ada indikasi akuifer air tawar pada kedalaman terjangkau.");
  }

  if (count >= 6) score += 10;
  else if (count >= 4) score += 5;
  if (spreadMedian < 0.25) score += 10;
  else if (spreadMedian > 0.6) {
    score -= 10;
    result.warnings.push(`Pengulangan ukur di jarak yang sama beda jauh (median sebaran ${Math.round(spreadMedian * 100)}%). Periksa kontak elektroda.`);
  }
  for (let i = 1; i < count; i++) {
    const ratio = sounding[i].rho / sounding[i - 1].rho;
    if (ratio > 5 || ratio < 0.2) {
      result.warnings.push(`Lonjakan >5× antara jarak ${sounding[i - 1].spacing} m dan ${sounding[i].spacing} m. Cek ulang titik tersebut.`);
      break;
    }
  }

  if (water) {
    result.notes.push(...water.notes.map((note) => `Air: ${note}`));
    if (water.saline) {
      score -= 15;
      result.warnings.push("Sampel air ber-TDS sangat tinggi: air kemungkinan payau/asin walau resistivitas tampak akuifer.");
    }
    if (result.best && water.expectedResistivity) {
      const [low, high] = water.expectedResistivity;
      if (result.best.rho >= low * 0.7 && result.best.rho <= high * 1.3) {
        score += 5;
        result.notes.push(`Resistivitas lapisan target (${Math.round(result.best.rho)} Ω·m) sesuai perkiraan dari daya hantar air (${Math.round(low)}–${Math.round(high)} Ω·m).`);
      } else {
        result.notes.push(result.best.rho < low * 0.7
          ? "Lapisan target lebih konduktif dari perkiraan air sampel: curigai lempung atau air lebih asin di kedalaman."
          : "Lapisan target lebih resistif dari perkiraan air sampel: formasi mungkin kurang jenuh atau berpasir kasar/kerikil.");
      }
    }
  }

  const deepSaline = bestIndex !== null && !brackishOnly && sounding[count - 1].rho < 10 && result.best!.rho >= 10;
  if (deepSaline) {
    score -= 20;
    result.warnings.push("Lapisan terdalam sangat konduktif (<10 Ω·m): ada risiko lempung atau air asin di bawah target. Jangan bor terlalu dalam.");
  }
  result.score = Math.max(0, Math.min(100, Math.round(score)));
  if (bestIndex === null) result.verdict = "Rendah";
  else if (brackishOnly || deepSaline || result.best!.rho < 10) result.verdict = "Berisiko asin/lempung";
  else if (result.score >= 70) result.verdict = "Tinggi";
  else if (result.score >= 45) result.verdict = "Sedang";
  else result.verdict = "Rendah";
  result.notes.push(`Kedalaman diperkirakan dari jarak elektroda × ${(sounding[0].depth / sounding[0].spacing).toFixed(2)} (aturan praktis). Untuk keputusan pengeboran, validasi dengan inversi dan sumur sekitar.`);
  return result;
}
