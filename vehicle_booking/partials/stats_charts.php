<?php
/**
 * Small, dependency-free charts for the Administrator dashboard.
 * Plain HTML/CSS bars (styles in admin_dashboard.php) so they print cleanly
 * and work offline. Each mark carries data-tip for the hover tooltip and a
 * visible value label, so nothing relies on colour or hover alone.
 */

/** Horizontal bars: one row per label, value printed at the end of the bar. */
function vbChartBars(array $rows, string $unit = '', string $empty = 'No data for this period.'): string
{
    $max = 0;
    foreach ($rows as $r) $max = max($max, (float)$r['value']);
    if ($max <= 0) {
        return '<p class="vb-chart-empty">' . htmlspecialchars($empty) . '</p>';
    }
    $html = '<div class="vb-hbars">';
    foreach ($rows as $r) {
        $v     = (float)$r['value'];
        $w     = $v > 0 ? max(1.5, $v / $max * 100) : 0;
        $value = number_format($v) . ($unit !== '' ? ' ' . $unit : '');
        $label = htmlspecialchars((string)$r['label']);
        $html .= '<div class="vb-hbar-row">'
               .   '<div class="vb-hbar-label" title="' . $label . '">' . $label . '</div>'
               .   '<div class="vb-hbar-track">'
               .     '<div class="vb-hbar" style="width:' . round($w, 2) . '%" data-tip="' . $label . ': ' . htmlspecialchars($value) . '"></div>'
               .     '<span class="vb-hbar-value">' . htmlspecialchars($value) . '</span>'
               .   '</div>'
               . '</div>';
    }
    return $html . '</div>';
}

/** Vertical columns over time, with a light y-axis grid and sparse x labels. */
function vbChartColumns(array $points, string $unit = 'requests'): string
{
    $max = 0;
    foreach ($points as $p) $max = max($max, (int)$p['value']);
    if ($max <= 0) {
        return '<p class="vb-chart-empty">No requests were raised in this period.</p>';
    }
    // A "nice" axis top: 1, 2, 5 x 10^n at or above the max.
    $mag  = (int)(10 ** floor(log10($max)));
    $top  = $max;
    foreach ([1, 2, 5, 10] as $m) { if ($m * $mag >= $max) { $top = $m * $mag; break; } }
    $top  = max($top, 1);
    $count = count($points);
    $every = max(1, (int)ceil($count / 8));   // at most ~8 x-axis labels

    $html  = '<div class="vb-cols-wrap">';
    $html .= '<div class="vb-cols-axis"><span>' . number_format($top) . '</span><span>' . number_format($top / 2, $top % 2 ? 1 : 0) . '</span><span>0</span></div>';
    $html .= '<div class="vb-cols-plot"><div class="vb-cols">';
    foreach ($points as $i => $p) {
        $v = (int)$p['value'];
        $h = $v > 0 ? max(1.5, $v / $top * 100) : 0;
        $label = htmlspecialchars($p['label']);
        $html .= '<div class="vb-col-slot" data-tip="' . $label . ': ' . number_format($v) . ' ' . htmlspecialchars($unit) . '">'
               .   '<div class="vb-col" style="height:' . round($h, 2) . '%"></div>'
               . '</div>';
    }
    $html .= '</div><div class="vb-cols-x">';
    foreach ($points as $i => $p) {
        $html .= '<span>' . ($i % $every === 0 ? htmlspecialchars($p['label']) : '') . '</span>';
    }
    return $html . '</div></div></div>';
}

/**
 * One stacked bar per approval stage, split into "On the system" and
 * "By email", with counts written inside each segment and a legend.
 */
function vbChartChannel(array $channel, array $stageLabels): string
{
    $max = 0;
    foreach ($channel as $c) $max = max($max, $c['system'] + $c['email']);
    if ($max <= 0) {
        return '<p class="vb-chart-empty">No approvals or rejections in this period.</p>';
    }
    $html  = '<div class="vb-legend">'
           . '<span><i class="vb-swatch vb-s1"></i>On the system</span>'
           . '<span><i class="vb-swatch vb-s2"></i>By email</span>'
           . '</div><div class="vb-hbars">';
    foreach ($channel as $stage => $c) {
        $total = $c['system'] + $c['email'];
        $label = htmlspecialchars($stageLabels[$stage] ?? $stage);
        $html .= '<div class="vb-hbar-row"><div class="vb-hbar-label">' . $label . '</div><div class="vb-hbar-track">';
        if ($total > 0) {
            $html .= '<div class="vb-stack" style="width:' . round(max(3, $total / $max * 100), 2) . '%">';
            foreach (['system' => ['vb-s1', 'on the system'], 'email' => ['vb-s2', 'by email']] as $k => [$cls, $txt]) {
                if ($c[$k] <= 0) continue;
                $html .= '<div class="vb-seg ' . $cls . '" style="flex:' . $c[$k] . '" data-tip="' . $label . ': ' . $c[$k] . ' ' . $txt
                       . ' (' . vbPct($c[$k], $total) . ')"><span>' . $c[$k] . '</span></div>';
            }
            $html .= '</div>';
        }
        $html .= '<span class="vb-hbar-value">' . $total . '</span></div></div>';
    }
    return $html . '</div>';
}
