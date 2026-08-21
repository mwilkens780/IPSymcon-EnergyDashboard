<?php

declare(strict_types=1);

class EnergyDashboard extends IPSModule
{
    private const CHART_SPAN_SEC = 6 * 3600;
    private const DIAL_RANGES = [
        'pv_limit' => [0.0, 100.0, 1.0],
    ];
    // Balkonkraftwerk: gesetzliche Einspeisegrenze.
    private const PV_LIMIT_WATT = 800.0;

    // ─── Lifecycle ────────────────────────────────────────────────────────────

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('update_interval', 60);
        // Archive Control instance used to read power/consumption history --
        // same instance as the WeatherDashboard module already uses on this
        // installation, since there's normally only one per system.
        $this->RegisterPropertyInteger('archive_instance', 59233);

        // No sensible defaults exist -- Solar/OpenDTU and the Stromzaehler
        // have installation-specific object IDs that couldn't be discovered
        // without console access, so every variable starts unconfigured (0).
        $this->RegisterPropertyInteger('var_pv_power_ac',       0);
        $this->RegisterPropertyInteger('var_pv_power_dc',       0);
        $this->RegisterPropertyInteger('var_pv_voltage',        0);
        $this->RegisterPropertyInteger('var_pv_current',        0);
        $this->RegisterPropertyInteger('var_pv_frequency',      0);
        $this->RegisterPropertyInteger('var_pv_efficiency',     0);
        $this->RegisterPropertyInteger('var_pv_temperature',    0);
        $this->RegisterPropertyInteger('var_pv_energy_today',   0);
        $this->RegisterPropertyInteger('var_pv_energy_total',   0);
        $this->RegisterPropertyInteger('var_pv_producing',      0);
        $this->RegisterPropertyInteger('var_pv_reachable',      0);
        $this->RegisterPropertyInteger('var_pv_online',         0);
        $this->RegisterPropertyInteger('var_pv_limit_relative', 0);

        $this->RegisterPropertyInteger('var_meter_energy_total', 0);
        $this->RegisterPropertyInteger('var_meter_power',        0);

        $this->RegisterTimer('UpdateTimer', 0, 'END_Refresh($_IPS[\'TARGET\']);');

        $this->SetVisualizationType(1);
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if ($this->ReadPropertyInteger('var_pv_power_ac') === 0 && $this->ReadPropertyInteger('var_meter_energy_total') === 0) {
            $this->SetStatus(201);
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }

        $interval = $this->ReadPropertyInteger('update_interval');
        $this->SetTimerInterval('UpdateTimer', $interval > 0 ? $interval * 1000 : 0);
        $this->SetStatus(102);

        $this->Refresh();
    }

    // ─── HTML-SDK: dashboard tile ──────────────────────────────────────────────

    public function GetVisualizationTile(): string
    {
        return $this->buildDashboardHTML();
    }

    // ─── Public update ────────────────────────────────────────────────────────

    public function Refresh(): void
    {
        try {
            $data = $this->collectData();
            $this->pushValue('__all__', $data);
            $this->SetStatus(102);
        } catch (\Throwable $e) {
            $this->LogMessage('EnergyDashboard Refresh: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
        }
    }

    // ─── IPS action handler ─────────────────────────────────────────────────────

    /**
     * This module owns none of the underlying variables -- OpenDTU does.
     * Writes are forwarded via the global RequestAction($VariableID, $Value),
     * exactly like the Heating Dashboard forwards to VitoConnect.
     */
    public function RequestAction($Ident, $Value): void
    {
        if ($Ident === 'pv_limit') {
            try {
                $targetID = $this->ReadPropertyInteger('var_pv_limit_relative');
                if ($targetID > 0) {
                    RequestAction($targetID, (float) $Value);
                    $this->pushValue($Ident, (float) $Value); // optimistic UI update
                }
            } catch (\Throwable $e) {
                $this->LogMessage('EnergyDashboard RequestAction ' . $Ident . ': ' . $e->getMessage(), KL_ERROR);
            }
        } else {
            $this->LogMessage("EnergyDashboard RequestAction: unknown ident {$Ident}", KL_WARNING);
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function pushValue(string $key, $value): void
    {
        $this->UpdateVisualizationValue(json_encode(['key' => $key, 'value' => $value]));
    }

    private function readVar(string $prop)
    {
        $id = $this->ReadPropertyInteger($prop);
        return $this->readVarById($id);
    }

    private function readVarById(int $id)
    {
        if ($id <= 0 || !@IPS_VariableExists($id)) {
            return null;
        }
        return GetValue($id);
    }

    private function fmtNum($v, int $decimals = 0): string
    {
        if ($v === null) {
            return '–';
        }
        return number_format((float) $v, $decimals, ',', '.');
    }

    /** All archived rows for $varId within the last $spanSec, oldest-first as [ts, value]. */
    private function archivedSeries(int $varId, int $spanSec): array
    {
        $archiveID = $this->ReadPropertyInteger('archive_instance');
        if ($archiveID <= 0 || $varId <= 0 || !function_exists('AC_GetLoggedValues')) {
            return [];
        }
        $now = time();
        try {
            $rows = @AC_GetLoggedValues($archiveID, $varId, $now - $spanSec, $now, 0);
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($rows)) {
            return [];
        }
        $series = [];
        foreach (array_reverse($rows) as $row) { // AC_GetLoggedValues returns newest-first
            if (isset($row['TimeStamp'], $row['Value'])) {
                $series[] = [(int) $row['TimeStamp'], (float) $row['Value']];
            }
        }
        return $series;
    }

    /**
     * Approximates a power (W) curve from an ever-increasing energy counter
     * (Wh) that has no separate instantaneous-power variable: average power
     * between each pair of consecutive archived readings = Wh delta / hours.
     */
    private function derivePowerSeries(int $varId, int $spanSec): array
    {
        $rows = $this->archivedSeries($varId, $spanSec);
        if (count($rows) < 2) {
            return [];
        }
        $series = [];
        for ($i = 1; $i < count($rows); $i++) {
            [$ts1, $v1] = $rows[$i - 1];
            [$ts2, $v2] = $rows[$i];
            $dtH = ($ts2 - $ts1) / 3600.0;
            if ($dtH <= 0) {
                continue;
            }
            $series[] = [$ts2, max(0.0, ($v2 - $v1) / $dtH)];
        }
        return $series;
    }

    /** Earliest archived value at or after $ts -- used as a period's starting counter reading. */
    private function counterAtOrAfter(int $varId, int $ts): ?float
    {
        $archiveID = $this->ReadPropertyInteger('archive_instance');
        if ($archiveID <= 0 || $varId <= 0 || !function_exists('AC_GetLoggedValues')) {
            return null;
        }
        try {
            $rows = @AC_GetLoggedValues($archiveID, $varId, $ts, time(), 0);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($rows) || count($rows) === 0) {
            return null;
        }
        $oldest = end($rows); // newest-first, so the last element is the earliest after $ts
        return isset($oldest['Value']) ? (float) $oldest['Value'] : null;
    }

    /**
     * Delta of an ever-increasing counter between two points in time. $end
     * close to "now" reads the live value instead of the (possibly lagging)
     * archive, everything else is resolved from the archive -- this is what
     * makes "previous period" (e.g. last month, bounded on both ends in the
     * past) possible without waiting for a rollover to be observed live,
     * unlike the Heating Dashboard's period_prev tracking.
     */
    private function counterDelta(int $varId, int $start, int $end): ?float
    {
        $base = $this->counterAtOrAfter($varId, $start);
        if ($base === null) {
            return null;
        }
        $endVal = ($end >= time() - 60) ? $this->readVarById($varId) : $this->counterAtOrAfter($varId, $end);
        if ($endVal === null) {
            return null;
        }
        return max(0.0, (float) $endVal - $base);
    }

    /** Per-calendar-day maximum of a daily-resetting counter (e.g. "Energie heute") within [start,end]. */
    private function dailyMaxByDay(int $varId, int $start, int $end): array
    {
        $archiveID = $this->ReadPropertyInteger('archive_instance');
        if ($archiveID <= 0 || $varId <= 0 || !function_exists('AC_GetLoggedValues')) {
            return [];
        }
        try {
            $rows = @AC_GetLoggedValues($archiveID, $varId, $start, $end, 0);
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($rows)) {
            return [];
        }
        $byDay = [];
        foreach ($rows as $row) {
            if (!isset($row['TimeStamp'], $row['Value'])) {
                continue;
            }
            $day = date('Y-m-d', (int) $row['TimeStamp']);
            $val = (float) $row['Value'];
            if (!isset($byDay[$day]) || $val > $byDay[$day]) {
                $byDay[$day] = $val;
            }
        }
        return $byDay;
    }

    /** Sum of daily maxima within [start,end] -- period total for a daily-resetting yield counter. */
    private function yieldForRange(int $varId, int $start, int $end): ?float
    {
        $byDay = $this->dailyMaxByDay($varId, $start, $end);
        return count($byDay) > 0 ? array_sum($byDay) : null;
    }

    /** [{label, value(kWh)}] for the last 7 calendar days, oldest first. */
    private function last7DaysYield(int $varId): array
    {
        $todayStart = strtotime('today');
        $start      = strtotime('-6 days', $todayStart);
        $byDay      = $this->dailyMaxByDay($varId, $start, time());

        $names = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
        $out   = [];
        for ($i = 6; $i >= 0; $i--) {
            $dayTs = strtotime("-{$i} days", $todayStart);
            $day   = date('Y-m-d', $dayTs);
            $label = $i === 0 ? 'Heute' : $names[(int) date('w', $dayTs)];
            $out[] = ['label' => $label, 'value' => isset($byDay[$day]) ? $byDay[$day] / 1000.0 : null];
        }
        return $out;
    }

    private function whToKwh(?float $wh): ?float
    {
        return $wh !== null ? $wh / 1000.0 : null;
    }

    /** SVG polyline points + real min/max for a series, scaled into a 300x60 box. */
    private function chartData(array $series, float $viewW = 300.0, float $viewH = 60.0): array
    {
        if (count($series) < 2) {
            return ['points' => '', 'min' => null, 'max' => null];
        }
        $startTs = time() - self::CHART_SPAN_SEC;
        $values  = array_column($series, 1);
        $realMin = min($values);
        $realMax = max($values);
        $scaleMin = $realMin;
        $scaleMax = $realMax;
        if ($scaleMax - $scaleMin < 1.0) {
            $mid      = ($scaleMax + $scaleMin) / 2;
            $scaleMin = $mid - 0.5;
            $scaleMax = $mid + 0.5;
        }
        $pad = ($scaleMax - $scaleMin) * 0.15;
        $scaleMin -= $pad;
        $scaleMax += $pad;

        $pts = [];
        foreach ($series as [$ts, $val]) {
            $x = max(0, min($viewW, ($ts - $startTs) / self::CHART_SPAN_SEC * $viewW));
            $y = $viewH - (($val - $scaleMin) / ($scaleMax - $scaleMin)) * $viewH;
            $pts[] = round($x, 1) . ',' . round($y, 1);
        }
        return ['points' => implode(' ', $pts), 'min' => $realMin, 'max' => $realMax];
    }

    /**
     * Like chartData() but scaled 0..max(observed, PV_LIMIT_WATT) so the
     * 800W Balkonkraftwerk-Grenze is always visible, and split into
     * red/green polyline segments at the threshold (with the crossing
     * point linearly interpolated so the color change lands exactly on
     * the line, not at a jagged data-point boundary).
     */
    private function pvChartGeometry(array $series): array
    {
        if (count($series) < 2) {
            return ['segments' => [], 'thresholdY' => null, 'min' => null, 'max' => null];
        }
        $startTs = time() - self::CHART_SPAN_SEC;
        $values  = array_column($series, 1);
        $realMin = min($values);
        $realMax = max($values);
        $scaleMax = max($realMax, self::PV_LIMIT_WATT) * 1.1;
        if ($scaleMax <= 0) {
            $scaleMax = self::PV_LIMIT_WATT * 1.1;
        }
        $scaleMin = 0.0;

        $toXY = function (float $ts, float $val) use ($startTs, $scaleMin, $scaleMax): array {
            $x = max(0.0, min(300.0, ($ts - $startTs) / self::CHART_SPAN_SEC * 300.0));
            $y = 60.0 - (($val - $scaleMin) / ($scaleMax - $scaleMin)) * 60.0;
            return [$x, $y];
        };

        $segments = [];
        $current  = null;
        $n        = count($series);
        for ($i = 0; $i < $n; $i++) {
            [$ts, $val] = $series[$i];
            $xy    = $toXY((float) $ts, $val);
            $color = $val > self::PV_LIMIT_WATT ? 'red' : 'green';
            if ($current === null) {
                $current = ['color' => $color, 'pts' => [$xy]];
                continue;
            }
            if ($color !== $current['color']) {
                [$prevTs, $prevVal] = $series[$i - 1];
                $denom = $val - $prevVal;
                if (abs($denom) > 1e-9) {
                    $frac    = max(0.0, min(1.0, (self::PV_LIMIT_WATT - $prevVal) / $denom));
                    $crossTs = $prevTs + $frac * ($ts - $prevTs);
                    $cxy     = $toXY($crossTs, self::PV_LIMIT_WATT);
                    $current['pts'][] = $cxy;
                    $segments[] = $current;
                    $current = ['color' => $color, 'pts' => [$cxy, $xy]];
                } else {
                    $segments[] = $current;
                    $current = ['color' => $color, 'pts' => [$xy]];
                }
            } else {
                $current['pts'][] = $xy;
            }
        }
        if ($current !== null) {
            $segments[] = $current;
        }

        [, $thresholdY] = $toXY((float) $startTs, self::PV_LIMIT_WATT);

        return [
            'segments' => array_map(static function (array $s): array {
                return [
                    'color'  => $s['color'],
                    'points' => implode(' ', array_map(static fn (array $p) => round($p[0], 1) . ',' . round($p[1], 1), $s['pts'])),
                ];
            }, $segments),
            'thresholdY' => round($thresholdY, 1),
            'min' => $realMin,
            'max' => $realMax,
        ];
    }

    /** Thumb (x,y) on the 120x120/r45/center60,60 dial arc for a value in [min,max]. */
    private function dialThumbPos(float $value, float $min, float $max): array
    {
        $frac     = $max > $min ? max(0, min(1, ($value - $min) / ($max - $min))) : 0;
        $angleDeg = -135 + $frac * 270;
        $rad      = deg2rad($angleDeg);
        return [round(60 + 45 * sin($rad), 1), round(60 - 45 * cos($rad), 1)];
    }

    private function collectData(): array
    {
        $pvPowerAc     = $this->readVar('var_pv_power_ac');
        $meterEnergyId = $this->ReadPropertyInteger('var_meter_energy_total');
        $meterEnergy   = $this->readVarById($meterEnergyId);
        $meterPowerId  = $this->ReadPropertyInteger('var_meter_power');
        $energyTodayId = $this->ReadPropertyInteger('var_pv_energy_today');
        $energyToday   = $this->readVarById($energyTodayId);

        $meterPowerCurrent = null;
        if ($meterPowerId > 0) {
            $meterPowerCurrent = $this->readVarById($meterPowerId);
        } else {
            $derived = $this->derivePowerSeries($meterEnergyId, self::CHART_SPAN_SEC);
            if (count($derived) > 0) {
                $meterPowerCurrent = end($derived)[1];
            }
        }

        $todayStart     = strtotime('today');
        $yesterdayStart = strtotime('-1 day', $todayStart);
        $weekStart      = strtotime('monday this week', $todayStart);
        $lastWeekStart  = strtotime('-7 days', $weekStart);
        $monthStart     = strtotime('first day of this month', $todayStart);
        $lastMonthStart = strtotime('first day of last month', $todayStart);
        $thisYear       = (int) date('Y', $todayStart);
        $yearStart      = mktime(0, 0, 0, 1, 1, $thisYear);
        $lastYearStart  = mktime(0, 0, 0, 1, 1, $thisYear - 1);
        $now            = time();
        $meterCur       = $meterEnergy !== null ? (float) $meterEnergy : null;
        $prodDayCur     = $energyToday !== null ? (float) $energyToday : null;

        return [
            'pvPowerAc'    => $pvPowerAc !== null ? (float) $pvPowerAc : null,
            'pvPowerDc'    => $this->readVar('var_pv_power_dc'),
            'pvVoltage'    => $this->readVar('var_pv_voltage'),
            'pvCurrent'    => $this->readVar('var_pv_current'),
            'pvFrequency'  => $this->readVar('var_pv_frequency'),
            'pvEfficiency' => $this->readVar('var_pv_efficiency'),
            'pvTemperature' => $this->readVar('var_pv_temperature'),
            'pvEnergyToday' => $energyToday,
            'pvEnergyTotal' => $this->readVar('var_pv_energy_total'),
            'pvProducing'  => (bool) $this->readVar('var_pv_producing'),
            'pvReachable'  => (bool) $this->readVar('var_pv_reachable'),
            'pvOnline'     => (bool) $this->readVar('var_pv_online'),
            'pvLimit'      => $this->readVar('var_pv_limit_relative'),

            'meterEnergyTotal'  => $meterCur,
            'meterPowerCurrent' => $meterPowerCurrent !== null ? (float) $meterPowerCurrent : null,

            // Netzbezug: counterDelta() reads a true continuous counter, so
            // "previous period" is a direct archive lookup -- no rollover
            // detection needed (unlike the Heating Dashboard's meter).
            'meterUsageDay'      => $this->whToKwh($this->counterDelta($meterEnergyId, $todayStart, $now)),
            'meterUsageWeek'     => $this->whToKwh($this->counterDelta($meterEnergyId, $weekStart, $now)),
            'meterUsageMonth'    => $this->whToKwh($this->counterDelta($meterEnergyId, $monthStart, $now)),
            'meterUsageYear'     => $this->whToKwh($this->counterDelta($meterEnergyId, $yearStart, $now)),
            'meterUsagePrevDay'  => $this->whToKwh($this->counterDelta($meterEnergyId, $yesterdayStart, $todayStart)),
            'meterUsagePrevWeek' => $this->whToKwh($this->counterDelta($meterEnergyId, $lastWeekStart, $weekStart)),
            'meterUsagePrevMonth' => $this->whToKwh($this->counterDelta($meterEnergyId, $lastMonthStart, $monthStart)),
            'meterUsagePrevYear' => $this->whToKwh($this->counterDelta($meterEnergyId, $lastYearStart, $yearStart)),

            // Produktion: "Energie heute" resets daily, so periods are summed
            // from daily maxima instead of a simple counter delta.
            'prodDay'      => $this->whToKwh($prodDayCur),
            'prodWeek'     => $this->whToKwh($this->yieldForRange($energyTodayId, $weekStart, $now)),
            'prodMonth'    => $this->whToKwh($this->yieldForRange($energyTodayId, $monthStart, $now)),
            'prodYear'     => $this->whToKwh($this->yieldForRange($energyTodayId, $yearStart, $now)),
            'prodPrevDay'  => $this->whToKwh($this->yieldForRange($energyTodayId, $yesterdayStart, $todayStart)),
            'prodPrevWeek' => $this->whToKwh($this->yieldForRange($energyTodayId, $lastWeekStart, $weekStart)),
            'prodPrevMonth' => $this->whToKwh($this->yieldForRange($energyTodayId, $lastMonthStart, $monthStart)),
            'prodPrevYear' => $this->whToKwh($this->yieldForRange($energyTodayId, $lastYearStart, $yearStart)),

            'dailyYield' => $this->last7DaysYield($energyTodayId),

            'histPvPower'    => $this->archivedSeries($this->ReadPropertyInteger('var_pv_power_ac'), self::CHART_SPAN_SEC),
            'histMeterPower' => $meterPowerId > 0
                ? $this->archivedSeries($meterPowerId, self::CHART_SPAN_SEC)
                : $this->derivePowerSeries($meterEnergyId, self::CHART_SPAN_SEC),

            'updated' => date('d.m. H:i'),
        ];
    }

    // ─── Rendering ──────────────────────────────────────────────────────────────

    private function renderStatTile(string $id, string $label, string $value): string
    {
        return "<div class='cur-tile'><span class='cur-label'>{$label}</span><span id=\"{$id}\" class='cur-value'>{$value}</span></div>";
    }

    private function renderStatusBadge(string $id, string $label, ?bool $active): string
    {
        if ($active === null) {
            return '';
        }
        $cls  = $active ? 'badge-on' : 'badge-off';
        $text = htmlspecialchars($label . ($active ? ': an' : ': aus'), ENT_QUOTES);
        return "<span id=\"{$id}\" class=\"badge {$cls}\">{$text}</span>";
    }

    private function renderMiniChart(string $id, string $label, array $series, string $unit = ''): string
    {
        $chart  = $this->chartData($series);
        $maxStr = $chart['max'] !== null ? $this->fmtNum($chart['max'], $chart['max'] < 10 ? 1 : 0) . $unit : '';
        $minStr = $chart['min'] !== null ? $this->fmtNum($chart['min'], $chart['min'] < 10 ? 1 : 0) . $unit : '';
        return "<div class=\"chart-wrap\"><div class=\"chart-label\">{$label}</div>"
            . '<div class="chart-inner">'
            . "<svg id=\"{$id}_svg\" viewBox=\"0 0 300 60\" preserveAspectRatio=\"none\">"
            . "<polyline id=\"{$id}_poly\" points=\"{$chart['points']}\" fill=\"none\" stroke=\"#7ec8f0\" stroke-width=\"2\"/>"
            . '</svg>'
            . "<span id=\"{$id}_max\" class=\"chart-max\">{$maxStr}</span>"
            . "<span id=\"{$id}_min\" class=\"chart-min\">{$minStr}</span>"
            . '</div></div>';
    }

    /** PV power 6h chart with the 800W limit line and red/green segments -- see pvChartGeometry(). */
    private function renderPvPowerChart(string $id, string $label, array $series): string
    {
        $geo    = $this->pvChartGeometry($series);
        $maxStr = $geo['max'] !== null ? $this->fmtNum($geo['max'], 0) . ' W' : '';
        $minStr = $geo['min'] !== null ? $this->fmtNum($geo['min'], 0) . ' W' : '';

        $polylines = '';
        foreach ($geo['segments'] as $seg) {
            $stroke = $seg['color'] === 'red' ? '#e0575a' : '#5ecb82';
            $polylines .= "<polyline points=\"{$seg['points']}\" fill=\"none\" stroke=\"{$stroke}\" stroke-width=\"2\"/>";
        }
        $thresholdLine = $geo['thresholdY'] !== null
            ? "<line x1=\"0\" y1=\"{$geo['thresholdY']}\" x2=\"300\" y2=\"{$geo['thresholdY']}\" stroke=\"#ffb020\" stroke-width=\"1\" stroke-dasharray=\"4,3\"/>"
            : '';

        return "<div class=\"chart-wrap\"><div class=\"chart-label\">{$label}</div>"
            . '<div class="chart-inner">'
            . "<svg id=\"{$id}_svg\" viewBox=\"0 0 300 60\" preserveAspectRatio=\"none\">"
            . "{$thresholdLine}<g id=\"{$id}_lines\">{$polylines}</g>"
            . '</svg>'
            . "<span id=\"{$id}_max\" class=\"chart-max\">{$maxStr}</span>"
            . "<span id=\"{$id}_min\" class=\"chart-min\">{$minStr}</span>"
            . '<span class="chart-limit">800 W Grenze</span>'
            . '</div></div>';
    }

    private function renderDial(string $ident, string $label, ?float $value): string
    {
        [$min, $max, $step] = self::DIAL_RANGES[$ident];
        $val    = $value ?? $min;
        [$tx, $ty] = $this->dialThumbPos($val, $min, $max);
        $valStr = $value !== null ? $this->fmtNum($value, 0) . '%' : '–';
        $labelEsc = htmlspecialchars($label, ENT_QUOTES);

        return "<div class=\"dial\" data-ident=\"{$ident}\" data-min=\"{$min}\" data-max=\"{$max}\" data-step=\"{$step}\" data-value=\"{$val}\">"
            . '<svg class="dial-svg" viewBox="0 0 120 120">'
            . '<path class="dial-track" d="M 28.2 91.8 A 45 45 0 1 1 91.8 91.8" fill="none" stroke="#1e2d40" stroke-width="8" stroke-linecap="round"/>'
            . "<circle class=\"dial-thumb\" cx=\"{$tx}\" cy=\"{$ty}\" r=\"7\" fill=\"#7ec8f0\"/>"
            . '</svg>'
            . "<div class=\"dial-value\">{$valStr}</div>"
            . "<div class=\"dial-label\">{$labelEsc}</div>"
            . '</div>';
    }

    /** $periods: ['Tag' => ['v' => ?float, 'prev' => ?float], 'Woche' => [...], 'Monat' => [...], 'Jahr' => [...]]. */
    private function renderBarChart(string $label, array $periods): string
    {
        $vals     = array_filter(array_map(static fn (array $p) => $p['v'], $periods), static fn ($v) => $v !== null);
        $localMax = count($vals) > 0 ? max($vals) : 1.0;

        $bars = '';
        foreach ($periods as $xlabel => $p) {
            $v       = $p['v'];
            $prev    = $p['prev'];
            $h       = $v !== null ? max(2, (int) round(($v / $localMax) * 60)) : 2;
            $vStr    = $v !== null ? $this->fmtNum($v, 1) : '–';
            $prevStr = $prev !== null ? '(' . $this->fmtNum($prev, 1) . ')' : '';
            $bars .= "<div class='bar-col'><div class='bar-value'>{$vStr}</div><div class='bar-prev'>{$prevStr}</div>"
                . "<div class='bar-track'><div class='bar-fill-v' style='height:{$h}px'></div></div>"
                . "<div class='bar-xlabel'>{$xlabel}</div></div>";
        }

        $labelEsc = htmlspecialchars($label, ENT_QUOTES);
        return "<div class=\"chart-card\"><div class=\"chart-card-label\">{$labelEsc}</div>"
            . "<div class=\"bar-row\">{$bars}</div></div>";
    }

    /** $series: [{label, value}] -- N columns, no previous-period comparison. */
    private function renderDailyBarChart(string $label, array $series): string
    {
        $vals     = array_filter(array_map(static fn (array $p) => $p['value'], $series), static fn ($v) => $v !== null);
        $localMax = count($vals) > 0 ? max($vals) : 1.0;

        $bars = '';
        foreach ($series as $p) {
            $v        = $p['value'];
            $h        = $v !== null ? max(2, (int) round(($v / $localMax) * 60)) : 2;
            $vStr     = $v !== null ? $this->fmtNum($v, 1) : '–';
            $labelEsc = htmlspecialchars($p['label'], ENT_QUOTES);
            $bars .= "<div class='bar-col'><div class='bar-value'>{$vStr}</div>"
                . "<div class='bar-track'><div class='bar-fill-v' style='height:{$h}px'></div></div>"
                . "<div class='bar-xlabel'>{$labelEsc}</div></div>";
        }

        $labelEsc2 = htmlspecialchars($label, ENT_QUOTES);
        return "<div class=\"chart-card\"><div class=\"chart-card-label\">{$labelEsc2}</div>"
            . "<div class=\"bar-row\">{$bars}</div></div>";
    }

    private function buildDashboardHTML(): string
    {
        $d = $this->collectData();

        $acStr   = $d['pvPowerAc'] !== null ? $this->fmtNum($d['pvPowerAc'], 0) . ' W' : '–';
        $dcStr   = $d['pvPowerDc'] !== null ? $this->fmtNum((float) $d['pvPowerDc'], 0) . ' W' : '–';
        $vStr    = $d['pvVoltage'] !== null ? $this->fmtNum((float) $d['pvVoltage'], 1) . ' V' : '–';
        $aStr    = $d['pvCurrent'] !== null ? $this->fmtNum((float) $d['pvCurrent'], 1) . ' A' : '–';
        $hzStr   = $d['pvFrequency'] !== null ? $this->fmtNum((float) $d['pvFrequency'], 2) . ' Hz' : '–';
        $effStr  = $d['pvEfficiency'] !== null ? $this->fmtNum((float) $d['pvEfficiency'], 1) . ' %' : '–';
        $tempStr = $d['pvTemperature'] !== null ? $this->fmtNum((float) $d['pvTemperature'], 1) . ' °C' : '–';
        $totalStr = $d['pvEnergyTotal'] !== null ? $this->fmtNum((float) $d['pvEnergyTotal'], 2) . ' kWh' : '–';

        $meterTotalStr = $d['meterEnergyTotal'] !== null ? $this->fmtNum($d['meterEnergyTotal'] / 1000, 1) . ' kWh' : '–';
        $meterPowerStr = $d['meterPowerCurrent'] !== null ? $this->fmtNum($d['meterPowerCurrent'], 0) . ' W' : '–';

        $onlineCls  = $d['pvOnline'] ? 'badge-on' : 'badge-off';
        $onlineText = $d['pvOnline'] ? '📡 OpenDTU online' : '📡 OpenDTU offline';

        $producingBadge = $this->renderStatusBadge('producing_badge', '☀️ Produziert', $d['pvProducing']);
        $reachableBadge = $this->renderStatusBadge('reachable_badge', '📶 Erreichbar', $d['pvReachable']);

        // Solar-Leistung produziert / Leistung Netzbezug: paired stats + paired 6h charts.
        $powerStats = $this->renderStatTile('cur_ac', 'Solar-Leistung produziert', $acStr)
            . $this->renderStatTile('cur_meter_power', 'Leistung Netzbezug', $meterPowerStr);
        $pvChart    = $this->renderPvPowerChart('chart_pv', 'Solar-Leistung 6h', $d['histPvPower']);
        $meterChart = $this->renderMiniChart('chart_meter', 'Netzbezug 6h', $d['histMeterPower'], ' W');

        $pvTiles = $this->renderStatTile('cur_dc', 'Leistung DC', $dcStr)
            . $this->renderStatTile('cur_v', 'Spannung', $vStr)
            . $this->renderStatTile('cur_a', 'Strom', $aStr)
            . $this->renderStatTile('cur_hz', 'Frequenz', $hzStr)
            . $this->renderStatTile('cur_eff', 'Effizienz', $effStr)
            . $this->renderStatTile('cur_temp', 'WR-Temperatur', $tempStr);

        $pvDial = $this->renderDial('pv_limit', 'Leistungsbegrenzung', $d['pvLimit'] !== null ? (float) $d['pvLimit'] : null);
        $pvTotalTile = $this->renderStatTile('cur_total', 'Energie gesamt', $totalStr);

        $meterTile = $this->renderStatTile('cur_meter_total', 'Zählerstand gesamt', $meterTotalStr);

        $dailyYieldChart = $this->renderDailyBarChart('Energie heute – letzte 7 Tage (kWh)', $d['dailyYield']);

        $meterBars = $this->renderBarChart('Netzbezug (kWh)', [
            'Tag'   => ['v' => $d['meterUsageDay'],   'prev' => $d['meterUsagePrevDay']],
            'Woche' => ['v' => $d['meterUsageWeek'],  'prev' => $d['meterUsagePrevWeek']],
            'Monat' => ['v' => $d['meterUsageMonth'], 'prev' => $d['meterUsagePrevMonth']],
            'Jahr'  => ['v' => $d['meterUsageYear'],  'prev' => $d['meterUsagePrevYear']],
        ]);
        $prodBars = $this->renderBarChart('Solar-Produktion (kWh)', [
            'Tag'   => ['v' => $d['prodDay'],   'prev' => $d['prodPrevDay']],
            'Woche' => ['v' => $d['prodWeek'],  'prev' => $d['prodPrevWeek']],
            'Monat' => ['v' => $d['prodMonth'], 'prev' => $d['prodPrevMonth']],
            'Jahr'  => ['v' => $d['prodYear'],  'prev' => $d['prodPrevYear']],
        ]);

        $updatedEsc = htmlspecialchars($d['updated'], ENT_QUOTES);
        $initJson   = json_encode($d);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
html{height:100%}
*{box-sizing:border-box;margin:0;padding:0}
body{overflow-y:auto;overflow-x:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;background:#0d1b2a;color:#d0e8ff;display:flex;flex-direction:column;padding:10px;gap:10px}
.header{display:flex;justify-content:space-between;align-items:center;gap:6px;font-size:14px;font-weight:600;border-bottom:1px solid #1e3a5f;padding-bottom:6px;flex:none}
.updated{font-size:10px;color:#3a5a7a;font-weight:400}
.badge{padding:3px 8px;border-radius:12px;font-size:12px;border:1px solid transparent;white-space:nowrap}
.badge-off{background:#1a2535;border-color:#2a3a50;color:#4a6a8a}
.badge-on{background:#12405a;border-color:#2a7aa0;color:#7ec8f0}
.status-row{display:flex;gap:6px;flex-wrap:wrap;flex:none}
.section-label{font-size:11px;font-weight:700;color:#8aa8c8;text-transform:uppercase;letter-spacing:.04em;flex:none;border-bottom:1px solid #1e3a5f;padding-bottom:3px}
.current-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;flex:none}
.cur-tile{display:flex;flex-direction:column;gap:1px;background:#131f33;border-radius:8px;padding:6px 8px}
.cur-label{font-size:10px;color:#4a6a8a;text-transform:uppercase;letter-spacing:.03em}
.cur-value{font-size:15px;font-weight:700;color:#d0e8ff}
.chart-wrap{flex:none;display:flex;flex-direction:column;gap:2px}
.chart-label{font-size:9px;color:#4a6a8a;text-transform:uppercase;letter-spacing:.02em}
.chart-inner{position:relative}
.chart-inner svg{width:100%;height:40px;background:#131f33;border-radius:6px;display:block}
.chart-max,.chart-min{position:absolute;right:3px;font-size:8px;color:#7ec8f0;background:rgba(13,27,42,.7);padding:0 3px;border-radius:3px}
.chart-max{top:2px}
.chart-min{bottom:2px}
.chart-limit{position:absolute;left:3px;bottom:2px;font-size:8px;color:#ffb020;background:rgba(13,27,42,.7);padding:0 3px;border-radius:3px}
.pv-block{display:flex;flex-direction:column;gap:6px;flex:none;background:#0f1c30;border-radius:10px;padding:8px}
.pv-title{font-size:12px;font-weight:700;color:#d0e8ff}
.power-stats{display:flex;flex-direction:column;gap:8px}
.power-charts{display:flex;flex-direction:column;gap:8px}
.dial-row{display:flex;gap:12px;justify-content:center}
.dial{display:flex;flex-direction:column;align-items:center;gap:2px;width:100px;touch-action:none}
.dial-svg{width:80px;height:80px;cursor:pointer}
.dial-value{font-size:15px;font-weight:700;margin-top:-48px;pointer-events:none}
.dial-label{font-size:10px;color:#8aa8c8;margin-top:30px}
.chart-card{background:#131f33;border-radius:8px;padding:6px 8px;display:flex;flex-direction:column;gap:4px}
.chart-card-label{font-size:11px;font-weight:700;color:#8aa8c8}
.bar-row{display:flex;justify-content:space-around;align-items:flex-end}
.bar-col{display:flex;flex-direction:column;align-items:center;gap:2px}
.bar-value{font-size:10px;font-weight:700;color:#d0e8ff}
.bar-prev{font-size:8px;font-weight:400;color:#4a6a8a;min-height:10px}
.bar-track{width:22px;height:60px;display:flex;align-items:flex-end}
.bar-fill-v{width:100%;background:linear-gradient(180deg,#7ec8f0,#3a8abf);border-radius:3px 3px 0 0;transition:height .4s}
.bar-xlabel{font-size:9px;color:#4a6a8a}
.consumption-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;flex:none}
</style>
</head>
<body>
<div class="header">
  <span>⚡ Energie <span id="updated" class="updated">Stand {$updatedEsc}</span></span>
  <span id="online_badge" class="badge {$onlineCls}">{$onlineText}</span>
</div>

<div class="status-row">{$producingBadge}{$reachableBadge}</div>

<div class="pv-block">
  <div class="pv-title">⚖️ Leistungsbilanz</div>
  <div class="power-stats">{$powerStats}</div>
  <div class="power-charts">
    {$pvChart}
    {$meterChart}
  </div>
</div>

<div class="pv-block">
  <div class="pv-title">☀️ Solaranlage</div>
  <div class="current-grid">{$pvTiles}</div>
  <div class="current-grid">{$pvTotalTile}</div>
  <div class="dial-row">{$pvDial}</div>
</div>

{$dailyYieldChart}

<div class="pv-block">
  <div class="pv-title">🔌 Stromzähler</div>
  <div class="current-grid">{$meterTile}</div>
</div>

<div class="consumption-grid">
  {$meterBars}
  {$prodBars}
</div>

<script>
(function() {
  var cs = getComputedStyle(document.body);
  var vExtra = (parseFloat(cs.marginTop) || 0) + (parseFloat(cs.marginBottom) || 0);
  document.body.style.height = 'calc(100% - ' + vExtra + 'px)';
})();

var state = {$initJson};
var dialRoots = {};

function setText(id, text) {
  var el = document.getElementById(id);
  if (el) el.textContent = text;
}

function renderMiniChart(prefix, series, unit) {
  var poly = document.getElementById(prefix + '_poly');
  if (!poly || !series || series.length < 2) return;
  var spanSec = 6 * 3600;
  var startTs = Math.floor(Date.now() / 1000) - spanSec;
  var vals = series.map(function(p) { return p[1]; });
  var realMin = Math.min.apply(null, vals), realMax = Math.max.apply(null, vals);
  var min = realMin, max = realMax;
  if (max - min < 1.0) { var mid = (max + min) / 2; min = mid - 0.5; max = mid + 0.5; }
  var pad = (max - min) * 0.15; min -= pad; max += pad;
  var w = 300, h = 60;
  var pts = series.map(function(p) {
    var x = Math.max(0, Math.min(w, (p[0] - startTs) / spanSec * w));
    var y = h - ((p[1] - min) / (max - min)) * h;
    return x.toFixed(1) + ',' + y.toFixed(1);
  }).join(' ');
  poly.setAttribute('points', pts);
  function fmt(v) { return (v < 10 ? v.toFixed(1) : Math.round(v)).toString().replace('.', ',') + unit; }
  setText(prefix + '_max', fmt(realMax));
  setText(prefix + '_min', fmt(realMin));
}

// Mirrors PHP pvChartGeometry(): 800W-scaled, red/green segments with the crossing point interpolated.
function renderPvChart(prefix, series) {
  var g = document.getElementById(prefix + '_lines');
  if (!g || !series || series.length < 2) return;
  var LIMIT = 800;
  var spanSec = 6 * 3600;
  var startTs = Math.floor(Date.now() / 1000) - spanSec;
  var vals = series.map(function(p) { return p[1]; });
  var realMin = Math.min.apply(null, vals), realMax = Math.max.apply(null, vals);
  var scaleMax = Math.max(realMax, LIMIT) * 1.1;
  if (scaleMax <= 0) scaleMax = LIMIT * 1.1;
  var scaleMin = 0;

  function toXY(ts, val) {
    var x = Math.max(0, Math.min(300, (ts - startTs) / spanSec * 300));
    var y = 60 - ((val - scaleMin) / (scaleMax - scaleMin)) * 60;
    return [x, y];
  }

  var segments = [];
  var current = null;
  for (var i = 0; i < series.length; i++) {
    var ts = series[i][0], val = series[i][1];
    var xy = toXY(ts, val);
    var color = val > LIMIT ? 'red' : 'green';
    if (current === null) {
      current = { color: color, pts: [xy] };
      continue;
    }
    if (color !== current.color) {
      var prevTs = series[i - 1][0], prevVal = series[i - 1][1];
      var denom = val - prevVal;
      if (Math.abs(denom) > 1e-9) {
        var frac = Math.max(0, Math.min(1, (LIMIT - prevVal) / denom));
        var crossTs = prevTs + frac * (ts - prevTs);
        var cxy = toXY(crossTs, LIMIT);
        current.pts.push(cxy);
        segments.push(current);
        current = { color: color, pts: [cxy, xy] };
      } else {
        segments.push(current);
        current = { color: color, pts: [xy] };
      }
    } else {
      current.pts.push(xy);
    }
  }
  if (current) segments.push(current);

  var html = '';
  segments.forEach(function(seg) {
    var stroke = seg.color === 'red' ? '#e0575a' : '#5ecb82';
    var pts = seg.pts.map(function(p) { return p[0].toFixed(1) + ',' + p[1].toFixed(1); }).join(' ');
    html += '<polyline points="' + pts + '" fill="none" stroke="' + stroke + '" stroke-width="2"/>';
  });
  g.innerHTML = html;

  setText(prefix + '_max', Math.round(realMax) + ' W');
  setText(prefix + '_min', Math.round(realMin) + ' W');
}

function valueToThumb(value, min, max) {
  var frac = max > min ? Math.max(0, Math.min(1, (value - min) / (max - min))) : 0;
  var angleDeg = -135 + frac * 270;
  var rad = angleDeg * Math.PI / 180;
  return { x: 60 + 45 * Math.sin(rad), y: 60 - 45 * Math.cos(rad) };
}

function initDial(root) {
  var svg = root.querySelector('.dial-svg');
  var thumb = root.querySelector('.dial-thumb');
  var valueEl = root.querySelector('.dial-value');
  var min = parseFloat(root.dataset.min);
  var max = parseFloat(root.dataset.max);
  var step = parseFloat(root.dataset.step);
  var ident = root.dataset.ident;
  var dragging = false;
  var currentValue = parseFloat(root.dataset.value);

  function svgPoint(clientX, clientY) {
    var pt = svg.createSVGPoint();
    pt.x = clientX; pt.y = clientY;
    var ctm = svg.getScreenCTM();
    if (!ctm) return { x: 60, y: 60 };
    return pt.matrixTransform(ctm.inverse());
  }

  function updateVisual(value) {
    var pos = valueToThumb(value, min, max);
    thumb.setAttribute('cx', pos.x.toFixed(1));
    thumb.setAttribute('cy', pos.y.toFixed(1));
    if (valueEl) valueEl.textContent = Math.round(value) + '%';
  }

  function setFromClient(clientX, clientY) {
    var p = svgPoint(clientX, clientY);
    var dx = p.x - 60, dy = p.y - 60;
    var angleDeg = Math.atan2(dx, -dy) * 180 / Math.PI;
    angleDeg = Math.max(-135, Math.min(135, angleDeg));
    var frac = (angleDeg + 135) / 270;
    var value = min + frac * (max - min);
    value = Math.round(value / step) * step;
    currentValue = value;
    updateVisual(value);
  }

  svg.addEventListener('pointerdown', function(e) {
    dragging = true;
    svg.setPointerCapture(e.pointerId);
    setFromClient(e.clientX, e.clientY);
  });
  svg.addEventListener('pointermove', function(e) {
    if (dragging) setFromClient(e.clientX, e.clientY);
  });
  function endDrag() {
    if (!dragging) return;
    dragging = false;
    requestAction(ident, currentValue);
  }
  svg.addEventListener('pointerup', endDrag);
  svg.addEventListener('pointercancel', endDrag);

  root._updateVisual = updateVisual;
  dialRoots[ident] = root;
}
document.querySelectorAll('.dial').forEach(initDial);

window.handleMessage = function(raw) {
  var msg = JSON.parse(raw);
  var key = msg.key, val = msg.value;

  if (key === '__all__') {
    state = val;
    setText('updated', 'Stand ' + val.updated);
    setText('cur_ac', val.pvPowerAc == null ? '–' : Math.round(val.pvPowerAc) + ' W');
    setText('cur_dc', val.pvPowerDc == null ? '–' : Math.round(val.pvPowerDc) + ' W');
    setText('cur_v', val.pvVoltage == null ? '–' : val.pvVoltage.toFixed(1).replace('.', ',') + ' V');
    setText('cur_a', val.pvCurrent == null ? '–' : val.pvCurrent.toFixed(1).replace('.', ',') + ' A');
    setText('cur_hz', val.pvFrequency == null ? '–' : val.pvFrequency.toFixed(2).replace('.', ',') + ' Hz');
    setText('cur_eff', val.pvEfficiency == null ? '–' : val.pvEfficiency.toFixed(1).replace('.', ',') + ' %');
    setText('cur_temp', val.pvTemperature == null ? '–' : val.pvTemperature.toFixed(1).replace('.', ',') + ' °C');
    setText('cur_total', val.pvEnergyTotal == null ? '–' : val.pvEnergyTotal.toFixed(2).replace('.', ',') + ' kWh');
    setText('cur_meter_total', val.meterEnergyTotal == null ? '–' : (val.meterEnergyTotal / 1000).toFixed(1).replace('.', ',') + ' kWh');
    setText('cur_meter_power', val.meterPowerCurrent == null ? '–' : Math.round(val.meterPowerCurrent) + ' W');

    var onlineBadge = document.getElementById('online_badge');
    if (onlineBadge) {
      onlineBadge.className = 'badge ' + (val.pvOnline ? 'badge-on' : 'badge-off');
      onlineBadge.textContent = val.pvOnline ? '📡 OpenDTU online' : '📡 OpenDTU offline';
    }
    var producingBadge = document.getElementById('producing_badge');
    if (producingBadge && val.pvProducing != null) {
      producingBadge.className = 'badge ' + (val.pvProducing ? 'badge-on' : 'badge-off');
      producingBadge.textContent = '☀️ Produziert: ' + (val.pvProducing ? 'an' : 'aus');
    }
    var reachableBadge = document.getElementById('reachable_badge');
    if (reachableBadge && val.pvReachable != null) {
      reachableBadge.className = 'badge ' + (val.pvReachable ? 'badge-on' : 'badge-off');
      reachableBadge.textContent = '📶 Erreichbar: ' + (val.pvReachable ? 'an' : 'aus');
    }

    renderPvChart('chart_pv', val.histPvPower);
    renderMiniChart('chart_meter', val.histMeterPower, ' W');

    if (val.pvLimit != null && dialRoots['pv_limit']) dialRoots['pv_limit']._updateVisual(val.pvLimit);
  } else if (dialRoots[key]) {
    dialRoots[key]._updateVisual(val);
  }
};
</script>
</body>
</html>
HTML;
    }
}
