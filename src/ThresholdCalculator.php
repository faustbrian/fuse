<?php

namespace Cline\Fuse;

/**
 * Resolves service thresholds for the current time window.
 *
 * Fuse supports relaxed or stricter thresholds during configured peak hours.
 * This helper centralizes that decision so the runtime breaker and operator
 * tooling report the same threshold values for a given service.
 */
class ThresholdCalculator
{
    /**
     * Resolve the active failure threshold for the service right now.
     *
     * If the service is not configured, Fuse falls back to the package-wide
     * default threshold. Configured services may define a separate threshold
     * for peak hours to make the breaker either more tolerant or more strict
     * during expected traffic spikes.
     */
    public static function for(string $service): int
    {
        $config = config("fuse.services.{$service}");

        if (! $config) {
            return config('fuse.default_threshold', 50);
        }

        $hour = now()->hour;

        $peakStart = $config['peak_hours_start'] ?? 9;
        $peakEnd = $config['peak_hours_end'] ?? 17;
        $isPeakHours = self::isPeakHour($hour, $peakStart, $peakEnd);

        return $isPeakHours
            ? ($config['peak_hours_threshold'] ?? $config['threshold'] ?? 60)
            : ($config['threshold'] ?? 50);
    }

    /**
     * Get the service configuration currently relevant to breaker decisions.
     *
     * This mirrors the values an operator would care about when checking why a
     * service tripped or when it will allow recovery probes again.
     *
     * @return array{threshold: int, timeout: int, min_requests: int, is_peak_hours: bool}
     */
    public static function getConfig(string $service): array
    {
        $config = config("fuse.services.{$service}", []);
        $hour = now()->hour;

        $peakStart = $config['peak_hours_start'] ?? 9;
        $peakEnd = $config['peak_hours_end'] ?? 17;
        $isPeakHours = self::isPeakHour($hour, $peakStart, $peakEnd);

        return [
            'threshold' => self::for($service),
            'timeout' => $config['timeout'] ?? config('fuse.default_timeout', 60),
            'min_requests' => $config['min_requests'] ?? config('fuse.default_min_requests', 10),
            'is_peak_hours' => $isPeakHours,
        ];
    }

    /**
     * Determine whether the current hour falls inside the configured peak window.
     *
     * Peak ranges may stay within one day, or wrap across midnight such as
     * `22 -> 06`.
     */
    private static function isPeakHour(int $hour, int $peakStart, int $peakEnd): bool
    {
        if ($peakStart <= $peakEnd) {
            return $hour >= $peakStart && $hour <= $peakEnd;
        }

        return $hour >= $peakStart || $hour <= $peakEnd;
    }
}
