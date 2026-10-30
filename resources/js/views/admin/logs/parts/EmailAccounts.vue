<script setup lang="ts">
/** Sending accounts of the outgoing mail pool: availability, volumes, limits and the last problem. Never logins or passwords. */
import { formatDateTime, humanize } from '@/utils/format';

export interface MailAccountStat {
    name: string; label: string; primary: boolean; enabled: boolean; active: boolean;
    from_address: string | null; from_name: string | null; host: string | null; port: number | null; encryption: string | null;
    per_minute: number | null; daily_limit: number | null; problems: string[]; warnings: string[];
    availability: 'available' | 'unavailable' | 'rate_limited' | 'misconfigured' | 'disabled' | 'not_in_use';
    unavailable_reason: string | null; available_at: string | null;
    sent_24h: number; failed_24h: number; sent_total: number; failed_total: number; usage_percent: number | null; near_limit: boolean;
    consecutive_failures: number; last_success_at: string | null; last_failure_at: string | null; last_error: string | null;
}

defineProps<{ accounts: MailAccountStat[]; enabled: boolean; strategy: string }>();

const AVAILABILITY: Record<string, { cls: string; icon: string; label: string }> = {
    available: { cls: 'text-bg-success', icon: 'bi-check-circle', label: 'Available' },
    unavailable: { cls: 'text-bg-danger', icon: 'bi-pause-circle', label: 'Resting' },
    rate_limited: { cls: 'text-bg-warning', icon: 'bi-speedometer2', label: 'Rate limited' },
    misconfigured: { cls: 'text-bg-danger', icon: 'bi-exclamation-octagon', label: 'Misconfigured' },
    disabled: { cls: 'text-bg-secondary', icon: 'bi-slash-circle', label: 'Disabled' },
    not_in_use: { cls: 'text-bg-light border', icon: 'bi-dash-circle', label: 'Not in use' },
};
const REASONS: Record<string, string> = {
    connection: 'Server unreachable', ambiguous: 'Connection dropped after sending', rate_limited: 'Throttled by the server',
    quota: 'Sending quota reached', domain_rate_limited: 'Domain rate limit', auth: 'Login rejected', config: 'TLS or configuration problem',
    sender_rejected: 'From address refused', per_minute_limit: 'Per-minute limit', daily_limit: 'Daily limit reached',
};
const STRATEGIES: Record<string, string> = { single: 'single account', round_robin: 'round robin', failover: 'failover' };
const ENCRYPTION: Record<string, string> = { tls: 'STARTTLS required', ssl: 'TLS', none: 'unencrypted', auto: 'STARTTLS if offered' };
const badge = (a: MailAccountStat) => AVAILABILITY[a.availability] ?? { cls: 'text-bg-secondary', icon: 'bi-question-circle', label: humanize(a.availability) };
</script>

<template>
    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="bi bi-hdd-network me-1"></i>Sending accounts</span>
            <span class="small text-muted">{{ enabled ? `Pool on · ${STRATEGIES[strategy] ?? humanize(strategy)}` : 'Pool off · all email uses the primary account' }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-cards align-middle mb-0">
                <thead class="table-light"><tr><th>Account</th><th>Status</th><th class="text-end">Sent (24 h)</th><th class="text-end">Failed (24 h)</th><th class="text-end">All time sent / failed</th><th>Last problem</th></tr></thead>
                <tbody>
                    <tr v-for="a in accounts" :key="a.name">
                        <td>
                            <div class="fw-semibold">{{ a.label }}<span v-if="a.primary" class="badge text-bg-light border ms-1">Primary</span></div>
                            <div class="small text-muted text-break">{{ a.from_address ?? '—' }}</div>
                            <div v-if="a.host" class="small text-muted">{{ a.host }}:{{ a.port }} · {{ ENCRYPTION[a.encryption ?? ''] ?? a.encryption }}</div>
                        </td>
                        <td>
                            <span class="badge" :class="badge(a).cls"><i class="bi me-1" :class="badge(a).icon"></i>{{ badge(a).label }}</span>
                            <div v-if="a.unavailable_reason" class="small text-muted">
                                {{ REASONS[a.unavailable_reason] ?? humanize(a.unavailable_reason) }}<template v-if="a.available_at"> · until {{ formatDateTime(a.available_at) }}</template>
                            </div>
                            <div v-for="p in a.problems" :key="p" class="small text-danger">{{ p }}</div>
                            <div v-for="w in a.warnings" :key="w" class="small text-muted"><i class="bi bi-info-circle me-1"></i>{{ w }}</div>
                        </td>
                        <td class="text-end">
                            {{ a.sent_24h }}<template v-if="a.daily_limit"> / {{ a.daily_limit }}</template>
                            <div v-if="a.usage_percent !== null" class="small" :class="a.near_limit ? 'text-danger fw-semibold' : 'text-muted'">{{ a.usage_percent }}% of daily limit</div>
                        </td>
                        <td class="text-end" :class="{ 'text-danger fw-semibold': a.failed_24h > 0 }">{{ a.failed_24h }}</td>
                        <td class="text-end small text-muted">{{ a.sent_total }} / {{ a.failed_total }}</td>
                        <td class="small">
                            <template v-if="a.last_error">
                                <div class="text-break">{{ a.last_error }}</div>
                                <div class="text-muted">{{ formatDateTime(a.last_failure_at) }}</div>
                            </template>
                            <span v-else class="text-muted">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">
            <i class="bi bi-info-circle me-1"></i>“Sent” means the account's mail server accepted the message. It does not confirm that the message reached the recipient's inbox.
        </div>
    </div>
</template>
