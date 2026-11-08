"use client";

import { useEffect } from "react";
import { addNotification } from "@/lib/notification-store";

export function DownloadNotificationListener() {
  useEffect(() => {
    const handleComplete = (event: Event) => {
      const detail = (event as CustomEvent<{ pointId: string; pointCode: string }>).detail;
      if (!detail?.pointId || !detail.pointCode) return;
      try {
        addNotification({
          title: "Laporan PDF berhasil diunduh",
          message: `Laporan survei ${detail.pointCode} berhasil diunduh.`,
          href: `/points/${encodeURIComponent(detail.pointId)}`,
        });
      } catch (error) {
        console.error("PDF berhasil diunduh tetapi notifikasi gagal disimpan.", error);
      }
    };
    window.addEventListener("aquify:download-complete", handleComplete);
    return () => window.removeEventListener("aquify:download-complete", handleComplete);
  }, []);

  return null;
}
