// Sdílené vykreslení grafu průběhu vážení - používá index.html (poslední
// vážení, periodicky obnovované) i weighing_detail.html (konkrétní vážení
// podle id, jednorázově). Očekává na stránce elementy
// #weighing-chart-summary, #weighing-chart-svg, #weighing-chart-tooltip.

const CHART_FLAT_WINDOW_SEC = 30;   // od jakého trvání "plochého" úseku začínáme zkracovat
const CHART_FLAT_DELTA_KG = 30;      // tolerance pro "plochý" úsek
const CHART_EDGE_KEEP = 3;           // kolik bodů na začátku/konci úseku necháme v reálném měřítku
const CHART_COMPRESSED_SEC = 6;      // na kolik "virtuálních" vteřin zkrátíme libovolně dlouhý plochý úsek

function parseChartTime(t) {
    return new Date(t).getTime();
}

// Zjistí plošné úseky (po sobě jdoucí body, co se drží v rámci
// CHART_FLAT_DELTA_KG) a vrátí mapu index -> "display" čas v
// sekundách, kde dlouhé ploché úseky jsou zkrácené na
// CHART_COMPRESSED_SEC. Vrací i seznam "zlomů" (kde se zkracovalo).
function buildDisplayTimeline(points) {
    const n = points.length;
    const realT = points.map(p => (p.time - points[0].time) / 1000);

    // Najdeme ploché úseky.
    const runs = [];
    let runStart = 0;
    for (let i = 1; i <= n; i++) {
        const sameRun = i < n && Math.abs(points[i].weight - points[runStart].weight) <= CHART_FLAT_DELTA_KG;
        if (!sameRun) {
            runs.push([runStart, i - 1]);
            runStart = i;
        }
    }

    const displayT = new Array(n).fill(0);
    const breaks = [];
    let cursor = 0;
    let prevRealT = realT[0];

    for (const [start, end] of runs) {
        const duration = realT[end] - realT[start];
        const count = end - start + 1;

        if (duration > CHART_FLAT_WINDOW_SEC && count > CHART_EDGE_KEEP * 2) {
            // Necháme prvních/posledních CHART_EDGE_KEEP bodů v reálném měřítku,
            // střed zkrátíme na pevnou malou šířku.
            for (let i = start; i <= start + CHART_EDGE_KEEP - 1; i++) {
                cursor += realT[i] - prevRealT;
                displayT[i] = cursor;
                prevRealT = realT[i];
            }
            const skippedStart = realT[start + CHART_EDGE_KEEP - 1];
            const skippedEnd = realT[end - CHART_EDGE_KEEP + 1];
            const skippedSec = skippedEnd - skippedStart;

            cursor += CHART_COMPRESSED_SEC;
            breaks.push({ displayT: cursor - CHART_COMPRESSED_SEC / 2, skippedSec });

            for (let i = end - CHART_EDGE_KEEP + 1; i <= end; i++) {
                cursor += Math.max(0, realT[i] - skippedEnd);
                displayT[i] = cursor;
                prevRealT = realT[i];
            }
        } else {
            for (let i = start; i <= end; i++) {
                cursor += realT[i] - prevRealT;
                displayT[i] = cursor;
                prevRealT = realT[i];
            }
        }
    }

    return { displayT, breaks, totalDisplaySec: cursor };
}

function formatTime(date) {
    return date.toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

function formatDuration(sec) {
    sec = Math.round(sec);
    if (sec < 60) return `${sec} s`;
    const min = Math.round(sec / 60);
    return `${min} min`;
}

function renderEmptyChart(message) {
    document.getElementById('weighing-chart-summary').textContent = '';
    const svg = document.getElementById('weighing-chart-svg');
    svg.setAttribute('viewBox', '0 0 800 180');
    svg.innerHTML = `<text x="400" y="95" text-anchor="middle" fill="#5f6368" font-size="14">${message}</text>`;
}

// data = { weighing: {...}, readings: [...] } - viz get_latest_weighing_readings.php
function renderWeighingChart(data) {
    if (!data.weighing || !data.readings || data.readings.length < 2) {
        renderEmptyChart('Zatím žádné vážení se záznamem průběhu');
        return;
    }

    const w = data.weighing;
    const summaryEl = document.getElementById('weighing-chart-summary');
    const ts = new Date(w.timestamp);
    summaryEl.innerHTML = '';
    const strong = document.createElement('strong');
    strong.textContent = `${parseFloat(w.weight).toFixed(2)} kg`;
    summaryEl.appendChild(strong);
    const rest = document.createElement('span');
    rest.textContent = `${ts.toLocaleDateString('cs-CZ')} ${formatTime(ts)}${w.spz ? ' · SPZ ' + w.spz : ''}`;
    summaryEl.appendChild(rest);
    if (w.is_stabilized === false) {
        const warn = document.createElement('span');
        warn.style.color = '#d93025';
        warn.textContent = '⚠️ Neustáleno - auto odjelo předčasně';
        summaryEl.appendChild(warn);
    }

    const points = data.readings.map(r => ({
        time: parseChartTime(r.t),
        weight: r.w,
        stable: r.stable,
        button: r.button,
    }));

    const { displayT, breaks, totalDisplaySec } = buildDisplayTimeline(points);

    const W = 800, H = 180;
    const padL = 45, padR = 15, padT = 10, padB = 24;
    const plotW = W - padL - padR;
    const plotH = H - padT - padB;

    const weights = points.map(p => p.weight);
    let minW = Math.min(...weights);
    let maxW = Math.max(...weights);
    if (minW === maxW) { minW -= 10; maxW += 10; }
    const wPad = (maxW - minW) * 0.1;
    minW = Math.max(0, minW - wPad);
    maxW += wPad;

    const x = sec => padL + (totalDisplaySec > 0 ? (sec / totalDisplaySec) * plotW : 0);
    const y = weight => padT + plotH - ((weight - minW) / (maxW - minW)) * plotH;

    const xs = points.map((p, i) => x(displayT[i]));
    const ys = points.map(p => y(p.weight));

    let svgParts = [];

    // Zelené pásy stabilních úseků
    let stableStart = null;
    for (let i = 0; i <= points.length; i++) {
        const isStable = i < points.length && points[i].stable;
        if (isStable && stableStart === null) {
            stableStart = i;
        } else if (!isStable && stableStart !== null) {
            const x1 = xs[stableStart], x2 = xs[i - 1];
            svgParts.push(`<rect x="${x1.toFixed(1)}" y="${padT}" width="${Math.max(1, x2 - x1).toFixed(1)}" height="${plotH}" fill="#0ca30c" opacity="0.12"></rect>`);
            stableStart = null;
        }
    }

    // Zlomy (zkrácené úseky) - jemná klikatá značka + popisek
    for (const brk of breaks) {
        const bx = x(brk.displayT);
        svgParts.push(`
            <path d="M ${bx - 4} ${padT} L ${bx + 4} ${padT + 10} L ${bx - 4} ${padT + 20} L ${bx + 4} ${padT + 30}" stroke="#898781" stroke-width="1.5" fill="none"></path>
            <text x="${bx.toFixed(1)}" y="${H - 6}" text-anchor="middle" fill="#898781" font-size="10">zkráceno (${formatDuration(brk.skippedSec)})</text>
        `);
    }

    // Linka váhy
    const linePoints = xs.map((px, i) => `${px.toFixed(1)},${ys[i].toFixed(1)}`).join(' ');
    svgParts.push(`<polyline points="${linePoints}" fill="none" stroke="#1a73e8" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"></polyline>`);

    // Červené značky ručního tlačítka
    points.forEach((p, i) => {
        if (p.button) {
            svgParts.push(`<line x1="${xs[i].toFixed(1)}" y1="${padT}" x2="${xs[i].toFixed(1)}" y2="${padT + plotH}" stroke="#d93025" stroke-width="2"></line>`);
        }
    });

    // Osa Y - jen min/max popisek
    svgParts.push(`<line x1="${padL}" y1="${padT + plotH}" x2="${padL + plotW}" y2="${padT + plotH}" stroke="#c3c2b7" stroke-width="1"></line>`);
    svgParts.push(`<text x="${padL - 8}" y="${padT + plotH}" text-anchor="end" fill="#898781" font-size="10">${minW.toFixed(0)}</text>`);
    svgParts.push(`<text x="${padL - 8}" y="${padT + 8}" text-anchor="end" fill="#898781" font-size="10">${maxW.toFixed(0)}</text>`);

    // Osa X - čas začátku/konce
    svgParts.push(`<text x="${padL}" y="${H - 6}" text-anchor="start" fill="#898781" font-size="10">${formatTime(new Date(points[0].time))}</text>`);
    svgParts.push(`<text x="${padL + plotW}" y="${H - 6}" text-anchor="end" fill="#898781" font-size="10">${formatTime(new Date(points[points.length - 1].time))}</text>`);

    const svg = document.getElementById('weighing-chart-svg');
    svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
    svg.innerHTML = svgParts.join('');

    // Hover - crosshair + tooltip
    const tooltip = document.getElementById('weighing-chart-tooltip');
    let crosshair = null;

    svg.addEventListener('pointermove', (e) => {
        const rect = svg.getBoundingClientRect();
        const px = ((e.clientX - rect.left) / rect.width) * W;
        let nearest = 0, nearestDist = Infinity;
        xs.forEach((cx, i) => {
            const d = Math.abs(cx - px);
            if (d < nearestDist) { nearestDist = d; nearest = i; }
        });

        if (!crosshair) {
            crosshair = document.createElementNS('http://www.w3.org/2000/svg', 'line');
            crosshair.setAttribute('stroke', '#c3c2b7');
            crosshair.setAttribute('stroke-width', '1');
            svg.appendChild(crosshair);
        }
        crosshair.setAttribute('x1', xs[nearest]);
        crosshair.setAttribute('x2', xs[nearest]);
        crosshair.setAttribute('y1', padT);
        crosshair.setAttribute('y2', padT + plotH);

        const screenX = rect.left + (xs[nearest] / W) * rect.width;
        const screenY = rect.top + (ys[nearest] / H) * rect.height;
        tooltip.style.display = 'block';
        tooltip.style.left = (screenX - rect.left) + 'px';
        tooltip.style.top = (screenY - rect.top) + 'px';
        tooltip.innerHTML = `<strong>${points[nearest].weight.toFixed(2)} kg</strong><br>${formatTime(new Date(points[nearest].time))}`;
    });
    svg.addEventListener('pointerleave', () => {
        tooltip.style.display = 'none';
        if (crosshair) { crosshair.remove(); crosshair = null; }
    });
}

// Stáhne data pro dané vážení (nebo poslední, pokud id vynecháno) a rovnou
// zavolá renderWeighingChart(). Vrací stažená data, aby je volající (např.
// protokol vážení) mohl použít i pro hlavičku stránky.
async function fetchWeighingData(id) {
    const url = 'get_latest_weighing_readings.php' + (id ? ('?id=' + encodeURIComponent(id)) : '');
    const response = await fetch(url);
    if (!response.ok) throw new Error('Chyba sítě');
    return response.json();
}

async function loadLatestWeighingChart() {
    try {
        const data = await fetchWeighingData();
        renderWeighingChart(data);
    } catch (error) {
        console.error('Chyba při načítání grafu vážení:', error);
        renderEmptyChart('Chyba načítání grafu');
    }
}
