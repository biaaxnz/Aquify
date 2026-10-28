import Image from "next/image";
import Link from "next/link";

export function AuthBrand() {
  return (
    <aside className="auth-brand" aria-label="Aquify">
      <Link className="ab-top" href="/">
        <Image src="/assets/aquify_badge_hd.png" alt="" width={64} height={64} className="ab-logo" priority />
        <span className="ab-word"><b>Aquify</b><small>SMART GROUNDWATER</small></span>
      </Link>
      <div className="ab-mid">
        <span className="ab-pill"><i />Geolistrik · Kualitas Air · Analisa AI</span>
        <h2>Eksplorasi air tanah, lebih terarah.</h2>
        <p>Kelola survei geolistrik, pantau kualitas air, dan simpan data pengukuran dalam satu ruang kerja.</p>
        <ul className="ab-feats">
          <li><span className="fi"><span>01</span></span><span><b>Survei terorganisir</b><small>Catat titik dan rencana pengukuran.</small></span></li>
          <li><span className="fi"><span>02</span></span><span><b>Data mudah dibaca</b><small>Ringkasan geolistrik dan sampel air.</small></span></li>
          <li><span className="fi"><span>03</span></span><span><b>Siap terhubung perangkat</b><small>Bangun alur data bersama ESP32.</small></span></li>
        </ul>
      </div>
      <p className="ab-foot">AQUIFY · SMART GROUNDWATER EXPLORATION</p>
    </aside>
  );
}
