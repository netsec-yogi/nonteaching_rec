<script setup lang="ts">
/** Compact field-by-field diff of an audit entry's old / new values, plus its meta. */
import { computed } from 'vue';

const props = defineProps<{ oldValues: Record<string, unknown> | null; newValues: Record<string, unknown> | null; meta?: Record<string, unknown> | null }>();

const show = (v: unknown): string => {
    if (v === undefined) return '';
    if (v === null) return 'null';
    if (typeof v === 'object') return JSON.stringify(v);
    return String(v);
};

const rows = computed(() => {
    const o = props.oldValues ?? {};
    const n = props.newValues ?? {};
    const keys = [...new Set([...Object.keys(o), ...Object.keys(n)])];
    return keys.map((k) => ({ key: k, old: show(o[k]), new: show(n[k]), changed: show(o[k]) !== show(n[k]) }));
});
const metaRows = computed(() => Object.entries(props.meta ?? {}).map(([k, v]) => ({ key: k, value: show(v) })));
</script>

<template>
    <div class="small">
        <table v-if="rows.length" class="table table-sm table-bordered mb-2 bg-white">
            <thead class="table-light"><tr><th style="width: 22%">Field</th><th>Old value</th><th>New value</th></tr></thead>
            <tbody>
                <tr v-for="r in rows" :key="r.key">
                    <td class="font-monospace">{{ r.key }}</td>
                    <td class="text-break" :class="r.changed && r.old ? 'text-danger text-decoration-line-through' : 'text-muted'">{{ r.old || '—' }}</td>
                    <td class="text-break" :class="r.changed ? 'text-success fw-semibold' : 'text-muted'">{{ r.new || '—' }}</td>
                </tr>
            </tbody>
        </table>
        <div v-else class="text-muted mb-2">No field changes recorded.</div>
        <dl v-if="metaRows.length" class="row mb-0">
            <template v-for="m in metaRows" :key="m.key">
                <dt class="col-sm-3 fw-normal text-muted font-monospace">{{ m.key }}</dt>
                <dd class="col-sm-9 text-break mb-1">{{ m.value }}</dd>
            </template>
        </dl>
    </div>
</template>
