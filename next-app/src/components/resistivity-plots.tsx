import { classifyResistivity, type Layer, type SoundingPoint } from "@/lib/analysis";

const resistivityStops: Array<[number, [number, number, number]]> = [
  [1.35, [10, 10, 94]], [4, [0, 0, 224]], [12, [10, 132, 255]], [20, [0, 229, 229]],
  [35, [0, 201, 135]], [60, [61, 220, 61]], [105, [127, 184, 0]], [190, [242, 230, 0]],
  [310, [217, 154, 0]], [600, [255, 106, 0]], [919, [240, 30, 30]], [1800, [176, 0, 64]],
  [2726, [106, 0, 106]],
];

function resistivityColor(rho: number): string {
  const value = Math.max(resistivityStops[0][0], Math.min(resistivityStops[resistivityStops.length - 1][0], rho));
  for (let index = 0; index < resistivityStops.length - 1; index++) {
    const [lower, lowerColor] = resistivityStops[index];
    const [upper, upperColor] = resistivityStops[index + 1];
    if (value <= upper) {
      const ratio = (Math.log10(value) - Math.log10(lower)) / (Math.log10(upper) - Math.log10(lower));
      const color = lowerColor.map((channel, channelIndex) =>
        Math.round(channel + (upperColor[channelIndex] - channel) * ratio),
      );
      return `rgb(${color.join(",")})`;
    }
  }
  return "rgb(106,0,106)";
}

export function SoundingPlot({ sounding }: { sounding: SoundingPoint[] }) {
  if (sounding.length < 2) return <p className="hint">Masukkan minimal dua jarak pengukuran untuk melihat grafik.</p>;
  const width = 680;
  const height = 340;
  const left = 64;
  const right = 18;
  const top = 18;
  const bottom = 48;
  const logMinX = Math.floor(Math.log10(Math.min(...sounding.map((point) => point.spacing))));
  const logMaxX = Math.max(logMinX + 1, Math.ceil(Math.log10(Math.max(...sounding.map((point) => point.spacing)))));
  const logMinY = Math.floor(Math.log10(Math.min(...sounding.map((point) => point.rho))));
  const logMaxY = Math.max(logMinY + 1, Math.ceil(Math.log10(Math.max(...sounding.map((point) => point.rho)))));
  const x = (value: number) => left + (Math.log10(value) - logMinX) / (logMaxX - logMinX) * (width - left - right);
  const y = (value: number) => top + (logMaxY - Math.log10(value)) / (logMaxY - logMinY) * (height - top - bottom);
  const polyline = sounding.map((point) => `${x(point.spacing).toFixed(1)},${y(point.rho).toFixed(1)}`).join(" ");

  return (
    <div className="plot-scroll">
      <svg className="res-plot" viewBox={`0 0 ${width} ${height}`} role="img" aria-label="Grafik sounding resistivitas semu">
        {Array.from({ length: logMaxX - logMinX + 1 }, (_, index) => {
          const value = 10 ** (logMinX + index);
          const position = x(value);
          return <g key={`x${value}`}><line className="plot-grid" x1={position} y1={top} x2={position} y2={height - bottom} /><text className="plot-label" x={position} y={height - bottom + 20} textAnchor="middle">{value}</text></g>;
        })}
        {Array.from({ length: logMaxY - logMinY + 1 }, (_, index) => {
          const value = 10 ** (logMinY + index);
          const position = y(value);
          return <g key={`y${value}`}><line className="plot-grid" x1={left} y1={position} x2={width - right} y2={position} /><text className="plot-label" x={left - 8} y={position + 4} textAnchor="end">{value}</text></g>;
        })}
        <polyline className="plot-line" points={polyline} />
        {sounding.map((point) => {
          const classification = classifyResistivity(point.rho);
          return <circle key={`${point.spacing}-${point.rho}`} cx={x(point.spacing)} cy={y(point.rho)} r="5" fill={classification.color}><title>{point.spacing} m: {point.rho.toFixed(1)} Ω·m</title></circle>;
        })}
        <text className="plot-axis" x={(left + width - right) / 2} y={height - 6} textAnchor="middle">Jarak elektroda (m) · skala log</text>
        <text className="plot-axis" transform={`translate(16 ${(top + height - bottom) / 2}) rotate(-90)`} textAnchor="middle">ρa (Ω·m) · skala log</text>
      </svg>
    </div>
  );
}

export function Section2DPlot({ rows }: { rows: Array<{ x_m: number | null; spacing_m: number; rho_a: number }> }) {
  const validRows = rows.filter((row): row is { x_m: number; spacing_m: number; rho_a: number } =>
    row.x_m !== null && Number.isFinite(row.x_m) && row.spacing_m > 0 && row.rho_a > 0);
  const groups = new Map<string, number[]>();
  for (const point of validRows) {
    const key = `${point.x_m.toFixed(2)}|${point.spacing_m.toFixed(2)}`;
    groups.set(key, [...(groups.get(key) ?? []), point.rho_a]);
  }
  const points = [...groups.entries()].map(([key, values]) => {
    const [x, spacing] = key.split("|").map(Number);
    const sorted = [...values].sort((a, b) => a - b);
    const middle = Math.floor(sorted.length / 2);
    const rho = sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
    return { x_m: x, spacing_m: spacing, rho_a: rho };
  });
  const uniqueX = [...new Set(points.map((point) => point.x_m))].sort((a, b) => a - b);
  const uniqueSpacing = [...new Set(points.map((point) => point.spacing_m))].sort((a, b) => a - b);
  if (uniqueX.length < 3 || uniqueSpacing.length < 2) {
    return <div className="section-empty">
      <p className="hint">Penampang 2D muncul setelah ada pengukuran di minimal 3 posisi lintasan (x) dan 2 jarak elektroda yang berbeda.</p>
      <p className="hint">Isi “Posisi sepanjang lintasan (m)” setiap kali mengukur—misalnya 0 m, 5 m, dan 10 m. Data tanpa posisi tidak bisa membentuk penampang 2D.</p>
    </div>;
  }

  const width = 780;
  const height = 430;
  const left = 60;
  const right = 18;
  const top = 56;
  const bottom = 92;
  let dx = Number.POSITIVE_INFINITY;
  for (let index = 1; index < uniqueX.length; index++) dx = Math.min(dx, uniqueX[index] - uniqueX[index - 1]);
  let minSpacing = Number.POSITIVE_INFINITY;
  for (let index = 1; index < uniqueSpacing.length; index++) minSpacing = Math.min(minSpacing, uniqueSpacing[index] - uniqueSpacing[index - 1]);
  if (!Number.isFinite(dx) || dx <= 0) dx = 1;
  if (!Number.isFinite(minSpacing) || minSpacing <= 0) minSpacing = uniqueSpacing[0];
  const minX = uniqueX[0] - dx / 2;
  const maxX = uniqueX[uniqueX.length - 1] + dx / 2;
  const maxDepth = Math.max(...uniqueSpacing) * 0.56;
  const plotWidth = width - left - right;
  const plotHeight = height - top - bottom;
  const cellColumns = 100;
  const cellRows = 38;
  const cellWidth = plotWidth / cellColumns;
  const cellHeight = plotHeight / cellRows;
  const xPosition = (value: number) => left + (value - minX) / (maxX - minX) * plotWidth;
  const depthPosition = (depth: number) => top + depth / maxDepth * plotHeight;
  const scaled = points.map((point) => ({
    ...point,
    nx: point.x_m / dx,
    ny: point.spacing_m / minSpacing,
    value: Math.log10(point.rho_a),
  }));
  const cells: Array<{ x: number; y: number; fill: string; opacity: number }> = [];

  for (let column = 0; column < cellColumns; column++) {
    const x = minX + ((column + 0.5) / cellColumns) * (maxX - minX);
    for (let row = 0; row < cellRows; row++) {
      const depth = ((row + 0.5) / cellRows) * maxDepth;
      let weighted = 0;
      let totalWeight = 0;
      let nearest = Number.POSITIVE_INFINITY;
      const normalizedX = x / dx;
      const normalizedDepth = depth / (0.5 * minSpacing);
      const distances: Array<{ distance: number; value: number }> = [];
      for (const point of scaled) {
        const distance = Math.sqrt((normalizedX - point.nx) ** 2 + (normalizedDepth - point.ny) ** 2);
        distances.push({ distance, value: point.value });
        nearest = Math.min(nearest, distance);
      }
      if (nearest > 1.9) continue;
      distances.sort((a, b) => a.distance - b.distance);
      for (const point of distances.slice(0, 6)) {
        const weight = 1 / (point.distance ** 2 + 0.06);
        weighted += point.value * weight;
        totalWeight += weight;
      }
      if (!totalWeight) continue;
      const rho = 10 ** (weighted / totalWeight);
      cells.push({
        x: left + column * cellWidth,
        y: top + row * cellHeight,
        fill: resistivityColor(rho),
        opacity: nearest <= 1.3 ? 1 : 0.42,
      });
    }
  }

  const gradientId = `resistivity-gradient-${points.length}-${Math.round(minX * 100)}-${Math.round(maxX * 100)}`;
  const tickValues = [1, 10, 100, 1000];
  const logMin = Math.log10(1);
  const logMax = Math.log10(3000);
  const lineStep = (maxX - minX) / 6;

  return (
    <div className="section-layout">
      <div className="plot-scroll">
        <svg className="res-plot section-plot" viewBox={`0 0 ${width} ${height}`} role="img" aria-label="Penampang resistivitas semu 2D hasil interpolasi data pengukuran">
          <defs>
            <linearGradient id={gradientId} x1="0" x2="1" y1="0" y2="0">
              {Array.from({ length: 33 }, (_, index) => {
                const rho = 10 ** (logMin + (logMax - logMin) * index / 32);
                return <stop key={index} offset={`${index / 32 * 100}%`} stopColor={resistivityColor(rho)} />;
              })}
            </linearGradient>
            <filter id={`${gradientId}-soft`} x="-4%" y="-4%" width="108%" height="108%">
              <feGaussianBlur stdDeviation="1.25" />
            </filter>
            <clipPath id={`${gradientId}-clip`}><rect x={left} y={top} width={plotWidth} height={plotHeight} /></clipPath>
          </defs>
          <text className="plot-axis section-plot-title" x={left} y="24">Penampang pseudo-2D · titik ukur</text>
          <rect x={left} y={top} width={plotWidth} height={plotHeight} fill="#eef2f5" />
          <g clipPath={`url(#${gradientId}-clip)`} filter={`url(#${gradientId}-soft)`}>
            {cells.map((cell, index) => <rect key={index} x={cell.x} y={cell.y} width={cellWidth + 0.6} height={cellHeight + 0.6} fill={cell.fill} opacity={cell.opacity} />)}
          </g>
          <line className="section-surface" x1={left} y1={top} x2={width - right} y2={top} />
          {Array.from({ length: 7 }, (_, index) => {
            const value = minX + index * lineStep;
            const position = xPosition(value);
            return <g key={`x-${index}`}><line className="plot-grid" x1={position} y1={top} x2={position} y2={top + plotHeight} /><text className="plot-label" x={position} y={top - 10} textAnchor="middle">{value.toFixed(1).replace(/\.0$/, "")}</text></g>;
          })}
          {Array.from({ length: 5 }, (_, index) => {
            const depth = (index / 4) * maxDepth;
            const position = depthPosition(depth);
            return <g key={`y-${index}`}><line className="plot-grid" x1={left} y1={position} x2={width - right} y2={position} /><text className="plot-label" x={left - 8} y={position + 4} textAnchor="end">{depth.toFixed(1)}</text></g>;
          })}
          {points.map((point, index) => {
            const cx = xPosition(point.x_m);
            const cy = depthPosition(point.spacing_m * 0.5);
            return <g key={index} className="section-measurement">
              <circle cx={cx} cy={cy} r="5.2" fill="white" fillOpacity=".92" />
              <circle cx={cx} cy={cy} r="3.1" fill={resistivityColor(point.rho_a)} stroke="#123b55" strokeWidth=".8">
                <title>x {point.x_m} m, jarak elektroda {point.spacing_m} m, ρa {point.rho_a.toFixed(1)} Ω·m</title>
              </circle>
            </g>;
          })}
          <text className="plot-axis" x={(left + width - right) / 2} y={top + plotHeight + 24} textAnchor="middle">Posisi sepanjang lintasan (m)</text>
          <text className="plot-axis" transform={`translate(16 ${top + plotHeight / 2}) rotate(-90)`} textAnchor="middle">Kedalaman semu (m)</text>
          <text className="plot-axis" x={(left + width - right) / 2} y={top + plotHeight + 48} textAnchor="middle">Resistivitas semu (Ω·m)</text>
          <rect x={left} y={top + plotHeight + 56} width={plotWidth} height="13" rx="6" fill={`url(#${gradientId})`} />
          {tickValues.map((value) => {
            const position = left + (Math.log10(value) - logMin) / (logMax - logMin) * plotWidth;
            return <g key={value}><line x1={position} y1={top + plotHeight + 69} x2={position} y2={top + plotHeight + 73} stroke="#52666b" /><text className="plot-label" x={position} y={top + plotHeight + 86} textAnchor="middle">{value}</text></g>;
          })}
        </svg>
      </div>
      <aside className="section-legend">
        <b>Keterangan nilai resistivitas</b>
        <span className="section-legend-point"><i />Titik berwarna: posisi data ukur</span>
        <span><i style={{ background: resistivityColor(5) }} />&lt;10 Ω·m · lempung basah / air asin</span>
        <span><i style={{ background: resistivityColor(17) }} />10–25 Ω·m · tanah lembap / payau</span>
        <span><i style={{ background: resistivityColor(60) }} />25–150 Ω·m · indikasi lapisan berpotensi air</span>
        <span><i style={{ background: resistivityColor(250) }} />150–500 Ω·m · relatif kering / padat</span>
        <span><i style={{ background: resistivityColor(1000) }} />&gt;500 Ω·m · batuan lebih resisif</span>
        <p>Warna diinterpolasi dari pengukuran. Sumbu vertikal adalah kedalaman semu (jarak elektroda × 0,5), bukan kedalaman hasil inversi.</p>
      </aside>
    </div>
  );
}

export function LayerColumn({ layers }: { layers: Layer[] }) {
  if (!layers.length) return null;
  const maxDepth = Math.max(...layers.map((layer) => layer.to));
  return <div className="layer-column" aria-label="Lapisan resistivitas indikatif">
    {layers.map((layer, index) => <div key={`${layer.key}-${index}`} style={{ backgroundColor: layer.color, minHeight: `${Math.max(28, (layer.to - layer.from) / maxDepth * 240)}px` }}>
      <b>{layer.rho.toFixed(0)} Ω·m</b><span>{layer.label}</span><small>{layer.from.toFixed(1)}–{layer.to.toFixed(1)} m</small>
    </div>)}
  </div>;
}
