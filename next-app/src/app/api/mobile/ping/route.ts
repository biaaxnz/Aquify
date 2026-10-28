import { NextResponse } from "next/server";

export function GET() {
  return NextResponse.json({ ok: true, app: "Aquify", backend: "nextjs", status: "foundation" });
}
