"use client";

import { useEffect, useMemo, useState } from "react";
import { CircleMarker, LayersControl, MapContainer, TileLayer, useMap, useMapEvents } from "react-leaflet";
import type { LeafletMouseEvent } from "leaflet";
import Link from "next/link";

export type MapPoint = {
  id: string;
  code: string;
  name: string;
  latitude: number;
  longitude: number;
  verdict: string | null;
  depth: number | null;
  rho: number | null;
  score: number | null;
};

const colors: Record<string, string> = {
  Tinggi: "#2CB4A4",
  Sedang: "#145C84",
  Rendah: "#D98B3A",
  "Berisiko asin/lempung": "#C4432F",
};

function MapClick({ onPick }: { onPick?: (latitude: number, longitude: number) => void }) {
  const map = useMap();
  useMapEvents({
    click(event: LeafletMouseEvent) {
      map.setView(event.latlng, Math.max(map.getZoom(), 12));
      onPick?.(event.latlng.lat, event.latlng.lng);
    },
  });
  return null;
}

function FitPoints({ points, enabled }: { points: MapPoint[]; enabled: boolean }) {
  const map = useMap();
  useEffect(() => {
    if (!enabled || !points.length) return;
    if (points.length === 1) {
      map.setView([points[0].latitude, points[0].longitude], Math.max(map.getZoom(), 12));
      return;
    }
    map.fitBounds(points.map((point) => [point.latitude, point.longitude]), { padding: [32, 32], maxZoom: 15 });
  }, [enabled, map, points]);
  return null;
}

function CenterPickedPoint({ position }: { position: [number, number] | null | undefined }) {
  const map = useMap();
  useEffect(() => {
    if (position) map.setView(position, Math.max(map.getZoom(), 12));
  }, [map, position]);
  return null;
}

export function SurveyMap({
  points,
  pickPosition,
  pickedPosition,
}: {
  points: MapPoint[];
  pickPosition?: (latitude: number, longitude: number) => void;
  pickedPosition?: [number, number] | null;
}) {
  const [query, setQuery] = useState("");
  const [filter, setFilter] = useState("all");
  const [selected, setSelected] = useState<MapPoint | null>(null);
  const filtered = useMemo(() => points.filter((point) => {
    const matchesText = `${point.code} ${point.name}`.toLowerCase().includes(query.toLowerCase());
    if (!matchesText) return false;
    if (filter === "high") return point.verdict === "Tinggi";
    if (filter === "shallow") return point.depth !== null && point.depth < 20;
    return true;
  }), [filter, points, query]);
  const center: [number, number] = filtered.length
    ? [filtered[0].latitude, filtered[0].longitude]
    : [-2.5, 118];

  return <div className="map-panel">
    {!pickPosition && <div className="map-tools">
      <input value={query} onChange={(event) => setQuery(event.target.value)} type="search" placeholder="Cari lokasi atau kode titik…" aria-label="Cari lokasi atau kode titik" />
      <select value={filter} onChange={(event) => setFilter(event.target.value)} aria-label="Filter titik">
        <option value="all">Semua titik</option><option value="high">Potensi tinggi</option><option value="shallow">Kedalaman &lt;20 m</option>
      </select>
    </div>}
    <MapContainer center={center} zoom={filtered.length ? 11 : 5} scrollWheelZoom className={pickPosition ? "survey-map picker-map" : "survey-map"}>
      <LayersControl position="topright">
        <LayersControl.BaseLayer checked name="Jalan">
          <TileLayer attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>' url="https://tile.openstreetmap.org/{z}/{x}/{y}.png" maxZoom={19} />
        </LayersControl.BaseLayer>
        <LayersControl.BaseLayer name="Satelit">
          <TileLayer attribution="Tiles &copy; Esri" url="https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}" maxZoom={19} />
        </LayersControl.BaseLayer>
      </LayersControl>
      <MapClick onPick={pickPosition} />
      <FitPoints points={filtered} enabled={!pickPosition} />
      {pickPosition && <CenterPickedPoint position={pickedPosition} />}
      {pickPosition && pickedPosition && <CircleMarker center={pickedPosition} radius={7} pathOptions={{ color: "#fff", weight: 2, fillColor: "#0E7C86", fillOpacity: 1 }} />}
      {filtered.map((point) => <CircleMarker
        key={point.id}
        center={[point.latitude, point.longitude]}
        radius={selected?.id === point.id ? 10 : 8}
        pathOptions={{ color: "#fff", weight: 2, fillColor: colors[point.verdict ?? ""] ?? "#0E7C86", fillOpacity: 1 }}
        eventHandlers={{ click: () => setSelected(point) }}
      />)}
    </MapContainer>
    {pickPosition ? <p className="hint">Ketuk peta untuk memilih koordinat lokasi.</p> : <>
      <div className="map-legend"><span>● Tinggi</span><span>● Sedang</span><span>● Rendah</span><span>● Berisiko</span></div>
      {selected && <section className="card map-selected">
        <div><h3>{selected.code} · {selected.name}</h3><p className="hint">{selected.latitude.toFixed(5)}, {selected.longitude.toFixed(5)}</p></div>
        <div className="statcards"><div><b>{selected.depth === null ? "–" : `${selected.depth} m`}</b><small>Est. kedalaman</small></div><div><b>{selected.rho === null ? "–" : `${selected.rho} Ω·m`}</b><small>Resistivitas</small></div><div><b>{selected.score ?? "–"}</b><small>Skor potensi</small></div></div>
        <Link className="btn sm" href={`/points/${selected.id}`}>Lihat detail</Link>
      </section>}
      {!filtered.length && <div className="empty"><b>Tidak ada titik yang cocok</b>Tambahkan titik dengan koordinat atau ubah filter.</div>}
    </>}
  </div>;
}
