"use client";

import dynamic from "next/dynamic";
import type { MapPoint } from "@/components/survey-map-client";

const SurveyMapClient = dynamic(
  () => import("@/components/survey-map-client").then((module) => module.SurveyMap),
  { ssr: false, loading: () => <div className="empty" role="status">Memuat peta…</div> },
);

export function SurveyMap(props: {
  points: MapPoint[];
  pickPosition?: (latitude: number, longitude: number) => void;
  pickedPosition?: [number, number] | null;
}) {
  return <SurveyMapClient {...props} />;
}
