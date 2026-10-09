document.addEventListener('DOMContentLoaded', () => {
  const themeKey = 'kapital-standart-theme';
  const themeButtons = Array.from(document.querySelectorAll('[data-theme-toggle]'));
  const currentTheme = () => document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
  const updateThemeButtons = () => {
    const dark = currentTheme() === 'dark';
    themeButtons.forEach((button) => {
      const icon = button.querySelector('[data-theme-icon]');
      const label = button.querySelector('[data-theme-label]');
      if (icon) icon.textContent = dark ? '☀' : '☾';
      if (label) label.textContent = dark ? 'Светлая тема' : 'Тёмная тема';
      button.setAttribute('aria-pressed', dark ? 'true' : 'false');
      button.setAttribute('aria-label', dark ? 'Включить светлую тему' : 'Включить тёмную тему');
    });
  };
  updateThemeButtons();
  themeButtons.forEach((button) => button.addEventListener('click', () => {
    const next = currentTheme() === 'dark' ? 'light' : 'dark';
    if (next === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
    else document.documentElement.removeAttribute('data-theme');
    try { localStorage.setItem(themeKey, next); } catch (error) { /* Theme still works for this page. */ }
    updateThemeButtons();
    window.dispatchEvent(new Event('resize'));
  }));
  document.querySelectorAll('[data-confirm]').forEach((element) => {
    element.addEventListener('click', (event) => {
      if (!window.confirm(element.getAttribute('data-confirm') || 'Подтвердить действие?')) event.preventDefault();
    });
  });
  document.querySelectorAll('[data-toggle-target]').forEach((trigger) => {
    trigger.addEventListener('change', () => {
      const target = document.getElementById(trigger.getAttribute('data-toggle-target'));
      if (target) target.hidden = !trigger.checked;
    });
  });
});

// Lightweight history chart; the data comes from the signed-in server session.
document.querySelectorAll('[data-history-chart]').forEach((chartRoot) => {
  const toggle = chartRoot.querySelector('[data-chart-toggle]');
  const panel = chartRoot.querySelector('[data-chart-panel]');
  const canvas = chartRoot.querySelector('[data-chart-canvas]');
  if (!toggle || !panel || !canvas) return;

  let rows = [];
  try { rows = JSON.parse(chartRoot.getAttribute('data-chart-data') || '[]'); }
  catch (error) { rows = []; }
  const totals = rows.reduce((result, row) => {
    result.income += Number(row.income || 0);
    result.expense += Number(row.expense || 0);
    return result;
  }, { income: 0, expense: 0 });
  const currency = (minor) => new Intl.NumberFormat('ru-RU', {
    style: 'currency', currency: 'RUB', maximumFractionDigits: 0
  }).format(Number(minor || 0) / 100);
  chartRoot.querySelectorAll('[data-chart-total]').forEach((node) => {
    const type = node.getAttribute('data-chart-total');
    node.textContent = currency(totals[type] || 0);
  });

  const drawChart = () => {
    if (panel.hidden) return;
    const context = canvas.getContext('2d');
    if (!context) return;
    const width = Math.max(220, Math.round(canvas.getBoundingClientRect().width));
    const height = window.matchMedia('(max-width: 620px)').matches ? 270 : 300;
    const ratio = Math.max(1, window.devicePixelRatio || 1);
    canvas.width = Math.round(width * ratio);
    canvas.height = Math.round(height * ratio);
    canvas.style.height = height + 'px';
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    context.clearRect(0, 0, width, height);

    const pad = { top: 18, right: 10, bottom: 38, left: 54 };
    const plotW = Math.max(1, width - pad.left - pad.right);
    const plotH = Math.max(1, height - pad.top - pad.bottom);
    const maxValue = Math.max(0, ...rows.map((row) => Math.max(Number(row.income || 0), Number(row.expense || 0)) / 100));
    const rawStep = Math.max(1, maxValue / 4);
    const magnitude = Math.pow(10, Math.floor(Math.log10(rawStep)));
    const scaled = rawStep / magnitude;
    const niceStep = (scaled <= 1 ? 1 : scaled <= 2 ? 2 : scaled <= 5 ? 5 : 10) * magnitude;
    const axisMax = Math.max(niceStep * 4, 1);
    const moneyLabel = (value) => {
      if (value >= 1000000) return (value / 1000000).toLocaleString('ru-RU', { maximumFractionDigits: 1 }) + ' млн';
      if (value >= 1000) return (value / 1000).toLocaleString('ru-RU', { maximumFractionDigits: 1 }) + ' тыс.';
      return Math.round(value).toLocaleString('ru-RU');
    };
    context.font = '11px Segoe UI, sans-serif';
    context.textAlign = 'right';
    context.textBaseline = 'middle';
    for (let tick = 0; tick <= 4; tick++) {
      const value = axisMax * tick / 4;
      const y = pad.top + plotH - (value / axisMax) * plotH;
      context.strokeStyle = document.documentElement.getAttribute('data-theme') === 'dark' ? '#29374d' : '#e8edf5';
      context.lineWidth = 1;
      context.beginPath(); context.moveTo(pad.left, y); context.lineTo(width - pad.right, y); context.stroke();
      context.fillStyle = document.documentElement.getAttribute('data-theme') === 'dark' ? '#9aaac0' : '#7a8798';
      context.fillText(moneyLabel(value), pad.left - 8, y);
    }
    const groupW = plotW / Math.max(1, rows.length);
    const barW = Math.max(3, Math.min(18, (groupW - 6) / 2));
    rows.forEach((row, index) => {
      const center = pad.left + groupW * (index + 0.5);
      const income = Number(row.income || 0) / 100;
      const expense = Number(row.expense || 0) / 100;
      const incomeH = income / axisMax * plotH;
      const expenseH = expense / axisMax * plotH;
      context.fillStyle = '#2864dc';
      context.fillRect(center - barW - 1, pad.top + plotH - incomeH, barW, incomeH);
      context.fillStyle = '#d95c68';
      context.fillRect(center + 1, pad.top + plotH - expenseH, barW, expenseH);
      context.fillStyle = document.documentElement.getAttribute('data-theme') === 'dark' ? '#9aaac0' : '#66758a';
      context.textAlign = 'center';
      context.textBaseline = 'top';
      context.fillText(String(row.label || '').split(' ')[0], center, pad.top + plotH + 10);
    });
  };

  toggle.addEventListener('click', () => {
    const shouldOpen = panel.hidden;
    panel.hidden = !shouldOpen;
    toggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
    toggle.textContent = shouldOpen ? 'Скрыть диаграмму' : 'Открыть диаграмму';
    if (shouldOpen) requestAnimationFrame(drawChart);
  });
  window.addEventListener('resize', drawChart);
});
