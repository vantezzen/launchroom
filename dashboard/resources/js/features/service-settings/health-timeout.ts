/**
 * DeploySettings::healthTimeout() without an explicit setting, recomputed
 * while the form changes. tests/Fixtures/health-timeout-vectors.json keeps
 * both equal.
 */
export function autoHealthTimeout(health: {
    startPeriod: number;
    interval: number;
    timeout: number;
    failures: number;
}): number {
    return Math.max(
        90,
        health.startPeriod +
            (health.interval + health.timeout) * health.failures +
            60,
    );
}
