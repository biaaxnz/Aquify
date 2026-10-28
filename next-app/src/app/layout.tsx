import type { Metadata } from "next";
import "leaflet/dist/leaflet.css";
import "./aquify.css";
import "./globals.css";

export const metadata: Metadata = {
  title: { default: "Aquify", template: "%s – Aquify" },
  description: "Smart Groundwater Exploration System",
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="id">
      <head>
        <meta name="theme-color" content="#0F4660" />
        <link rel="icon" href="/assets/aquify_badge_hd.png" />
      </head>
      <body>{children}</body>
    </html>
  );
}
