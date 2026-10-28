import Image from "next/image";
import Link from "next/link";
import { redirect } from "next/navigation";
import { signOut } from "@/app/auth-actions";
import { Icon } from "@/components/icon";
import { RailController } from "@/components/rail-controller";
import { createClient } from "@/lib/supabase/server";

const navigation = [
  { href: "/dashboard", label: "Beranda", icon: "home" },
  { href: "/geolistrik", label: "Pengukuran Tahanan Tanah", icon: "activity" },
  { href: "/map", label: "Peta Potensi Air", icon: "map" },
  { href: "/history", label: "Riwayat Survey", icon: "clock" },
  { href: "/water", label: "Cek Kualitas Air", icon: "droplet" },
  { href: "/analysis", label: "Analisa AI", icon: "sparkles" },
  { href: "/device", label: "Status Perangkat", icon: "cpu" },
  { href: "/notifications", label: "Notifikasi", icon: "bell" },
  { href: "/sync", label: "Sinkron Firebase", icon: "refresh" },
  { href: "/settings", label: "Pengaturan", icon: "settings" },
];

export async function AppShell({
  active,
  title,
  children,
}: {
  active: string;
  title: string;
  children: React.ReactNode;
}) {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) redirect("/login");

  const { data: profile } = await supabase.from("profiles").select("full_name, role").eq("id", user.id).maybeSingle();
  const displayName = profile?.full_name || user.email || "Pengguna";
  const initials = displayName.slice(0, 2).toUpperCase();
  const visibleNavigation = profile?.role === "admin"
    ? [...navigation, { href: "/admin/simulation", label: "Simulasi Integrasi", icon: "activity" }]
    : navigation;
  const initialsActive = (item: string) => item === active ? { "aria-current": "page" as const } : {};

  return (
    <>
      <RailController />
      <button className="railtoggle" id="railToggle" type="button" aria-label="Buka menu" aria-expanded="false" aria-controls="rail">
        <Icon name="more" size={22} />
      </button>
      <div className="rail-backdrop" id="railBackdrop" hidden />
      <aside className="rail" id="rail" aria-label="Menu" tabIndex={-1}>
        <div className="rail-head">
          <Link className="brand" href="/dashboard">
            <Image className="brand-mark" src="/assets/aquify_badge_hd.png" alt="" width={40} height={40} priority />
            <span><b>Aquify</b><small>Smart groundwater</small></span>
          </Link>
          <button className="rail-x" id="railClose" type="button" aria-label="Tutup menu"><Icon name="x" /></button>
        </div>
        <Link className="btn teal block" href="/survey/new"><Icon name="plus" size={18} />Survey Baru</Link>
        <nav className="rail-nav" aria-label="Menu utama">
          {visibleNavigation.map((item) => (
            <Link key={item.href} href={item.href} {...initialsActive(item.href)}>
              <Icon name={item.icon} size={19} /><span>{item.label}</span>
            </Link>
          ))}
        </nav>
        <div className="rail-foot">
          <span className="chip">Firebase belum diatur</span>
          <Link className="who" href="/settings">
            <span className="av">{initials}</span>
            <span><b>{displayName}</b><small>{profile?.role ?? "viewer"}</small></span>
          </Link>
          <form action={signOut}><button className="btn ghost sm block" type="submit">Keluar</button></form>
        </div>
      </aside>
      <main id="main" className="app-main">
        <div className="appbar">
          <h1>{title}</h1>
          <div className="appbar-r"><Link className="iconbtn" href="/notifications" aria-label="Notifikasi"><Icon name="bell" /></Link></div>
        </div>
        <div className="route-content">{children}</div>
      </main>
      <nav className="tabbar" aria-label="Navigasi bawah">
        <Link href="/dashboard" {...initialsActive("/dashboard")}><Icon name="home" size={22} /><span>Beranda</span></Link>
        <Link href="/map" {...initialsActive("/map")}><Icon name="map" size={22} /><span>Peta</span></Link>
        <Link className="fab" href="/survey/new" aria-label="Survey baru"><Icon name="plus" size={26} /></Link>
        <Link href="/history" {...initialsActive("/history")}><Icon name="clock" size={22} /><span>Riwayat</span></Link>
        <Link href="/settings" {...initialsActive("/settings")}><Icon name="user" size={22} /><span>Profil</span></Link>
      </nav>
    </>
  );
}
