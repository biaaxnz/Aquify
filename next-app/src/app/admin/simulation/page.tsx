import { redirect } from "next/navigation";
import { AppShell } from "@/components/app-shell";
import { IntegrationSimulator } from "@/components/integration-simulator";
import { createClient } from "@/lib/supabase/server";

export default async function AdminSimulationPage() {
  const supabase = await createClient();
  const { data: { user } } = await supabase.auth.getUser();
  if (!user) redirect("/login");

  const { data: profile, error } = await supabase.from("profiles").select("role").eq("id", user.id).maybeSingle();
  if (error) throw new Error(`Gagal memeriksa hak akses: ${error.message}`);
  if (profile?.role !== "admin") redirect("/dashboard");

  return (
    <AppShell active="/admin/simulation" title="Simulasi Integrasi">
      <div className="page-title">
        <h2>Pratinjau koneksi Aquify</h2>
        <p>Gambaran tampilan ketika ESP32, Firebase, dan Gemini terhubung.</p>
      </div>
      <IntegrationSimulator
        configuration={{
          esp32: Boolean(process.env.ESP32_INGEST_TOKEN && process.env.SUPABASE_SERVICE_ROLE_KEY),
          firebase: Boolean(process.env.FIREBASE_DATABASE_URL),
          gemini: Boolean(process.env.GEMINI_API_KEY),
        }}
      />
    </AppShell>
  );
}
