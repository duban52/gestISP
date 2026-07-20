{{--
    Base común de las gráficas del módulo.

    Carga Chart.js (la misma versión que ya usan las vistas de ONT y
    PPPoE) y define tres constructores para no repetir la
    configuración en cada pantalla.
--}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    // Paleta fija del módulo: el mismo concepto conserva su color
    // en todas las pantallas y en los PDF
    const COLORES = {
        primario: '#1F4E79',
        exito: '#28a745',
        peligro: '#dc3545',
        aviso: '#ffc107',
        info: '#17a2b8',
        gris: '#6c757d',
    };

    const dineroCorto = (valor) => {
        const n = Number(valor) || 0;
        if (Math.abs(n) >= 1e9) return '$' + (n / 1e9).toFixed(1) + 'MM';
        if (Math.abs(n) >= 1e6) return '$' + (n / 1e6).toFixed(1) + 'M';
        if (Math.abs(n) >= 1e3) return '$' + Math.round(n / 1e3) + 'k';
        return '$' + n;
    };

    const dineroCompleto = (valor) =>
        '$' + (Number(valor) || 0).toLocaleString('es-CO', { maximumFractionDigits: 0 });

    const baseOpciones = (esDinero = false) => ({
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 12, padding: 14 } },
            tooltip: {
                callbacks: {
                    label: (ctx) => {
                        const v = ctx.parsed.y ?? ctx.parsed;
                        return ` ${ctx.dataset.label}: ${esDinero ? dineroCompleto(v) : v}`;
                    },
                },
            },
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { callback: (v) => (esDinero ? dineroCorto(v) : v) },
                grid: { color: 'rgba(0,0,0,.05)' },
            },
            x: { grid: { display: false } },
        },
    });

    /** Gráfica de líneas o barras con varias series */
    function graficaSeries(canvasId, labels, datasets, { tipo = 'line', dinero = false } = {}) {
        const el = document.getElementById(canvasId);
        if (!el) return null;

        return new Chart(el, {
            type: tipo,
            data: {
                labels,
                datasets: datasets.map(d => ({
                    tension: 0.3,
                    borderWidth: 2,
                    pointRadius: labels.length > 40 ? 0 : 3,
                    fill: d.fill ?? false,
                    ...d,
                })),
            },
            options: baseOpciones(dinero),
        });
    }

    /** Gráfica de anillo para distribuciones */
    function graficaAnillo(canvasId, labels, valores, colores) {
        const el = document.getElementById(canvasId);
        if (!el) return null;

        return new Chart(el, {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{ data: valores, backgroundColor: colores, borderWidth: 1, borderColor: '#fff' }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '58%',
                plugins: {
                    legend: { position: 'right', labels: { boxWidth: 12, padding: 10 } },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
                                return ` ${ctx.label}: ${ctx.parsed} (${pct}%)`;
                            },
                        },
                    },
                },
            },
        });
    }

    /** Barras horizontales, para rankings y tramos de cartera */
    function graficaBarrasH(canvasId, labels, valores, colores, dinero = false) {
        const el = document.getElementById(canvasId);
        if (!el) return null;

        return new Chart(el, {
            type: 'bar',
            data: {
                labels,
                datasets: [{ data: valores, backgroundColor: colores, borderWidth: 0 }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => ' ' + (dinero ? dineroCompleto(ctx.parsed.x) : ctx.parsed.x),
                        },
                    },
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { callback: (v) => (dinero ? dineroCorto(v) : v) },
                        grid: { color: 'rgba(0,0,0,.05)' },
                    },
                    y: { grid: { display: false } },
                },
            },
        });
    }
</script>
