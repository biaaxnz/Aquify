"use client";

import Link from "next/link";
import { useEffect, useMemo } from "react";
import { addNotificationOnce } from "@/lib/notification-store";

type WaterSample = {
  id: string;
  point_id: string;
  point_code: string;
  point_name: string;
  tds_ppm: number | null;
  ec_us: number | null;
  measured_at: string;
};

type WaterAlert = {
  key: string;
  pointId: string;
  pointName: string;
  message: string;
};

function latestSamplesByPoint(samples: WaterSample[]): WaterSample[] {
  const sorted = [...samples].sort((a, b) => Date.parse(b.measured_at) - Date.parse(a.measured_at));
  const seenPoints = new Set<string>();
  return sorted.filter((sample) => {
    if (seenPoints.has(sample.point_id)) return false;
    seenPoints.add(sample.point_id);
    return true;
  });
}

export function WaterQualityAlertMonitor({ samples }: { samples: WaterSample[] }) {
  const alerts = useMemo<WaterAlert[]>(() => latestSamplesByPoint(samples).flatMap((sample) => {
    const elevated: string[] = [];
    if (sample.tds_ppm !== null && sample.tds_ppm > 300) {
      elevated.push(`TDS ${sample.tds_ppm} ppm (batas perhatian skrining Aquify: 300 ppm)`);
    }
    if (sample.ec_us !== null && sample.ec_us > 400) {
      elevated.push(`EC ${sample.ec_us} µS/cm (batas perhatian skrining Aquify: 400 µS/cm)`);
    }
    if (!elevated.length) return [];
    return [{
      key: `water-quality:${sample.id}`,
      pointId: sample.point_id,
      pointName: sample.point_name || sample.point_code,
      message: `${elevated.join("; ")}. Periksa ulang pengukuran dan verifikasi sampel. Ini bukan konfirmasi bahwa air payau atau tidak layak diminum.`,
    }];
  }), [samples]);

  useEffect(() => {
    for (const alert of alerts) {
      try {
        addNotificationOnce(alert.key, {
          title: "Perlu pemeriksaan sampel air",
          message: `${alert.pointName}: ${alert.message}`,
          href: `/points/${encodeURIComponent(alert.pointId)}`,
        });
      } catch (error) {
        console.error("Peringatan kualitas air gagal disimpan.", error);
      }
    }
  }, [alerts]);

  if (!alerts.length) return null;

  return (
    <section className="card">
      <div className="alert warn" role="status">
        <div>
          <b>Peringatan skrining kualitas air</b>
          <p>Nilai TDS/EC melewati batas perhatian skrining Aquify pada sampel terbaru:</p>
          <ul>
            {alerts.map((alert) => (
              <li key={alert.key}>
                <Link className="tx-link" href={`/points/${encodeURIComponent(alert.pointId)}`}>{alert.pointName}</Link>: {alert.message}
              </li>
            ))}
          </ul>
          <p>Ambang ini hanya untuk skrining aplikasi; lakukan pengukuran ulang dan uji laboratorium. Peringatan ini tidak mengukur cadangan air tanah.</p>
        </div>
      </div>
    </section>
  );
}
