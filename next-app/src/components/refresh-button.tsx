"use client";

import { Icon } from "@/components/icon";

export function RefreshButton() {
  return (
    <button className="iconbtn" type="button" aria-label="Muat ulang halaman" title="Muat ulang halaman" onClick={() => window.location.reload()}>
      <Icon name="refresh" />
    </button>
  );
}
