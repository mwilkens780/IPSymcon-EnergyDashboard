<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/TileKit.php';

class EnergyDashboard extends IPSModule
{
    use \Fuchsbau\TileKit\EnergyDashboard\TileKit;

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
        // Archive Control instance used to read power/consumption history
        // (normally only one per system; selected in the config form).
        $this->RegisterPropertyInteger('archive_instance', 0);

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
            $this->tkPush('__all__', $this->ops($this->view($this->collectData())));
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
                    $this->tkPush($Ident, (float) $Value); // optimistic UI update
                }
            } catch (\Throwable $e) {
                $this->LogMessage('EnergyDashboard RequestAction ' . $Ident . ': ' . $e->getMessage(), KL_ERROR);
            }
        } else {
            $this->LogMessage("EnergyDashboard RequestAction: unknown ident {$Ident}", KL_WARNING);
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

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

    // ─── Rendering (TileKit, siehe docs/ui-kachel.md im Workspace) ─────────────
    //
    // view() liefert alle Kacheln inkl. fertig gerenderter Diagramme; daraus
    // entstehen Erstrender und Live-Push (ops). Die Diagrammlogik existiert
    // damit nur noch in PHP (frueher doppelt in PHP und JS).

    private function num($v, int $dec, string $unit): string
    {
        return $v === null ? '–' : $this->fmtNum((float) $v, $dec) . $unit;
    }

    private function view(array $d): array
    {
        $bool = static fn (bool $on, string $onText, string $offText, string $onIcon, string $offIcon, string $offState = '') =>
            ['text' => $on ? $onText : $offText, 'icon' => $on ? $onIcon : $offIcon, 'state' => $on ? 'ok' : $offState];
        $periods = fn (string $p) => [
            'Tag'   => ['v' => $d[$p . 'Day'],   'prev' => $d[$p . 'PrevDay']],
            'Woche' => ['v' => $d[$p . 'Week'],  'prev' => $d[$p . 'PrevWeek']],
            'Monat' => ['v' => $d[$p . 'Month'], 'prev' => $d[$p . 'PrevMonth']],
            'Jahr'  => ['v' => $d[$p . 'Year'],  'prev' => $d[$p . 'PrevYear']],
        ];
        return [
            'updated'   => $d['updated'],
            'online'    => $bool($d['pvOnline'], 'Online', 'Offline', '🟢', '🔴', 'alarm'),
            'producing' => $bool($d['pvProducing'], 'Produziert', 'Ruht', '☀️', '🌙'),
            'reachable' => $bool($d['pvReachable'], 'Erreichbar', 'Nicht erreichbar', '📶', '⚠️', 'warn'),
            'ac'        => $this->num($d['pvPowerAc'], 0, ' W'),
            'meter'     => $this->num($d['meterPowerCurrent'], 0, ' W'),
            'pvChart'   => $this->pvChartHtml($d['histPvPower']),
            'meterChart' => $this->lineChartHtml($d['histMeterPower'], ' W'),
            'dc'        => $this->num($d['pvPowerDc'], 0, ' W'),
            'volt'      => $this->num($d['pvVoltage'], 1, ' V'),
            'amp'       => $this->num($d['pvCurrent'], 1, ' A'),
            'hz'        => $this->num($d['pvFrequency'], 2, ' Hz'),
            'eff'       => $this->num($d['pvEfficiency'], 1, ' %'),
            'temp'      => $this->num($d['pvTemperature'], 1, ' °C'),
            'total'     => $this->num($d['pvEnergyTotal'], 2, ' kWh'),
            'meterTotal' => $d['meterEnergyTotal'] !== null ? $this->fmtNum($d['meterEnergyTotal'] / 1000, 1) . ' kWh' : '–',
            'limit'     => $d['pvLimit'] !== null ? (float) $d['pvLimit'] : null,
            'daily'     => $this->dailyBarsHtml($d['dailyYield']),
            'prodBars'  => $this->periodBarsHtml($periods('prod')),
            'meterBars' => $this->periodBarsHtml($periods('meterUsage')),
        ];
    }

    private function ops(array $v): array
    {
        $ops = [];
        foreach (['online', 'producing', 'reachable'] as $k) {
            array_push($ops, ['t', $k . '_val', $v[$k]['text']], ['t', $k . '_icon', $v[$k]['icon']], ['s', $k, $v[$k]['state']]);
        }
        foreach (['ac', 'meter', 'dc', 'volt', 'amp', 'hz', 'eff', 'temp', 'total', 'meterTotal'] as $k) {
            $ops[] = ['t', $k . '_val', $v[$k]];
        }
        foreach (['pvChart', 'meterChart', 'daily', 'prodBars', 'meterBars'] as $k) {
            $ops[] = ['h', $k, $v[$k]];
        }
        if ($v['limit'] !== null) {
            $ops[] = ['d', 'pv_limit', $v['limit']];
        }
        return ['updated' => $v['updated'], 'ops' => $ops];
    }

    /** Solarleistung 6h mit 800-W-Grenze, rot ueber / gruen unter der Grenze (Geometrie: pvChartGeometry). */
    private function pvChartHtml(array $series): string
    {
        $geo = $this->pvChartGeometry($series);
        if ($geo['segments'] === []) {
            return '<span class="tk-hint">Keine Archivdaten</span>';
        }
        $lines = '';
        foreach ($geo['segments'] as $seg) {
            $stroke = $seg['color'] === 'red' ? '#e0575a' : '#5ecb82';
            $lines .= "<polyline points=\"{$seg['points']}\" fill=\"none\" stroke=\"{$stroke}\" stroke-width=\"2\" vector-effect=\"non-scaling-stroke\"/>";
        }
        return '<svg viewBox="0 0 300 60" preserveAspectRatio="none">'
            . "<line x1=\"0\" y1=\"{$geo['thresholdY']}\" x2=\"300\" y2=\"{$geo['thresholdY']}\" stroke=\"#ffb020\" stroke-width=\"1\" stroke-dasharray=\"4,3\" vector-effect=\"non-scaling-stroke\"/>{$lines}</svg>"
            . '<span class="ch-max">' . $this->fmtNum($geo['max'], 0) . ' W</span><span class="ch-min">' . $this->fmtNum($geo['min'], 0) . ' W</span>'
            . '<span class="ch-limit">800 W Grenze</span>';
    }

    private function lineChartHtml(array $series, string $unit): string
    {
        $c = $this->chartData($series);
        if ($c['points'] === '') {
            return '<span class="tk-hint">Keine Archivdaten</span>';
        }
        $f = fn (float $v) => $this->fmtNum($v, $v < 10 ? 1 : 0) . $unit;
        return "<svg viewBox=\"0 0 300 60\" preserveAspectRatio=\"none\"><polyline points=\"{$c['points']}\" fill=\"none\" stroke=\"#7ec8f0\" stroke-width=\"2\" vector-effect=\"non-scaling-stroke\"/></svg>"
            . '<span class="ch-max">' . $f($c['max']) . '</span><span class="ch-min">' . $f($c['min']) . '</span>';
    }

    /** Balken je Zeitraum, darunter der Vorperioden-Wert in Klammern. */
    private function periodBarsHtml(array $periods): string
    {
        $vals = array_filter(array_column($periods, 'v'), static fn ($v) => $v !== null);
        $max  = count($vals) > 0 ? max(max($vals), 0.001) : 1.0;
        $html = '';
        foreach ($periods as $label => $p) {
            $h     = $p['v'] !== null ? max(2, (int) round($p['v'] / $max * 56)) : 2;
            $prev  = $p['prev'] !== null ? '(' . $this->fmtNum($p['prev'], 1) . ')' : '';
            $html .= '<div class="bar"><span class="bar-v">' . $this->num($p['v'], 1, '') . "</span><span class=\"tk-sub\">{$prev}</span>"
                . "<div class=\"bar-track\"><div class=\"bar-fill\" style=\"height:{$h}px\"></div></div><span class=\"tk-sub\">{$label}</span></div>";
        }
        return $html;
    }

    private function dailyBarsHtml(array $series): string
    {
        $vals = array_filter(array_column($series, 'value'), static fn ($v) => $v !== null);
        $max  = count($vals) > 0 ? max(max($vals), 0.001) : 1.0;
        $html = '';
        foreach ($series as $p) {
            $h     = $p['value'] !== null ? max(2, (int) round($p['value'] / $max * 56)) : 2;
            $html .= '<div class="bar"><span class="bar-v">' . $this->num($p['value'], 1, '') . '</span>'
                . "<div class=\"bar-track\"><div class=\"bar-fill\" style=\"height:{$h}px\"></div></div><span class=\"tk-sub\">" . self::tkEsc($p['label']) . '</span></div>';
        }
        return $html;
    }

    private function renderLimitDial(?float $value): string
    {
        [$min, $max, $step] = self::DIAL_RANGES['pv_limit'];
        $val       = $value ?? $min;
        [$tx, $ty] = $this->dialThumbPos($val, $min, $max);
        $valStr    = $value !== null ? $this->fmtNum($value, 0) . ' %' : '–';
        return "<div class=\"dial\" data-ident=\"pv_limit\" data-min=\"{$min}\" data-max=\"{$max}\" data-step=\"{$step}\" data-value=\"{$val}\">"
            . '<svg class="dial-svg" viewBox="0 0 120 120"><path d="M 28.2 91.8 A 45 45 0 1 1 91.8 91.8" fill="none" stroke="#1e2d40" stroke-width="8" stroke-linecap="round"/>'
            . "<circle class=\"dial-thumb\" cx=\"{$tx}\" cy=\"{$ty}\" r=\"7\" fill=\"#7ec8f0\"/></svg>"
            . "<div class=\"dial-center\"><span class=\"dial-value\">{$valStr}</span></div></div>";
    }

    private function chartTile(string $id, string $name, string $valueId, string $value, string $chartHtml): string
    {
        return "<div class=\"tk-tile tk-span2 chart-tile\" id=\"{$valueId}_card\"><span class=\"tk-name\">" . self::tkEsc($name) . '</span>'
            . "<span id=\"{$valueId}_val\" class=\"tk-value\">" . self::tkEsc($value) . "</span><div class=\"chart\" id=\"{$id}\">{$chartHtml}</div><div class=\"ch-x\"><span>-6h</span><span>-3h</span><span>jetzt</span></div></div>";
    }

    private function barTile(string $id, string $name, string $html, string $cls = 'tk-span2'): string
    {
        return "<div class=\"tk-tile {$cls} bars-tile\"><span class=\"tk-name\">" . self::tkEsc($name) . "</span><div class=\"bars\" id=\"{$id}\">{$html}</div></div>";
    }

    private function buildDashboardHTML(): string
    {
        $v = $this->view($this->collectData());
        $s = fn (string $k, string $name) => self::tkStatusTile($k, $name, $v[$k]['text'], $v[$k]['icon'], $v[$k]['state']);
        $t = fn (string $k, string $name) => self::tkValueTile($k, $name, $v[$k]);

        $body = self::tkGroup('Leistung')
            . $this->chartTile('pvChart', 'Solar-Leistung (6 h)', 'ac', $v['ac'], $v['pvChart'])
            . $this->chartTile('meterChart', 'Netzbezug (6 h)', 'meter', $v['meter'], $v['meterChart'])
            . $s('online', 'OpenDTU') . $s('producing', 'Wechselrichter') . $s('reachable', 'Verbindung')
            . self::tkGroup('Solaranlage')
            . $t('dc', 'Leistung DC') . $t('volt', 'Spannung') . $t('amp', 'Strom') . $t('hz', 'Frequenz')
            . $t('eff', 'Effizienz') . $t('temp', 'WR-Temperatur') . $t('total', 'Energie gesamt')
            . '<div class="tk-tile limit-tile"><span class="tk-name">Leistungsbegrenzung</span>' . $this->renderLimitDial($v['limit']) . '</div>'
            . self::tkGroup('Ertrag & Verbrauch')
            . $this->barTile('daily', 'Solar – letzte 7 Tage (kWh)', $v['daily'], 'tk-span2 wide-bars')
            . $this->barTile('prodBars', 'Solar-Produktion (kWh, Vorperiode)', $v['prodBars'])
            . $this->barTile('meterBars', 'Netzbezug (kWh, Vorperiode)', $v['meterBars'])
            . $t('meterTotal', 'Zählerstand');

        $css = <<<'CSS'
.chart-tile{justify-content:flex-start}
.chart{position:relative;margin-top:2px;min-height:46px}
.chart svg{width:100%;height:46px;display:block}
.ch-max,.ch-min,.ch-limit{position:absolute;font-size:var(--tk-fs-meta);background:rgba(13,27,42,.75);padding:0 3px;border-radius:3px}
.ch-max{right:2px;top:0;color:var(--tk-accent)}.ch-min{right:2px;bottom:0;color:var(--tk-accent)}.ch-limit{left:2px;bottom:0;color:#ffb020}
.ch-x{display:flex;justify-content:space-between;font-size:var(--tk-fs-meta);color:var(--tk-dim)}
.limit-tile{align-items:center}
.dial{position:relative;width:96px;height:96px;touch-action:none}
.dial-svg{width:96px;height:96px;display:block;cursor:pointer}
.dial-center{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none}
.dial-value{font-size:16px;font-weight:700}
.bars-tile{justify-content:flex-start}
.bars{display:flex;justify-content:space-around;align-items:flex-end;gap:4px;margin-top:4px}
.bar{display:flex;flex-direction:column;align-items:center;gap:1px;min-width:0}
.bar-v{font-size:var(--tk-fs-label);font-weight:700}
.bar-track{width:22px;height:56px;display:flex;align-items:flex-end}
.bar-fill{width:100%;background:linear-gradient(180deg,#7ec8f0,#3a8abf);border-radius:3px 3px 0 0;transition:height .4s}
CSS;
        $js = <<<'JS'
// Leistungsbegrenzung (Drehregler, schreibt beim Loslassen)
var dial = (function (root) {
  if (!root) return null;
  var svg = root.querySelector('.dial-svg'), thumb = root.querySelector('.dial-thumb'), val = root.querySelector('.dial-value');
  var min = +root.dataset.min, max = +root.dataset.max, step = +root.dataset.step, cur = +root.dataset.value, drag = false;
  function show(v) {
    var f = max > min ? Math.max(0, Math.min(1, (v - min) / (max - min))) : 0, r = (-135 + f * 270) * Math.PI / 180;
    thumb.setAttribute('cx', (60 + 45 * Math.sin(r)).toFixed(1)); thumb.setAttribute('cy', (60 - 45 * Math.cos(r)).toFixed(1));
    val.textContent = Math.round(v) + ' %';
  }
  function at(e) {
    var pt = svg.createSVGPoint(); pt.x = e.clientX; pt.y = e.clientY; var m = svg.getScreenCTM(); if (!m) return;
    var p = pt.matrixTransform(m.inverse()), a = Math.max(-135, Math.min(135, Math.atan2(p.x - 60, 60 - p.y) * 180 / Math.PI));
    cur = Math.round((min + (a + 135) / 270 * (max - min)) / step) * step; show(cur);
  }
  svg.addEventListener('pointerdown', function (e) { drag = true; svg.setPointerCapture(e.pointerId); at(e); });
  svg.addEventListener('pointermove', function (e) { if (drag) at(e); });
  function end() { if (drag) { drag = false; requestAction('pv_limit', cur); } }
  svg.addEventListener('pointerup', end); svg.addEventListener('pointercancel', end);
  return { show: show, busy: function () { return drag; } };
})(document.querySelector('.dial'));

var STATES = ['ok', 'warn', 'alarm'], lastHtml = {};
tk.on('__all__', function (p) {
  p.ops.forEach(function (o) {
    var k = o[0], id = o[1], v = o[2];
    if (k === 't') tk.setText(id, v);
    else if (k === 'h') { if (lastHtml[id] !== v) { lastHtml[id] = v; tk.setHtml(id, v); } }
    else if (k === 'd') { if (dial && !dial.busy()) dial.show(+v); }
    else if (k === 's') {
      var card = tk.el(id + '_card'), st = tk.el(id + '_status');
      if (card) { card.classList.toggle('tk-is-alarm', v === 'alarm'); card.classList.toggle('tk-is-warn', v === 'warn'); }
      if (st) STATES.forEach(function (s) { st.classList.toggle('tk-' + s, s === v); });
    }
  });
});
tk.on('pv_limit', function (v) { if (dial && !dial.busy()) dial.show(+v); });
JS;
        return $this->tkPage('Energie', '⚡', $v['updated'], "<div class=\"tk-grid\">{$body}</div>", $css, $js, 'null');
    }
}
