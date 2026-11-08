import { PDFDocument, StandardFonts, rgb, type PDFFont, type PDFPage } from "pdf-lib";
import { createClient } from "@/lib/supabase/server";
import { formatInterpretationText } from "@/lib/interpretation-text";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

const PAGE_WIDTH = 595.28;
const PAGE_HEIGHT = 841.89;
const MARGIN = 42;
const NAVY = rgb(15 / 255, 70 / 255, 96 / 255);
const TEAL = rgb(44 / 255, 180 / 255, 164 / 255);
const INK = rgb(16 / 255, 36 / 255, 40 / 255);
const MUTED = rgb(106 / 255, 128 / 255, 133 / 255);
const PALE = rgb(240 / 255, 247 / 255, 247 / 255);
const LINE = rgb(221 / 255, 232 / 255, 232 / 255);

type PdfContext = {
  document: PDFDocument;
  regular: PDFFont;
  bold: PDFFont;
  page: PDFPage;
  y: number;
};

function pdfText(value: unknown): string {
  return String(value ?? "—")
    .normalize("NFKD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/[Ω]/g, "ohm")
    .replace(/[ρ]/g, "rho")
    .replace(/[µμ]/g, "u")
    .replace(/[°]/g, " deg ")
    .replace(/[–—]/g, "-")
    .replace(/[·]/g, " ")
    .replace(/[“”]/g, '"')
    .replace(/[‘’]/g, "'")
    .replace(/[^\x20-\x7E]/g, "?");
}

function dateText(value: string | null | undefined): string {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "—";
  return new Intl.DateTimeFormat("id-ID", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: "Asia/Jakarta",
  }).format(date);
}

function safeFilename(value: string): string {
  return value.normalize("NFKD").replace(/[^a-zA-Z0-9_-]+/g, "-").replace(/^-|-$/g, "").slice(0, 60) || "survey";
}

function drawText(ctx: PdfContext, text: string, x: number, y: number, size = 10, color = INK, font = ctx.regular) {
  ctx.page.drawText(pdfText(text), { x, y, size, color, font });
}

function wrapText(text: string, font: PDFFont, size: number, maxWidth: number): string[] {
  const words = pdfText(text).split(/\s+/);
  const lines: string[] = [];
  let line = "";
  for (const word of words) {
    const candidate = line ? `${line} ${word}` : word;
    if (line && font.widthOfTextAtSize(candidate, size) > maxWidth) {
      lines.push(line);
      line = word;
    } else {
      line = candidate;
    }
  }
  if (line) lines.push(line);
  return lines;
}

function addPage(ctx: PdfContext) {
  ctx.page = ctx.document.addPage([PAGE_WIDTH, PAGE_HEIGHT]);
  ctx.y = PAGE_HEIGHT - MARGIN;
  ctx.page.drawRectangle({ x: 0, y: PAGE_HEIGHT - 8, width: PAGE_WIDTH, height: 8, color: TEAL });
}

function ensureSpace(ctx: PdfContext, height: number) {
  if (ctx.y - height < MARGIN + 28) addPage(ctx);
}

function sectionTitle(ctx: PdfContext, title: string) {
  ensureSpace(ctx, 32);
  ctx.y -= 10;
  drawText(ctx, title.toUpperCase(), MARGIN, ctx.y, 9, NAVY, ctx.bold);
  ctx.y -= 7;
  ctx.page.drawLine({ start: { x: MARGIN, y: ctx.y }, end: { x: PAGE_WIDTH - MARGIN, y: ctx.y }, thickness: 0.8, color: LINE });
  ctx.y -= 17;
}

function keyValue(ctx: PdfContext, label: string, value: unknown, x: number, y: number, width: number) {
  drawText(ctx, label, x, y, 8, MUTED);
  const valueLines = wrapText(String(value ?? "—"), ctx.bold, 10, width);
  valueLines.slice(0, 2).forEach((line, index) => drawText(ctx, line, x, y - 15 - index * 12, 10, INK, ctx.bold));
}

function drawTable(
  ctx: PdfContext,
  headers: string[],
  rows: Array<Array<unknown>>,
  widths: number[],
) {
  const rowHeight = 22;
  ensureSpace(ctx, rowHeight + 8);
  const tableX = MARGIN;
  const tableWidth = widths.reduce((sum, width) => sum + width, 0);
  ctx.page.drawRectangle({ x: tableX, y: ctx.y - rowHeight + 4, width: tableWidth, height: rowHeight, color: PALE });
  let x = tableX + 7;
  headers.forEach((header, index) => {
    drawText(ctx, header, x, ctx.y - 10, 7.5, NAVY, ctx.bold);
    x += widths[index];
  });
  ctx.y -= rowHeight;

  rows.forEach((row, rowIndex) => {
    ensureSpace(ctx, rowHeight);
    if (rowIndex % 2 === 1) ctx.page.drawRectangle({ x: tableX, y: ctx.y - rowHeight + 4, width: tableWidth, height: rowHeight, color: rgb(0.985, 0.99, 0.99) });
    let cellX = tableX + 7;
    row.forEach((value, index) => {
      const text = pdfText(value ?? "—");
      const size = 7.5;
      const availableWidth = widths[index] - 12;
      const lines = wrapText(text, ctx.regular, size, availableWidth);
      drawText(ctx, lines[0] ?? "—", cellX, ctx.y - 10, size, INK);
      if (lines[1]) drawText(ctx, lines[1], cellX, ctx.y - 18, 6.5, MUTED);
      cellX += widths[index];
    });
    ctx.page.drawLine({ start: { x: tableX, y: ctx.y - rowHeight + 4 }, end: { x: tableX + tableWidth, y: ctx.y - rowHeight + 4 }, thickness: 0.4, color: LINE });
    ctx.y -= rowHeight;
  });
  ctx.y -= 4;
}

function paragraph(ctx: PdfContext, text: string, options: { size?: number; color?: ReturnType<typeof rgb>; indent?: number } = {}) {
  const size = options.size ?? 9;
  const color = options.color ?? INK;
  const indent = options.indent ?? 0;
  const lines = wrapText(text, ctx.regular, size, PAGE_WIDTH - 2 * MARGIN - indent);
  ensureSpace(ctx, lines.length * (size + 4) + 4);
  for (const line of lines) {
    drawText(ctx, line, MARGIN + indent, ctx.y, size, color);
    ctx.y -= size + 4;
  }
  ctx.y -= 3;
}

function formatNumber(value: number | null, digits = 2): string {
  return value === null || !Number.isFinite(value)
    ? "—"
    : new Intl.NumberFormat("id-ID", { maximumFractionDigits: digits }).format(value);
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}

function resistivityColor(value: number) {
  if (value < 10) return rgb(0.66, 0.26, 0.18);
  if (value < 25) return rgb(0.79, 0.54, 0.17);
  if (value < 150) return rgb(0.05, 0.49, 0.53);
  if (value < 500) return rgb(0.55, 0.56, 0.47);
  return rgb(0.29, 0.31, 0.34);
}

function drawSection2D(
  ctx: PdfContext,
  rows: Array<{ x_m: number | null; spacing_m: number; rho_a: number }>,
) {
  const grouped = new Map<string, number[]>();
  for (const row of rows) {
    if (row.x_m === null || !Number.isFinite(row.x_m) || row.spacing_m <= 0 || row.rho_a <= 0) continue;
    const key = `${row.x_m.toFixed(2)}|${row.spacing_m.toFixed(2)}`;
    grouped.set(key, [...(grouped.get(key) ?? []), row.rho_a]);
  }
  const points = [...grouped.entries()].map(([key, values]) => {
    const [x, spacing] = key.split("|").map(Number);
    const sorted = values.sort((a, b) => a - b);
    const middle = Math.floor(sorted.length / 2);
    const rho = sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
    return { x, spacing, rho };
  });
  const positions = [...new Set(points.map((point) => point.x))].sort((a, b) => a - b);
  const spacings = [...new Set(points.map((point) => point.spacing))].sort((a, b) => a - b);

  if (positions.length < 3 || spacings.length < 2) {
    sectionTitle(ctx, "Penampang geolistrik 2D");
    paragraph(ctx, "Grafik 2D belum dapat dibuat. Data perlu memiliki posisi lintasan pada minimal 3 titik dan menggunakan minimal 2 jarak elektroda berbeda.");
    return;
  }

  const chartHeight = 206;
  ensureSpace(ctx, chartHeight + 82);
  sectionTitle(ctx, "Penampang geolistrik 2D");
  const xGap = Math.min(...positions.slice(1).map((value, index) => value - positions[index]));
  const spacingGap = Math.min(...spacings.slice(1).map((value, index) => value - spacings[index]));
  const dx = Number.isFinite(xGap) && xGap > 0 ? xGap : 1;
  const minSpacingGap = Number.isFinite(spacingGap) && spacingGap > 0 ? spacingGap : spacings[0];
  const minX = positions[0] - dx / 2;
  const maxX = positions[positions.length - 1] + dx / 2;
  const maxDepth = Math.max(...spacings) * 0.56;
  const plotX = MARGIN + 43;
  const plotWidth = PAGE_WIDTH - MARGIN - 12 - plotX;
  const plotTop = ctx.y - 16;
  const plotHeight = chartHeight - 55;
  const plotBottom = plotTop - plotHeight;
  const xPosition = (value: number) => plotX + (value - minX) / (maxX - minX) * plotWidth;
  const yPosition = (depth: number) => plotTop - depth / maxDepth * plotHeight;
  const columns = 48;
  const gridRows = 18;
  const cellWidth = plotWidth / columns;
  const cellHeight = plotHeight / gridRows;

  ctx.page.drawRectangle({
    x: plotX,
    y: plotBottom,
    width: plotWidth,
    height: plotHeight,
    color: PALE,
    borderColor: LINE,
    borderWidth: 0.6,
  });
  for (let column = 0; column < columns; column++) {
    const x = minX + ((column + 0.5) / columns) * (maxX - minX);
    for (let row = 0; row < gridRows; row++) {
      const depth = ((row + 0.5) / gridRows) * maxDepth;
      const nx = x / dx;
      const ny = depth / (0.5 * minSpacingGap);
      const nearby = points.map((point) => ({
        distance: Math.hypot(nx - point.x / dx, ny - point.spacing / minSpacingGap),
        rho: point.rho,
      })).sort((a, b) => a.distance - b.distance).slice(0, 4);
      if (!nearby.length || nearby[0].distance > 1.9) continue;
      const totalWeight = nearby.reduce((sum, point) => sum + 1 / (point.distance ** 2 + 0.06), 0);
      const logRho = nearby.reduce((sum, point) =>
        sum + Math.log10(point.rho) / (point.distance ** 2 + 0.06), 0,
      ) / totalWeight;
      ctx.page.drawRectangle({
        x: plotX + column * cellWidth,
        y: plotTop - (row + 1) * cellHeight,
        width: cellWidth + 0.3,
        height: cellHeight + 0.3,
        color: resistivityColor(10 ** logRho),
      });
    }
  }
  ctx.page.drawLine({ start: { x: plotX, y: plotTop }, end: { x: plotX + plotWidth, y: plotTop }, thickness: 1, color: NAVY });
  for (let index = 0; index < 5; index++) {
    const value = minX + (maxX - minX) * index / 4;
    const x = xPosition(value);
    ctx.page.drawLine({ start: { x, y: plotBottom }, end: { x, y: plotTop }, thickness: 0.35, color: LINE });
    drawText(ctx, formatNumber(value, 1), x - 10, plotTop + 7, 7, MUTED);
  }
  for (let index = 0; index < 4; index++) {
    const depth = maxDepth * index / 3;
    const y = yPosition(depth);
    ctx.page.drawLine({ start: { x: plotX, y }, end: { x: plotX + plotWidth, y }, thickness: 0.35, color: LINE });
    drawText(ctx, formatNumber(depth, 1), MARGIN + 5, y - 2, 7, MUTED);
  }
  for (const point of points) {
    ctx.page.drawCircle({
      x: xPosition(point.x),
      y: yPosition(point.spacing * 0.5),
      size: 2.4,
      color: rgb(1, 1, 1),
      borderColor: INK,
      borderWidth: 0.8,
    });
  }
  drawText(ctx, "Posisi lintasan (m)", plotX + plotWidth / 2 - 42, plotBottom - 13, 7, MUTED);
  drawText(ctx, "Kedalaman semu (m)", MARGIN + 2, plotTop + 16, 7, MUTED);
  const legend = [
    { label: "<10 lempung/asin", color: resistivityColor(5) },
    { label: "10-25 payau/lempung basah", color: resistivityColor(15) },
    { label: "25-150 potensi akuifer", color: resistivityColor(70) },
    { label: ">150 padat/batuan", color: resistivityColor(300) },
  ];
  let legendX = plotX;
  for (const item of legend) {
    ctx.page.drawRectangle({ x: legendX, y: plotBottom - 27, width: 7, height: 7, color: item.color });
    drawText(ctx, item.label, legendX + 10, plotBottom - 25, 6, MUTED);
    legendX += 122;
  }
  ctx.y = plotBottom - 42;
  paragraph(ctx, "Warna memperlihatkan perubahan nilai resistivitas dari titik ukur. Grafik ini merupakan interpolasi indikatif, bukan hasil inversi geofisika. Kedalaman semu diperkirakan dari jarak elektroda.");
}

function plainConclusion(verdict: string, depth: number | null, warnings: string[]): string[] {
  const lines = verdict === "Data kurang"
    ? ["Data belum cukup untuk menilai lapisan bawah tanah. Perlu pengukuran pada sedikitnya tiga jarak elektroda berbeda."]
    : verdict === "Rendah"
      ? ["Data saat ini belum menunjukkan lapisan yang jelas berpotensi menjadi akuifer air tawar."]
      : verdict.toLowerCase().includes("risiko") || verdict.toLowerCase().includes("payau")
        ? ["Ada lapisan yang mungkin berupa lempung basah atau air payau. Data ini belum memastikan adanya sumber air tawar."]
        : [`Data menunjukkan lapisan yang berpotensi menyimpan air pada kedalaman perkiraan ${formatNumber(depth)} m. Ini indikasi awal, bukan kepastian ada air.`];

  const plainWarnings = warnings.flatMap((warning) => {
    if (/pengulangan ukur|sebaran/i.test(warning)) return ["Hasil pengukuran berulang berbeda cukup jauh; periksa sambungan elektroda dan ulangi pengukuran."];
    if (/lonjakan/i.test(warning)) return ["Ada perubahan hasil yang tajam antarjarak; sebaiknya periksa ulang pengukuran terkait."];
    if (/lapisan terdalam|asin|payau|lempung/i.test(warning)) return ["Ada kemungkinan lapisan lempung atau air payau/asin; jangan jadikan hasil ini satu-satunya dasar pengeboran."];
    return [];
  });
  lines.push(...new Set(plainWarnings));
  lines.push("Pastikan dengan membandingkan sumur sekitar dan meminta pemeriksaan ahli sebelum menentukan pengeboran. Kualitas air perlu diuji di laboratorium.");
  return lines;
}

export async function GET(_request: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) return Response.json({ error: "Silakan masuk untuk mengunduh laporan." }, { status: 401 });

  const { data: point, error: pointError } = await supabase
    .from("points")
    .select("id,code,name,survey_type,latitude,longitude,notes,created_at")
    .eq("id", id)
    .eq("owner_id", user.id)
    .maybeSingle();
  if (pointError) {
    console.error("Aquify report point query failed", pointError.message);
    return Response.json({ error: "Gagal memuat titik survei." }, { status: 500 });
  }
  if (!point) return Response.json({ error: "Titik survei tidak ditemukan." }, { status: 404 });

  const [measurementsResult, waterResult, analysisResult] = await Promise.all([
    supabase.from("measurements")
      .select("config,spacing_m,mn_m,dv_mv,i_ma,rho_a,x_m,measured_at")
      .eq("point_id", id).order("measured_at", { ascending: false }).limit(1000),
    supabase.from("water_samples")
      .select("tds_ppm,ec_us,ph,temp_c,turbidity_ntu,device_id,measured_at")
      .eq("point_id", id).order("measured_at", { ascending: false }).limit(500),
    supabase.from("analyses")
      .select("mode,score,verdict,best_depth,best_rho,result,ai_text,created_at")
      .eq("point_id", id).order("created_at", { ascending: false }).limit(20),
  ]);
  if (measurementsResult.error || waterResult.error || analysisResult.error) {
    console.error("Aquify report data query failed", measurementsResult.error?.message, waterResult.error?.message, analysisResult.error?.message);
    return Response.json({ error: "Gagal memuat data survei untuk laporan." }, { status: 500 });
  }

  const measurements = [...(measurementsResult.data ?? [])].reverse();
  const samples = [...(waterResult.data ?? [])].reverse();
  const analyses = analysisResult.data ?? [];
  const ruleAnalysis = analyses.find((analysis) => analysis.mode === "rule") ?? null;
  const aiText = analyses.find((analysis) => Boolean(analysis.ai_text))?.ai_text?.trim() || null;

  const document = await PDFDocument.create();
  document.setTitle(`Laporan Survei Aquify - ${point.code}`);
  document.setAuthor("Aquify");
  document.setSubject(`Ringkasan survei ${point.name}`);
  const regular = await document.embedFont(StandardFonts.Helvetica);
  const bold = await document.embedFont(StandardFonts.HelveticaBold);
  const ctx: PdfContext = { document, regular, bold, page: document.addPage([PAGE_WIDTH, PAGE_HEIGHT]), y: PAGE_HEIGHT - MARGIN };
  ctx.page.drawRectangle({ x: 0, y: PAGE_HEIGHT - 8, width: PAGE_WIDTH, height: 8, color: TEAL });

  drawText(ctx, "AQUIFY", MARGIN, ctx.y, 11, NAVY, bold);
  drawText(ctx, "LAPORAN SURVEI", PAGE_WIDTH - MARGIN - 130, ctx.y, 9, MUTED, bold);
  ctx.y -= 26;
  drawText(ctx, point.name, MARGIN, ctx.y, 20, NAVY, bold);
  ctx.y -= 17;
  drawText(ctx, `Kode ${point.code}  |  ${point.survey_type === "water" ? "Survei kualitas air" : "Survei geolistrik"}`, MARGIN, ctx.y, 9, MUTED);
  ctx.y -= 24;

  const boxY = ctx.y - 54;
  ctx.page.drawRectangle({ x: MARGIN, y: boxY, width: PAGE_WIDTH - 2 * MARGIN, height: 62, color: PALE, borderColor: LINE, borderWidth: 0.8 });
  keyValue(ctx, "TANGGAL DIBUAT", dateText(point.created_at), MARGIN + 12, ctx.y - 10, 150);
  keyValue(ctx, "LAPORAN DICETAK", dateText(new Date().toISOString()), MARGIN + 190, ctx.y - 10, 160);
  keyValue(ctx, "TITIK KOORDINAT", point.latitude !== null && point.longitude !== null ? `${point.latitude}, ${point.longitude}` : "Belum dicatat", MARGIN + 370, ctx.y - 10, 130);
  ctx.y = boxY - 18;

  sectionTitle(ctx, "Ringkasan survei");
  const halfWidth = (PAGE_WIDTH - 2 * MARGIN - 20) / 2;
  keyValue(ctx, "JUMLAH PENGUKURAN", `${measurements.length} data`, MARGIN, ctx.y, halfWidth);
  keyValue(ctx, "JUMLAH SAMPEL AIR", `${samples.length} sampel`, MARGIN + halfWidth + 20, ctx.y, halfWidth);
  ctx.y -= 40;
  if (point.notes) paragraph(ctx, `Catatan lokasi: ${point.notes}`);

  if (measurements.length) {
    sectionTitle(ctx, "Data pengukuran geolistrik");
    drawTable(ctx,
      ["Waktu", "Array", "Spasi (m)", "MN (m)", "dV (mV)", "I (mA)", "Rho (ohm.m)", "X (m)"],
      measurements.map((row) => [
        dateText(row.measured_at), row.config, formatNumber(row.spacing_m), formatNumber(row.mn_m),
        formatNumber(row.dv_mv), formatNumber(row.i_ma), formatNumber(row.rho_a), formatNumber(row.x_m),
      ]),
      [98, 54, 53, 48, 54, 48, 73, 55],
    );
    if (measurements.length === 1000) paragraph(ctx, "Catatan: laporan memuat maksimum 1.000 pengukuran terbaru.", { size: 8, color: MUTED });
  }

  drawSection2D(ctx, measurements);

  if (samples.length) {
    sectionTitle(ctx, "Data sampel kualitas air");
    drawTable(ctx,
      ["Waktu", "TDS (ppm)", "EC (uS/cm)", "pH", "Suhu (C)", "Kekeruhan (NTU)", "Perangkat"],
      samples.map((row) => [
        dateText(row.measured_at), formatNumber(row.tds_ppm), formatNumber(row.ec_us),
        formatNumber(row.ph), formatNumber(row.temp_c), formatNumber(row.turbidity_ntu), row.device_id,
      ]),
      [105, 66, 72, 42, 59, 90, 77],
    );
    if (samples.length === 500) paragraph(ctx, "Catatan: laporan memuat maksimum 500 sampel air terbaru.", { size: 8, color: MUTED });
  }

  sectionTitle(ctx, "Kesimpulan analisis");
  if (ruleAnalysis) {
    const result = isRecord(ruleAnalysis.result) ? ruleAnalysis.result : {};
    drawTable(ctx, ["Indikator", "Hasil"], [
      ["Kesimpulan otomatis", ruleAnalysis.verdict],
      ["Skor kecocokan data (bukan peluang menemukan air)", `${ruleAnalysis.score}/100`],
      ["Target kedalaman indikatif", ruleAnalysis.best_depth === null ? "Belum tersedia" : `${formatNumber(ruleAnalysis.best_depth)} m`],
      ["Resistivitas target", ruleAnalysis.best_rho === null ? "Belum tersedia" : `${formatNumber(ruleAnalysis.best_rho)} ohm.m`],
    ], [190, 321]);

    const warnings = Array.isArray(result.warnings) ? result.warnings.filter((item): item is string => typeof item === "string") : [];
    if (warnings.length) {
      paragraph(ctx, "Catatan pemeriksaan data:", { size: 9, color: NAVY });
      warnings.slice(0, 4).forEach((warning) => paragraph(ctx, `- ${warning}`, { indent: 8 }));
    }
    const layers = Array.isArray(result.layers) ? result.layers.filter(isRecord) : [];
    if (layers.length) {
      sectionTitle(ctx, "Interpretasi lapisan indikatif");
      drawTable(ctx, ["Lapisan", "Kedalaman dari (m)", "Kedalaman hingga (m)", "Rho rata-rata (ohm.m)"],
        layers.map((layer) => [
          layer.label ?? layer.key,
          formatNumber(typeof layer.from === "number" ? layer.from : null),
          formatNumber(typeof layer.to === "number" ? layer.to : null),
          formatNumber(typeof layer.rho === "number" ? layer.rho : null),
        ]),
        [190, 107, 107, 107],
      );
    }
    sectionTitle(ctx, "Kesimpulan singkat");
    plainConclusion(ruleAnalysis.verdict, ruleAnalysis.best_depth, warnings)
      .forEach((line) => paragraph(ctx, line));
  } else {
    sectionTitle(ctx, "Kesimpulan singkat");
    paragraph(ctx, "Analisis belum dijalankan untuk titik survei ini. Jalankan analisis dari aplikasi agar laporan dapat memuat kesimpulan.");
  }

  if (aiText) {
    sectionTitle(ctx, "Interpretasi Gemini");
    formatInterpretationText(aiText).forEach((line) => paragraph(ctx, line));
  } else {
    paragraph(ctx, "Belum ada interpretasi Gemini tersimpan untuk survei ini.");
  }

  const disclaimer = "Catatan: hasil analisis geolistrik dan penilaian kualitas air dalam laporan ini bersifat indikatif. Interpretasi resistivitas bukan kepastian keberadaan air; penampang merupakan visualisasi/interpolasi, bukan inversi geofisika. Verifikasi dengan kondisi lapangan, tenaga ahli, dan uji laboratorium sebelum mengambil keputusan.";
  const disclaimerLines = wrapText(disclaimer, regular, 8, PAGE_WIDTH - 2 * MARGIN - 20);
  const disclaimerHeight = disclaimerLines.length * 12 + 16;
  ensureSpace(ctx, disclaimerHeight + 10);
  ctx.y -= 4;
  ctx.page.drawRectangle({
    x: MARGIN,
    y: ctx.y - disclaimerHeight,
    width: PAGE_WIDTH - 2 * MARGIN,
    height: disclaimerHeight,
    color: rgb(0.96, 0.97, 0.97),
  });
  disclaimerLines.forEach((line, index) => drawText(ctx, line, MARGIN + 10, ctx.y - 13 - index * 12, 8, MUTED));
  ctx.y -= disclaimerHeight + 10;

  for (const page of document.getPages()) {
    page.drawLine({ start: { x: MARGIN, y: 28 }, end: { x: PAGE_WIDTH - MARGIN, y: 28 }, thickness: 0.6, color: LINE });
    page.drawText(`Aquify | ${pdfText(point.code)} | ${dateText(new Date().toISOString())}`, { x: MARGIN, y: 16, size: 7, color: MUTED, font: regular });
  }

  const bytes = await document.save();
  return new Response(new Uint8Array(bytes), {
    headers: {
      "Content-Type": "application/pdf",
      "Content-Disposition": `attachment; filename="laporan-aquify-${safeFilename(point.code)}.pdf"`,
      "Cache-Control": "private, no-store",
    },
  });
}
