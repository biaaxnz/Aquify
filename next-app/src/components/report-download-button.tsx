"use client";

import { useEffect, useState } from "react";

declare global {
  interface Window {
    AquifyDownloadBridge?: {
      downloadReport(url: string, filename: string, pointId: string, pointCode: string): void;
    };
  }
}

export function ReportDownloadButton({ pointId, pointCode }: { pointId: string; pointCode: string }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const filename = `laporan-aquify-${pointCode.replace(/[^a-zA-Z0-9_-]+/g, "-")}.pdf`;

  useEffect(() => {
    const completed = (event: Event) => {
      const detail = (event as CustomEvent<{ pointId: string; pointCode: string }>).detail;
      if (detail?.pointId !== pointId) return;
      setBusy(false);
      setError(null);
    };
    const failed = (event: Event) => {
      const detail = (event as CustomEvent<{ pointId: string; message: string }>).detail;
      if (detail?.pointId !== pointId) return;
      setError(detail.message || "Unduhan PDF gagal. Coba lagi.");
      setBusy(false);
    };
    window.addEventListener("aquify:download-complete", completed);
    window.addEventListener("aquify:download-failed", failed);
    return () => {
      window.removeEventListener("aquify:download-complete", completed);
      window.removeEventListener("aquify:download-failed", failed);
    };
  }, [pointId]);

  async function downloadReport() {
    let nativeDownloadStarted = false;
    setBusy(true);
    setError(null);
    try {
      const url = `/api/points/${encodeURIComponent(pointId)}/report`;
      if (window.AquifyDownloadBridge) {
        window.AquifyDownloadBridge.downloadReport(url, filename, pointId, pointCode);
        nativeDownloadStarted = true;
        return;
      }

      const response = await fetch(url, { credentials: "same-origin", cache: "no-store" });
      if (!response.ok) {
        const body: unknown = await response.json().catch(() => null);
        const message = body && typeof body === "object" && "error" in body && typeof body.error === "string"
          ? body.error
          : `Laporan gagal dibuat (HTTP ${response.status}).`;
        throw new Error(message);
      }
      if (!response.headers.get("content-type")?.includes("application/pdf")) {
        throw new Error("Server tidak mengembalikan file PDF. Coba muat ulang atau hubungi administrator.");
      }

      const blob = await response.blob();
      const objectUrl = URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = objectUrl;
      anchor.download = filename;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
      window.dispatchEvent(new CustomEvent("aquify:download-complete", { detail: { pointId, pointCode } }));
    } catch (cause) {
      console.error("Gagal mengunduh laporan Aquify.", cause);
      setError(cause instanceof Error ? cause.message : "Terjadi kesalahan saat mengunduh laporan PDF.");
      setBusy(false);
    } finally {
      if (!nativeDownloadStarted) setBusy(false);
    }
  }

  return (
    <div className="report-download">
      <button className="btn outline sm" type="button" onClick={downloadReport} disabled={busy}>
        {busy ? "Menyiapkan PDF..." : "Unduh laporan PDF"}
      </button>
      {error && <p className="alert bad" role="alert">{error}</p>}
    </div>
  );
}
