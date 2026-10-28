"use client";

import { useEffect } from "react";

export function RailController() {
  useEffect(() => {
    const rail = document.getElementById("rail");
    const toggle = document.getElementById("railToggle");
    const backdrop = document.getElementById("railBackdrop");
    const close = document.getElementById("railClose");
    if (!rail || !toggle || !backdrop) return;

    const setOpen = (open: boolean) => {
      rail.classList.toggle("open", open);
      toggle.setAttribute("aria-expanded", String(open));
      backdrop.hidden = !open;
      document.body.classList.toggle("rail-open", open);
      if (open) (rail.querySelector(".rail-nav a[aria-current]") as HTMLElement | null)?.focus();
      else toggle.focus();
    };
    const onToggle = () => setOpen(!rail.classList.contains("open"));
    const onBackdrop = () => setOpen(false);
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape" && rail.classList.contains("open")) setOpen(false);
    };
    toggle.addEventListener("click", onToggle);
    backdrop.addEventListener("click", onBackdrop);
    close?.addEventListener("click", onBackdrop);
    document.addEventListener("keydown", onKey);
    return () => {
      toggle.removeEventListener("click", onToggle);
      backdrop.removeEventListener("click", onBackdrop);
      close?.removeEventListener("click", onBackdrop);
      document.removeEventListener("keydown", onKey);
    };
  }, []);

  return null;
}
