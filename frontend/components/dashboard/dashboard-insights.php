<?php
$insight_config = $dashboard_insights ?? [];
$insight_rows = $insight_config['series_rows'] ?? [];
$insight_values_by_date = [];
foreach ($insight_rows as $insight_row) {
    $insight_values_by_date[(string)($insight_row['chart_date'] ?? '')] = (float)($insight_row['chart_value'] ?? 0);
}

$insight_series = [];
for ($day_offset = 6; $day_offset >= 0; $day_offset--) {
    $date = date('Y-m-d', strtotime('-' . $day_offset . ' days'));
    $insight_series[] = [
        'date' => $date,
        'label' => date('D', strtotime($date)),
        'short_date' => date('M j', strtotime($date)),
        'value' => max(0, $insight_values_by_date[$date] ?? 0),
    ];
}

$insight_values = array_column($insight_series, 'value');
$insight_total = array_sum($insight_values);
$insight_today = (float)end($insight_values);
$insight_average = $insight_total / max(1, count($insight_series));
$insight_peak = max($insight_values ?: [0]);
$insight_peak_day = '—';
if ($insight_peak > 0) {
    foreach ($insight_series as $insight_day) {
        if ($insight_day['value'] === $insight_peak) {
            $insight_peak_day = $insight_day['label'];
            break;
        }
    }
}
$insight_unit = (string)($insight_config['unit'] ?? 'records');
$format_insight_value = static function(float $value) use ($insight_unit): string {
    if ($insight_unit === 'hours') {
        $formatted = number_format($value, 1);
        return rtrim(rtrim($formatted, '0'), '.') . 'h';
    }
    return number_format((int)round($value));
};

$chart_width = 600;
$chart_left = 36;
$chart_right = 586;
$chart_top = 15;
$chart_bottom = 126;
$chart_x_step = ($chart_right - $chart_left) / max(1, count($insight_series) - 1);
$chart_scale = $insight_peak > 0 ? $insight_peak : 1;
$chart_points = [];
foreach ($insight_series as $index => $insight_day) {
    $chart_x = $chart_left + ($chart_x_step * $index);
    $chart_y = $chart_bottom - (($insight_day['value'] / $chart_scale) * ($chart_bottom - $chart_top));
    $chart_points[] = ['x' => $chart_x, 'y' => $chart_y, 'day' => $insight_day];
}
$chart_polyline = implode(' ', array_map(static fn(array $point): string => round($point['x'], 1) . ',' . round($point['y'], 1), $chart_points));
$chart_area = 'M ' . round($chart_points[0]['x'], 1) . ' ' . $chart_bottom . ' L ' . implode(' L ', array_map(static fn(array $point): string => round($point['x'], 1) . ' ' . round($point['y'], 1), $chart_points)) . ' L ' . round(end($chart_points)['x'], 1) . ' ' . $chart_bottom . ' Z';
$segment_items = $insight_config['segments'] ?? [];
$segment_total = array_sum(array_map(static fn(array $segment): int => max(0, (int)($segment['value'] ?? 0)), $segment_items));
$segment_colors = ['blue' => '#3975f6', 'green' => '#18a879', 'amber' => '#eea51d', 'violet' => '#7c67d8', 'slate' => '#91a0b5', 'red' => '#db6670'];
?>
<section class="dashboard-insights" aria-label="Dashboard analytics">
  <article class="dashboard-insights-card dashboard-insights-primary">
    <header class="dashboard-insights-heading">
      <div>
        <span class="dashboard-insights-eyebrow">LAST 7 DAYS</span>
        <h2><?= e($insight_config['title'] ?? 'Weekly activity') ?></h2>
        <p><?= e($insight_config['description'] ?? '') ?></p>
      </div>
      <div class="dashboard-insights-total">
        <strong><?= e($format_insight_value((float)$insight_total)) ?></strong>
        <span><?= e($insight_config['total_label'] ?? 'this week') ?></span>
      </div>
    </header>

    <figure class="dashboard-insights-chart">
      <svg viewBox="0 0 <?= $chart_width ?> 166" role="img" aria-label="<?= e($insight_config['title'] ?? 'Weekly activity') ?> over the last seven days">
        <defs>
          <linearGradient id="dashboardTrendFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#3975f6" stop-opacity=".18"/><stop offset="1" stop-color="#3975f6" stop-opacity="0"/></linearGradient>
        </defs>
        <?php foreach ([30, 66, 102, 126] as $grid_y): ?><line x1="<?= $chart_left ?>" y1="<?= $grid_y ?>" x2="<?= $chart_right ?>" y2="<?= $grid_y ?>" stroke="#e9edf3" stroke-width="1" stroke-dasharray="3 5"/><?php endforeach; ?>
        <path d="<?= e($chart_area) ?>" fill="url(#dashboardTrendFill)"/>
        <polyline class="dashboard-insights-line" pathLength="1" points="<?= e($chart_polyline) ?>" fill="none" stroke="#3975f6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        <?php foreach ($chart_points as $point): ?>
          <circle cx="<?= round($point['x'], 1) ?>" cy="<?= round($point['y'], 1) ?>" r="3.5" fill="#fff" stroke="#3975f6" stroke-width="2"><title><?= e($point['day']['short_date']) ?>: <?= e($format_insight_value((float)$point['day']['value'])) ?></title></circle>
        <?php endforeach; ?>
        <?php foreach ($chart_points as $point): ?><text x="<?= round($point['x'], 1) ?>" y="154" text-anchor="middle" fill="#8290a3" font-size="10" font-family="Arial, sans-serif"><?= e($point['day']['label']) ?></text><?php endforeach; ?>
      </svg>
    </figure>

    <div class="dashboard-insights-summary">
      <div><span>Today</span><strong><?= e($format_insight_value($insight_today)) ?></strong></div>
      <div><span>Daily average</span><strong><?= e($format_insight_value($insight_average)) ?></strong></div>
      <div><span>Most active</span><strong><?= e($insight_peak_day) ?></strong></div>
    </div>
  </article>

  <article class="dashboard-insights-card dashboard-insights-secondary">
    <div class="dashboard-insights-side-heading">
      <div><span class="dashboard-insights-eyebrow">DAILY BREAKDOWN</span><h2>Most active day</h2></div>
      <?php if ($insight_peak > 0): ?><span class="dashboard-insights-side-total"><?= e($format_insight_value($insight_peak)) ?></span><?php endif; ?>
    </div>
    <div class="dashboard-insights-bars" role="img" aria-label="Daily activity bars for the last seven days">
      <?php foreach ($insight_series as $insight_day):
          $bar_height = $insight_peak > 0 ? max(4, (int)round(($insight_day['value'] / $insight_peak) * 68)) : 4;
          $is_peak = $insight_peak > 0 && $insight_day['value'] === $insight_peak;
      ?>
        <div class="dashboard-insights-bar-column" title="<?= e($insight_day['short_date'] . ': ' . $format_insight_value((float)$insight_day['value'])) ?>">
          <span class="dashboard-insights-bar-value"><?= $is_peak ? e($format_insight_value((float)$insight_day['value'])) : '&nbsp;' ?></span>
          <div class="dashboard-insights-bar-track"><span class="<?= $is_peak ? 'is-peak' : '' ?>" style="height:<?= $bar_height ?>px"></span></div>
          <span class="dashboard-insights-bar-label"><?= e($insight_day['label']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="dashboard-insights-status">
      <div class="dashboard-insights-status-heading"><h3><?= e($insight_config['segments_title'] ?? 'Current snapshot') ?></h3><span><?= number_format($segment_total) ?> total</span></div>
      <?php if (!$segment_items): ?>
        <p class="dashboard-insights-empty">No status data available yet.</p>
      <?php else: foreach ($segment_items as $segment):
          $segment_value = max(0, (int)($segment['value'] ?? 0));
          $segment_color = $segment_colors[$segment['color'] ?? 'blue'] ?? $segment_colors['blue'];
          $segment_width = $segment_total > 0 ? (int)round(($segment_value / $segment_total) * 100) : 0;
      ?>
        <div class="dashboard-insights-status-row">
          <div class="dashboard-insights-status-label"><span><i style="--insight-color:<?= e($segment_color) ?>"></i><?= e($segment['label'] ?? '') ?></span><strong><?= number_format($segment_value) ?></strong></div>
          <div class="dashboard-insights-status-track"><span style="width:<?= $segment_width ?>%;--insight-color:<?= e($segment_color) ?>"></span></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </article>
</section>
