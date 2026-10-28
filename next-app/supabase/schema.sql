-- Aquify v2 starting schema for Supabase PostgreSQL.
-- Run in Supabase SQL Editor. Keep the current XAMPP/MySQL database as a backup.

create extension if not exists pgcrypto;

create table public.profiles (
  id uuid primary key references auth.users(id) on delete cascade,
  full_name text not null default '',
  role text not null default 'viewer' check (role in ('admin', 'viewer')),
  created_at timestamptz not null default now()
);

create function public.handle_new_user()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
  insert into public.profiles (id, full_name)
  values (new.id, coalesce(new.raw_user_meta_data ->> 'full_name', ''));
  return new;
end;
$$;

create trigger on_auth_user_created
  after insert on auth.users
  for each row execute procedure public.handle_new_user();

create function public.prevent_profile_role_change()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  if auth.uid() is not null and new.role is distinct from old.role then
    raise exception 'Profile roles can only be changed by the project owner in the database console';
  end if;
  return new;
end;
$$;

create trigger prevent_profile_role_change
  before update on public.profiles
  for each row execute procedure public.prevent_profile_role_change();

create table public.points (
  id uuid primary key default gen_random_uuid(),
  owner_id uuid not null references public.profiles(id) on delete cascade default auth.uid(),
  code text not null,
  name text not null,
  survey_type text not null default 'geovolt' check (survey_type in ('geovolt', 'water')),
  latitude double precision check (latitude is null or latitude between -90 and 90),
  longitude double precision check (longitude is null or longitude between -180 and 180),
  notes text,
  team text,
  plan jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default now(),
  unique (owner_id, code)
);

create table public.measurements (
  id uuid primary key default gen_random_uuid(),
  point_id uuid not null references public.points(id) on delete cascade,
  device_id text,
  config text not null default 'wenner' check (config in ('wenner', 'schlumberger')),
  spacing_m double precision not null check (spacing_m > 0),
  mn_m double precision,
  dv_mv double precision not null default 0,
  i_ma double precision not null default 0,
  rho_a double precision not null check (rho_a > 0),
  x_m double precision,
  source text not null default 'manual',
  external_key text,
  raw_json jsonb,
  measured_at timestamptz not null default now(),
  created_at timestamptz not null default now(),
  unique (source, external_key)
);

create index measurements_point_spacing_idx on public.measurements(point_id, spacing_m);
create index measurements_measured_at_idx on public.measurements(measured_at desc);

create table public.water_samples (
  id uuid primary key default gen_random_uuid(),
  point_id uuid not null references public.points(id) on delete cascade,
  device_id text,
  tds_ppm double precision,
  ec_us double precision,
  ph double precision check (ph is null or ph between 0 and 14),
  temp_c double precision,
  turbidity_ntu double precision,
  source text not null default 'manual',
  external_key text,
  raw_json jsonb,
  measured_at timestamptz not null default now(),
  created_at timestamptz not null default now(),
  unique (source, external_key),
  check (tds_ppm is not null or ec_us is not null or ph is not null or temp_c is not null or turbidity_ntu is not null)
);

create index water_samples_point_measured_idx on public.water_samples(point_id, measured_at desc);

create table public.analyses (
  id uuid primary key default gen_random_uuid(),
  point_id uuid not null references public.points(id) on delete cascade,
  mode text not null default 'rule' check (mode in ('rule', 'ai')),
  score smallint not null default 0 check (score between 0 and 100),
  verdict text not null default '',
  best_depth double precision,
  best_rho double precision,
  result jsonb,
  ai_text text,
  ai_generated_at timestamptz,
  created_at timestamptz not null default now()
);

create index analyses_point_created_idx on public.analyses(point_id, created_at desc);

create table public.device_readings (
  id uuid primary key default gen_random_uuid(),
  point_id uuid references public.points(id) on delete set null,
  device_id text,
  reading_type text not null check (reading_type in ('water', 'geoelectric', 'other')),
  source text not null default 'esp32',
  external_key text,
  payload jsonb not null,
  measured_at timestamptz not null default now(),
  created_at timestamptz not null default now()
);

create index device_readings_point_time_idx on public.device_readings(point_id, measured_at desc);
alter table public.device_readings add column if not exists external_key text;
create unique index if not exists device_readings_source_external_key_idx on public.device_readings(source, external_key);

create function public.is_point_owner(target_point uuid)
returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  select exists (
    select 1 from public.points
    where id = target_point and owner_id = auth.uid()
  );
$$;

alter table public.profiles enable row level security;
alter table public.points enable row level security;
alter table public.measurements enable row level security;
alter table public.water_samples enable row level security;
alter table public.analyses enable row level security;
alter table public.device_readings enable row level security;

create policy "Users read own profile" on public.profiles
  for select to authenticated using (id = auth.uid());
create policy "Users update own profile" on public.profiles
  for update to authenticated using (id = auth.uid()) with check (id = auth.uid());

create policy "Users manage own points" on public.points
  for all to authenticated using (owner_id = auth.uid()) with check (owner_id = auth.uid());

create policy "Users manage measurements on own points" on public.measurements
  for all to authenticated using (public.is_point_owner(point_id)) with check (public.is_point_owner(point_id));
create policy "Users manage water samples on own points" on public.water_samples
  for all to authenticated using (public.is_point_owner(point_id)) with check (public.is_point_owner(point_id));
create policy "Users manage analyses on own points" on public.analyses
  for all to authenticated using (public.is_point_owner(point_id)) with check (public.is_point_owner(point_id));
create policy "Users read own device readings" on public.device_readings
  for select to authenticated using (point_id is not null and public.is_point_owner(point_id));

grant usage on schema public to authenticated;
grant select, update on public.profiles to authenticated;
grant select, insert, update, delete on public.points to authenticated;
grant select, insert, update, delete on public.measurements to authenticated;
grant select, insert, update, delete on public.water_samples to authenticated;
grant select, insert, update, delete on public.analyses to authenticated;
grant select on public.device_readings to authenticated;
