alter table public.device_readings add column if not exists external_key text;
create unique index if not exists device_readings_source_external_key_idx
  on public.device_readings(source, external_key);
alter table public.analyses add column if not exists ai_generated_at timestamptz;
