import { PDFDocument, StandardFonts, rgb, type PDFFont, type PDFPage } from "pdf-lib";
import { createClient } from "@/lib/supabase/server";

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
  const ruleAnalysis = analyses.find((analysis) => analysis.mode === "rule");
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
      ["Verdik indikatif", ruleAnalysis.verdict],
      ["Skor", `${ruleAnalysis.score}/100`],
      ["Target kedalaman indikatif", ruleAnalysis.best_depth === null ? "Belum tersedia" : `${formatNumber(ruleAnalysis.best_depth)} m`],
      ["Resistivitas target", ruleAnalysis.best_rho === null ? "Belum tersedia" : `${formatNumber(ruleAnalysis.best_rho)} ohm.m`],
      ["Model analisis", ruleAnalysis.mode === "ai" ? "Gemini" : "Aturan"],
    ], [190, 321]);

    const warnings = Array.isArray(result.warnings) ? result.warnings.filter((item): item is string => typeof item === "string") : [];
    if (warnings.length) {
      paragraph(ctx, "Peringatan:", { size: 9, color: NAVY });
      warnings.forEach((warning) => paragraph(ctx, `- ${warning}`, { indent: 8 }));
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
  } else {
    paragraph(ctx, "Belum ada hasil analisis tersimpan untuk titik survei ini.");
  }

  if (aiText) {
    sectionTitle(ctx, "Interpretasi Gemini");
    aiText.split(/\r?\n/).map((line: string) => line.replace(/[#*_`>-]/g, "").trim()).filter(Boolean)
      .forEach((line: string) => paragraph(ctx, line));
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
