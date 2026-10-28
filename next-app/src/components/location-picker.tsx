"use client";

import { useState } from "react";
import { SurveyMap } from "@/components/survey-map";

export function LocationPicker({ latitude, longitude }: { latitude?: number; longitude?: number }) {
  const [position, setPosition] = useState<[number, number] | null>(
    latitude !== undefined && longitude !== undefined ? [latitude, longitude] : null,
  );
  const [latitudeText, setLatitudeText] = useState(latitude?.toString() ?? "");
  const [longitudeText, setLongitudeText] = useState(longitude?.toString() ?? "");
  const updatePosition = (lat: number, lon: number) => {
    setPosition([lat, lon]);
    setLatitudeText(lat.toFixed(6));
    setLongitudeText(lon.toFixed(6));
  };
  const onPick = (lat: number, lon: number) => updatePosition(lat, lon);

  return <>
    <SurveyMap points={[]} pickPosition={onPick} pickedPosition={position} />
    <div className="form-grid">
      <div className="field"><label htmlFor="latitude">Lintang</label><input id="latitude" name="latitude" value={latitudeText} onChange={(event) => {
        const value = event.target.value;
        setLatitudeText(value);
        const lat = Number(value.replace(",", "."));
        const lon = Number(longitudeText.replace(",", "."));
        if (value && longitudeText && Number.isFinite(lat) && Number.isFinite(lon)) setPosition([lat, lon]);
      }} inputMode="decimal" placeholder="-6.200000" /></div>
      <div className="field"><label htmlFor="longitude">Bujur</label><input id="longitude" name="longitude" value={longitudeText} onChange={(event) => {
        const value = event.target.value;
        setLongitudeText(value);
        const lat = Number(latitudeText.replace(",", "."));
        const lon = Number(value.replace(",", "."));
        if (latitudeText && value && Number.isFinite(lat) && Number.isFinite(lon)) setPosition([lat, lon]);
      }} inputMode="decimal" placeholder="106.816666" /></div>
      <div className="field full"><button className="btn ghost sm" type="button" onClick={() => {
        if (!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(
          (location) => updatePosition(location.coords.latitude, location.coords.longitude),
          () => window.alert("Lokasi tidak bisa dibaca. Izinkan akses lokasi atau ketuk peta."),
          { enableHighAccuracy: true, timeout: 10000 },
        );
      }}>Gunakan lokasi saya</button></div>
    </div>
    {position && <p className="hint">Koordinat terpilih: {position[0].toFixed(6)}, {position[1].toFixed(6)}</p>}
  </>;
}
