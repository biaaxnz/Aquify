const LABELS: Record<string, string> = {
  ringkasan: "Ringkasan",
  summary: "Ringkasan",
  kesimpulan: "Kesimpulan",
  verdict: "Kesimpulan indikatif",
  temuan: "Temuan utama",
  temuan_utama: "Temuan utama",
  main_findings: "Temuan utama",
  analisis: "Analisis",
  interpretasi: "Interpretasi",
  interpretation: "Interpretasi",
  risiko: "Risiko",
  risks: "Risiko",
  ketidakpastian: "Ketidakpastian",
  uncertainty: "Ketidakpastian",
  kualitas_data: "Kualitas data",
  rekomendasi: "Rekomendasi",
  recommendations: "Rekomendasi",
  saran: "Saran",
  langkah_berikutnya: "Langkah verifikasi berikutnya",
  next_steps: "Langkah verifikasi berikutnya",
  verifikasi: "Verifikasi",
  confidence: "Tingkat keyakinan",
  keyakinan: "Tingkat keyakinan",
  keyakinan_ai: "Tingkat keyakinan AI",
  perbedaan_dengan_hasil_aturan: "Perbedaan dari analisis aturan",
  water_quality: "Kualitas air",
  kualitas_air: "Kualitas air",
  data: "Data",
  notes: "Catatan",
};

function readableLabel(key: string): string {
  const normalized = key.trim().toLowerCase().replace(/[\s-]+/g, "_");
  if (LABELS[normalized]) return LABELS[normalized];
  return normalized
    .split("_")
    .filter(Boolean)
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(" ");
}

function readableValue(value: unknown): string {
  if (typeof value === "string") return value.trim();
  if (typeof value === "number" || typeof value === "boolean") return String(value);
  return "";
}

function flattenStructured(value: unknown, label?: string, depth = 0): string[] {
  if (depth > 5 || value === null || value === undefined) return [];
  if (Array.isArray(value)) {
    if (value.every((item) => typeof item === "string" || typeof item === "number" || typeof item === "boolean")) {
      const items = value.map(readableValue).filter(Boolean);
      return items.length ? [`${label ? `${label}: ` : ""}${items.join("; ")}`] : [];
    }
    return value.flatMap((item) => flattenStructured(item, label, depth + 1));
  }
  if (typeof value === "object") {
    return Object.entries(value as Record<string, unknown>).flatMap(([key, child]) => {
      const childLabel = readableLabel(key);
      if (child !== null && typeof child === "object") {
        return flattenStructured(child, label ? `${label} - ${childLabel}` : childLabel, depth + 1);
      }
      const text = readableValue(child);
      return text ? [`${label ? `${label} - ` : ""}${childLabel}: ${text}`] : [];
    });
  }
  const text = readableValue(value);
  return text ? [label ? `${label}: ${text}` : text] : [];
}

function parseStructuredText(raw: string): unknown | null {
  const withoutFence = raw.trim().replace(/^```(?:json)?\s*/i, "").replace(/\s*```$/, "").trim();
  if (!withoutFence.startsWith("{") && !withoutFence.startsWith("[")) return null;
  try {
    return JSON.parse(withoutFence) as unknown;
  } catch {
    return null;
  }
}

export function formatInterpretationText(raw: string): string[] {
  const structured = parseStructuredText(raw);
  if (structured !== null) {
    return flattenStructured(structured);
  }

  return raw
    .replace(/^```[a-z]*\s*/gim, "")
    .replace(/```$/gm, "")
    .split(/\r?\n/)
    .map((line) => line
      .replace(/^\s{0,3}#{1,6}\s*/, "")
      .replace(/^\s*[-*+]\s+/, "- ")
      .replace(/\*\*(.*?)\*\*/g, "$1")
      .replace(/__(.*?)__/g, "$1")
      .replace(/`([^`]*)`/g, "$1")
      .trim())
    .filter((line) => line && line !== "{" && line !== "}" && line !== "[" && line !== "]");
}

export type InterpretationSection = {
  heading: string;
  body: string;
};

function sectionHeading(line: string): { heading: string; inlineBody: string } | null {
  const match = line.match(/(?:^| - )(temuan utama|temuan|ringkasan|kesimpulan|risiko|ketidakpastian|rekomendasi|langkah(?: verifikasi)? berikutnya|kualitas data|interpretasi|data terukur|sampel air|kualitas air|tingkat keyakinan(?: ai)?|perbedaan dari analisis aturan)\b\s*:?\s*(.*)$/i);
  if (!match) return null;
  const heading = readableLabel(match[1]);
  return { heading, inlineBody: match[2].replace(/^[:\s-]+/, "").trim() };
}

export function splitInterpretationSections(raw: string): InterpretationSection[] {
  const sections: InterpretationSection[] = [];
  for (const line of formatInterpretationText(raw)) {
    const detected = sectionHeading(line);
    if (detected) {
      sections.push({ heading: detected.heading, body: detected.inlineBody });
    } else if (sections.length) {
      sections[sections.length - 1].body = [sections[sections.length - 1].body, line].filter(Boolean).join(" ");
    } else {
      sections.push({ heading: "Ringkasan", body: line });
    }
  }
  return sections.filter((section) => section.body);
}
