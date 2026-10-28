"use client";

import { useEffect, useState } from "react";
import { Icon } from "@/components/icon";
import { Section2DPlot } from "@/components/resistivity-plots";

type IntegrationConfiguration = {
  esp32: boolean;
  firebase: boolean;
  gemini: boolean;
};

export function IntegrationSimulator({ configuration }: { configuration: IntegrationConfiguration }) {
  const [running, setRunning] = useState(false);
  const [tick, setTick] = useState(0);

  useEffect(() => {
    if (!running) return;
    const timer = window.setInterval(() => setTick((current) => current + 1), 1800);
    return () => window.clearInterval(timer);
  }, [running]);

  const voltage = (24.6 + (tick % 5) * 0.3).toFixed(1);
  const current = (2.1 + (tick % 4) * 0.1).toFixed(1);
  const temperature = (25.8 + (tick % 3) * 0.2).toFixed(1);
  const layerResistivity = [30, 95, 260, 680];
  const simulatedMeasurements = [0, 5, 10, 15, 20].flatMap((x_m, positionIndex) =>
    [1, 2, 3, 4].map((spacing_m, spacingIndex) => {
      const conductiveLens = Math.exp(-(((positionIndex - 2) / 1.2) ** 2 + ((spacingIndex - 1.7) / 1.15) ** 2));
      const lateralChange = 1 + (positionIndex - 2) * 0.09;
      const readingVariation = 1 + ((tick + positionIndex + spacingIndex) % 3) * 0.025;
      return {
        x_m,
        spacing_m,
        rho_a: Number((layerResistivity[spacingIndex] * lateralChange * (1 - 0.78 * conductiveLens) * readingVariation).toFixed(1)),
      };
    }),
  );

  return (
    <div className="integration-simulator">
      <div className="alert info">
        Ini simulasi tampilan saja. Tidak mengirim data ke layanan eksternal dan tidak menyimpan data ke riwayat survey.
      </div>
      <section className="card simulation-controls">
        <div>
          <h2>Mode demo admin</h2>
          <p className="hint">Contoh angka akan berubah selama demo berjalan.</p>
        </div>
        <button className={`btn ${running ? "soft-red" : "teal"}`} type="button" onClick={() => setRunning((value) => !value)}>
          <Icon name={running ? "x" : "activity"} size={18} />
          {running ? "Hentikan simulasi" : "Mulai simulasi"}
        </button>
      </section>

      <div className="simulation-grid">
        <section className={`card simulation-card ${running ? "is-running" : ""}`}>
          <div className="simulation-heading">
            <span className="simulation-icon"><Icon name="cpu" size={22} /></span>
            <div><h2>ESP32 · Geolistrik</h2><p>Perangkat lapangan</p></div>
            <span className={`simulation-status ${running ? "on" : ""}`}><i />{running ? "TERHUBUNG · DEMO" : "PRATINJAU"}</span>
          </div>
          <div className="simulation-data">
            <div><small>Perangkat</small><b>ESP32-DEMO-01</b></div>
            <div><small>Beda tegangan</small><b>{running ? `${voltage} mV` : "—"}</b></div>
            <div><small>Arus</small><b>{running ? `${current} mA` : "—"}</b></div>
            <div><small>Resistivitas semu</small><b>{running ? `${(71 + tick % 8).toFixed(1)} Ω·m` : "—"}</b></div>
          </div>
          <p className="simulation-config">Konfigurasi server: {configuration.esp32 ? "tersedia" : "belum lengkap"}</p>
        </section>

        <section className={`card simulation-card ${running ? "is-running" : ""}`}>
          <div className="simulation-heading">
            <span className="simulation-icon"><Icon name="refresh" size={22} /></span>
            <div><h2>Firebase</h2><p>Sinkronisasi data contoh</p></div>
            <span className={`simulation-status ${running ? "on" : ""}`}><i />{running ? "TERSINKRON · DEMO" : "PRATINJAU"}</span>
          </div>
          <div className="simulation-data">
            <div><small>Data masuk</small><b>{running ? `${3 + tick % 4} baris` : "—"}</b></div>
            <div><small>Data duplikat</small><b>{running ? "0 baris" : "—"}</b></div>
            <div><small>Lokasi</small><b>GL-DEMO-01</b></div>
            <div><small>Pembaruan terakhir</small><b>{running ? "Baru saja" : "—"}</b></div>
          </div>
          <p className="simulation-config">Konfigurasi server: {configuration.firebase ? "URL tersedia" : "belum diatur"}</p>
        </section>

        <section className={`card simulation-card ${running ? "is-running" : ""}`}>
          <div className="simulation-heading">
            <span className="simulation-icon"><Icon name="sparkles" size={22} /></span>
            <div><h2>Gemini</h2><p>Contoh hasil interpretasi</p></div>
            <span className={`simulation-status ${running ? "on" : ""}`}><i />{running ? "SIAP · DEMO" : "PRATINJAU"}</span>
          </div>
          <div className="simulation-interpretation">
            {running
              ? `Contoh: data resistivitas semu sekitar ${71 + tick % 8} Ω·m berhasil diterima. Nilai ini hanya ilustrasi; interpretasi sebenarnya memerlukan data lapangan yang memadai dan verifikasi ahli.`
              : "Mulai simulasi untuk melihat contoh tampilan hasil interpretasi."}
          </div>
          <p className="simulation-config">Konfigurasi server: {configuration.gemini ? "API key tersedia" : "belum diatur"}</p>
        </section>
      </div>
      <p className="hint">Semua angka bertanda demo adalah data buatan, bukan hasil sensor, bukan respons Firebase, dan bukan jawaban dari Gemini.</p>

      <section className="card">
        <h2>Contoh kurva penampang geolistrik 2D</h2>
        <p className="data-caption">Penampang contoh dari data simulasi pada 5 posisi lintasan dan 4 jarak elektroda. Warna dan pola hanya ilustrasi.</p>
        <Section2DPlot rows={simulatedMeasurements} />
      </section>

      <section className="card simulation-weather">
        <div className="simulation-heading">
          <span className="simulation-icon"><Icon name="droplet" size={22} /></span>
          <div><h2>Contoh sensor kualitas air</h2><p>Nilai simulasi, bukan pengukuran aktual</p></div>
        </div>
        <div className="simulation-data">
          <div><small>pH</small><b>{running ? (7.1 + (tick % 3) * 0.1).toFixed(1) : "—"}</b></div>
          <div><small>TDS</small><b>{running ? `${310 + tick % 12} ppm` : "—"}</b></div>
          <div><small>Suhu</small><b>{running ? `${temperature} °C` : "—"}</b></div>
          <div><small>Kekeruhan</small><b>{running ? `${1.2 + (tick % 4) * 0.1} NTU` : "—"}</b></div>
        </div>
      </section>
    </div>
  );
}
