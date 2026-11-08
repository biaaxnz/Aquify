"use client";

import { Icon } from "@/components/icon";

export function RefreshButton() {
  return (
    <button className="refresh-action" type="button" aria-label="Muat ulang halaman" title="Muat ulang halaman" onClick={() => window.location.reload()}>
      <Icon name="refresh" size={18} />
      <span>Muat ulang</span>
    </button>
  );
}
