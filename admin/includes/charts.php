<?php
/**
 * Dependency-free SVG bar chart (no external JS; works with the strict CSP).
 */

/**
 * @param array  $series ['2026-10-01' => 12, ...] (one entry per day, in order)
 * @param string $title
 * @param bool   $money  format values as ₹
 */
function svgBarChart(array $series, $title, $money = false, $color = '#3498db') {
    $w = 640; $h = 180; $pad = 28;
    $n = max(1, count($series));
    $max = max(1, max(array_map('floatval', $series ?: [0])));
    $bw = ($w - $pad) / $n;
    $total = array_sum(array_map('floatval', $series));
    $fmt = fn($v) => $money ? '₹' . number_format((float)$v, 2) : number_format((float)$v);

    $bars = '';
    $i = 0;
    foreach ($series as $date => $v) {
        $bh = round(((float)$v / $max) * ($h - 40));
        $x = round($pad + $i * $bw + 1, 1);
        $y = $h - 20 - $bh;
        $label = htmlspecialchars(date('d M', strtotime($date)) . ': ' . $fmt($v), ENT_QUOTES, 'UTF-8');
        $bars .= "<rect x=\"$x\" y=\"$y\" width=\"" . max(1, round($bw - 2, 1)) . "\" height=\"$bh\" fill=\"$color\" rx=\"2\"><title>$label</title></rect>";
        if ($n <= 31 && ($i % max(1, (int)ceil($n / 8)) === 0)) {
            $bars .= "<text x=\"" . round($x + $bw / 2, 1) . "\" y=\"" . ($h - 5) . "\" font-size=\"10\" text-anchor=\"middle\" fill=\"#7f8c8d\">" . date('d/m', strtotime($date)) . "</text>";
        }
        $i++;
    }
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $maxLabel = htmlspecialchars($fmt($max), ENT_QUOTES, 'UTF-8');
    $totalLabel = htmlspecialchars($fmt($total), ENT_QUOTES, 'UTF-8');
    return <<<SVG
<div class="content-box" style="padding:16px">
  <div style="display:flex;justify-content:space-between;margin-bottom:6px"><strong>$t</strong><span style="color:#7f8c8d">Total: $totalLabel</span></div>
  <svg viewBox="0 0 $w $h" width="100%" role="img" aria-label="$t">
    <line x1="$pad" y1="20" x2="$w" y2="20" stroke="#ecf0f1"/><text x="0" y="24" font-size="10" fill="#7f8c8d">$maxLabel</text>
    <line x1="$pad" y1="{$h}" x2="$w" y2="{$h}" stroke="#ecf0f1" transform="translate(0,-20)"/>
    $bars
  </svg>
</div>
SVG;
}

/**
 * Daily series for a date range from a "SELECT DATE(col) d, <agg> v ... GROUP BY d" query,
 * with missing days filled with 0.
 */
function dailySeries($sql, array $params, $from, $to) {
    $rows = [];
    foreach (db()->fetchAll($sql, $params) as $r) {
        $rows[$r['d']] = (float)$r['v'];
    }
    $out = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
        $d = date('Y-m-d', $t);
        $out[$d] = $rows[$d] ?? 0;
    }
    return $out;
}
