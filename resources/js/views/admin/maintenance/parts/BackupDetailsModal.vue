<script setup lang="ts">
/** A backup's full record: integrity, provenance, schema, off-site copy, and the restores that used it. */
import { onMounted, ref } from 'vue';
import { api, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import BaseModal from '@/components/BaseModal.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { formatBytes, formatDateTime, humanize } from '@/utils/format';
import { BACKUP_TYPES, duration, type BackupDetails, type Restore } from './types';

const props = defineProps<{ uuid: string; canManage: boolean }>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'changed'): void }>();

const data = ref<{ backup: BackupDetails; restores: Restore[]; safety_for: Restore | null } | null>(null);
const error = ref<string | null>(null);
const verify = useForm();
const verdict = ref<{ valid: boolean; message: string } | null>(null);

async function load() {
    try { data.value = (await api.get(`/admin/maintenance/backups/${props.uuid}`)).data; } catch (e) { error.value = toApiError(e).message; }
}
onMounted(load);

async function runVerify() {
    const res = await verify.run(() => api.post<{ valid: boolean; message: string; backup: BackupDetails }>(`/admin/maintenance/backups/${props.uuid}/verify`));
    if (res) {
        verdict.value = { valid: res.data.valid, message: res.data.message };
        if (data.value) data.value.backup = res.data.backup;
        emit('changed');
    }
}
</script>

<template>
    <BaseModal :title="data ? `Backup ${data.backup.identifier}` : 'Backup'" size="lg" @close="emit('close')">
        <div v-if="error" class="alert alert-danger small mb-0">{{ error }}</div>
        <div v-else-if="!data" class="small text-muted" role="status">Loading…</div>
        <template v-else>
            <dl class="row small mb-0">
                <dt class="col-sm-4 fw-medium">Status</dt><dd class="col-sm-8"><StatusBadge :value="data.backup.status" /></dd>
                <dt class="col-sm-4 fw-medium">File</dt><dd class="col-sm-8 text-break">{{ data.backup.filename ?? '—' }}</dd>
                <dt class="col-sm-4 fw-medium">Type</dt><dd class="col-sm-8">{{ BACKUP_TYPES[data.backup.type] }}<template v-if="data.backup.schedule_slot"> (slot {{ formatDateTime(data.backup.schedule_slot) }})</template></dd>
                <dt class="col-sm-4 fw-medium">Created by</dt><dd class="col-sm-8">{{ data.backup.initiated_by_name ?? '—' }}</dd>
                <template v-if="data.backup.note"><dt class="col-sm-4 fw-medium">Note</dt><dd class="col-sm-8">{{ data.backup.note }}</dd></template>
                <dt class="col-sm-4 fw-medium">Requested</dt><dd class="col-sm-8">{{ formatDateTime(data.backup.created_at) }}</dd>
                <dt class="col-sm-4 fw-medium">Completed</dt><dd class="col-sm-8">{{ formatDateTime(data.backup.completed_at) }} <span class="text-muted">({{ duration(data.backup.duration_seconds) }})</span></dd>
                <dt class="col-sm-4 fw-medium">Size</dt><dd class="col-sm-8">{{ formatBytes(data.backup.size_bytes) }} <span v-if="data.backup.dump_bytes" class="text-muted">({{ formatBytes(data.backup.dump_bytes) }} of SQL)</span></dd>
                <dt class="col-sm-4 fw-medium">Encryption</dt><dd class="col-sm-8">{{ data.backup.encrypted ? 'Encrypted at rest (XChaCha20-Poly1305)' : 'Not encrypted' }}</dd>
                <dt class="col-sm-4 fw-medium">SHA-256</dt><dd class="col-sm-8 font-monospace text-break">{{ data.backup.checksum_sha256 ?? '—' }}</dd>
                <dt class="col-sm-4 fw-medium">Last verified</dt><dd class="col-sm-8">{{ formatDateTime(data.backup.verified_at) }}</dd>
                <template v-if="data.backup.copy_status">
                    <dt class="col-sm-4 fw-medium">Off-site copy</dt>
                    <dd class="col-sm-8">{{ data.backup.copy_status === 'copied' ? `Copied ${formatDateTime(data.backup.copied_at)}` : `Failed: ${data.backup.copy_error}` }}</dd>
                </template>
                <template v-if="data.backup.manifest">
                    <dt class="col-sm-4 fw-medium">Source database</dt>
                    <dd class="col-sm-8">{{ data.backup.manifest.database }} · server {{ data.backup.manifest.server_version }}<template v-if="data.backup.manifest.app_version"> · portal {{ data.backup.manifest.app_version }}</template></dd>
                    <dt class="col-sm-4 fw-medium">Contents</dt>
                    <dd class="col-sm-8">{{ data.backup.manifest.table_count }} tables<template v-if="data.backup.manifest.view_count">, {{ data.backup.manifest.view_count }} views</template>, schema at {{ data.backup.manifest.migration_count }} migrations<span v-if="data.backup.manifest.latest_migration" class="text-muted text-break"> (latest {{ data.backup.manifest.latest_migration }})</span></dd>
                    <dt class="col-sm-4 fw-medium">Not included</dt>
                    <dd class="col-sm-8 text-muted">Rows of {{ data.backup.manifest.operational_tables.join(', ') }} (queue, cache, sessions and this catalogue), and uploaded documents.</dd>
                </template>
                <template v-if="data.backup.error"><dt class="col-sm-4 fw-medium">Error</dt><dd class="col-sm-8 text-danger text-break">{{ data.backup.error }}</dd></template>
                <template v-if="data.backup.deleted_at"><dt class="col-sm-4 fw-medium">Deleted</dt><dd class="col-sm-8">{{ formatDateTime(data.backup.deleted_at) }} by {{ data.backup.deleted_by_name }}<template v-if="data.backup.deletion_reason"> — {{ data.backup.deletion_reason }}</template></dd></template>
                <template v-if="data.safety_for"><dt class="col-sm-4 fw-medium">Taken before</dt><dd class="col-sm-8">Restore of {{ data.safety_for.backup?.identifier }} by {{ data.safety_for.requested_by_name }} ({{ humanize(data.safety_for.status) }})</dd></template>
            </dl>

            <div v-if="verdict" class="alert small mt-3 mb-0" :class="verdict.valid ? 'alert-success' : 'alert-danger'" role="status">
                <i class="bi me-1" :class="verdict.valid ? 'bi-shield-check' : 'bi-shield-x'" aria-hidden="true"></i>{{ verdict.message }}
            </div>

            <template v-if="data.restores.length">
                <h3 class="h6 mt-3">Restores from this backup</h3>
                <ul class="list-unstyled small mb-0 vstack gap-1">
                    <li v-for="r in data.restores" :key="r.uuid"><StatusBadge :value="r.status" class="me-1" />{{ formatDateTime(r.started_at ?? r.queued_at) }} by {{ r.requested_by_name }} — {{ r.reason }}</li>
                </ul>
            </template>
        </template>

        <template #footer>
            <button v-if="canManage && data?.backup.available" type="button" class="btn btn-outline-secondary" :disabled="verify.busy.value" @click="runVerify">
                <span v-if="verify.busy.value" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span><i v-else class="bi bi-shield-check me-1" aria-hidden="true"></i>Verify integrity
            </button>
            <button type="button" class="btn btn-primary" @click="emit('close')">Close</button>
        </template>
    </BaseModal>
</template>
