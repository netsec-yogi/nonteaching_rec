<script setup lang="ts">
/**
 * Restore the database from a backup (backups.restore). Shows the server's pre-restore checks,
 * then asks for a reason, the backup ID typed back, the administrator's password and an explicit
 * acknowledgement. Once started it follows the restore stage by stage; the portal is offline
 * meanwhile, so failed polls are expected and simply retried.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { api, fieldError, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import BaseModal from '@/components/BaseModal.vue';
import FieldError from '@/components/FieldError.vue';
import { formatBytes, formatDateTime } from '@/utils/format';
import { BACKUP_TYPES, CHECK_ICON, RESTORE_STAGES, type Backup, type Check, type Restore } from './types';

const props = defineProps<{ backup: Backup }>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'finished'): void }>();

const form = useForm();
const checks = ref<Check[]>([]);
const busy = ref<string | null>(null);
const loadingChecks = ref(true);
const checkError = ref<string | null>(null);
const f = reactive({ reason: '', confirmation: '', password: '', acknowledge: false });
const restore = ref<Restore | null>(null);
const offline = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

onMounted(async () => {
    try {
        const { data } = await api.get<{ checks: Check[]; busy: string | null }>(`/admin/maintenance/backups/${props.backup.uuid}/restore-check`);
        checks.value = data.checks;
        busy.value = data.busy;
    } catch (e) {
        checkError.value = toApiError(e).message;
    } finally {
        loadingChecks.value = false;
    }
});
onBeforeUnmount(() => clearTimeout(timer));

const blocked = computed(() => checks.value.some((c) => c.status === 'fail') || !!busy.value || !!checkError.value);
const ready = computed(() => !blocked.value && f.reason.trim().length >= 10 && f.confirmation.trim() === props.backup.identifier && f.password !== '' && f.acknowledge);
const running = computed(() => !!restore.value && ['queued', 'running'].includes(restore.value.status));
const stageIndex = computed(() => RESTORE_STAGES.findIndex((s) => s.key === restore.value?.stage));

async function start() {
    const res = await form.run(() => api.post<{ restore: Restore }>(`/admin/maintenance/backups/${props.backup.uuid}/restore`, { ...f }));
    if (!res) return;
    f.password = '';
    restore.value = res.data.restore;
    poll();
}

async function poll() {
    clearTimeout(timer);
    if (!restore.value) return;
    try {
        const { data } = await api.get<{ restore: Restore; maintenance_mode: boolean }>(`/admin/maintenance/restores/${restore.value.uuid}`);
        restore.value = data.restore;
        offline.value = data.maintenance_mode;
    } catch {
        offline.value = true;   // tables are being replaced: the next poll will get through
    }
    if (running.value) timer = setTimeout(poll, 2500);
    else emit('finished');
}

function close() {
    if (running.value) return;   // the restore keeps going on the server; stay on its progress
    emit('close');
}
function done() {
    if (restore.value?.status === 'completed') window.location.reload();
    else close();
}
const stageState = (i: number) => {
    const r = restore.value;
    if (!r) return 'pending';
    if (r.status === 'completed') return 'done';
    if (i < stageIndex.value) return 'done';
    if (i === stageIndex.value) return r.status === 'failed' ? 'failed' : 'current';
    return r.stage === 'rolling_back' && i <= 2 ? 'done' : 'pending';
};
</script>

<template>
    <BaseModal :title="restore ? 'Database restore' : 'Restore the database from a backup'" size="lg" @close="close">
        <!-- Progress -->
        <div v-if="restore" aria-live="polite">
            <div v-if="running" class="alert alert-info small d-flex gap-2 align-items-start">
                <span class="spinner-border spinner-border-sm mt-1" aria-hidden="true"></span>
                <span>Restoring <strong>{{ backup.identifier }}</strong>. {{ offline ? 'The portal is offline (maintenance mode) while the data is replaced.' : 'Checking and preparing.' }} Keep this window open; the restore continues on the server even if you close the browser.</span>
            </div>
            <ol class="list-unstyled vstack gap-2 mb-3">
                <li v-for="(s, i) in RESTORE_STAGES" :key="s.key" class="d-flex align-items-center gap-2">
                    <i class="bi" :class="{
                        'bi-check-circle-fill text-success': stageState(i) === 'done',
                        'bi-arrow-repeat text-primary': stageState(i) === 'current',
                        'bi-x-octagon-fill text-danger': stageState(i) === 'failed',
                        'bi-circle text-muted': stageState(i) === 'pending',
                    }" aria-hidden="true"></i>
                    <span :class="{ 'fw-semibold': stageState(i) === 'current' }">{{ s.label }}</span>
                    <span class="visually-hidden">({{ stageState(i) }})</span>
                </li>
                <li v-if="restore.stage === 'rolling_back' || restore.rollback_status" class="d-flex align-items-center gap-2">
                    <i class="bi" :class="restore.rollback_status === 'succeeded' ? 'bi-check-circle-fill text-success' : restore.rollback_status === 'failed' ? 'bi-x-octagon-fill text-danger' : 'bi-arrow-repeat text-primary'" aria-hidden="true"></i>
                    <span>Rolling back to the safety backup<template v-if="restore.safety_backup"> {{ restore.safety_backup.identifier }}</template></span>
                </li>
            </ol>

            <div v-if="restore.status === 'completed'" class="alert alert-success small">
                <i class="bi bi-check-circle me-1" aria-hidden="true"></i>The database was restored from <strong>{{ backup.identifier }}</strong>.
                <template v-if="restore.migrations_applied.length"> {{ restore.migrations_applied.length }} newer migration(s) were applied.</template>
                The safety backup <strong>{{ restore.safety_backup?.identifier }}</strong> holds the data as it was just before. Reload the portal to see the restored data.
            </div>
            <div v-else-if="restore.status === 'failed'" class="alert small" :class="restore.maintenance_left_on ? 'alert-danger' : 'alert-warning'" role="alert">
                <strong>The restore failed.</strong> {{ restore.error }}
            </div>

            <template v-if="restore.health.length">
                <h3 class="h6">Health checks after the restore</h3>
                <ul class="list-unstyled small vstack gap-1 mb-0">
                    <li v-for="c in restore.health" :key="c.label"><i class="bi me-1" :class="[CHECK_ICON[c.status].icon, CHECK_ICON[c.status].cls]" :title="CHECK_ICON[c.status].label" aria-hidden="true"></i><span class="visually-hidden">{{ CHECK_ICON[c.status].label }}: </span>{{ c.label }}<span v-if="c.detail" class="text-muted"> — {{ c.detail }}</span></li>
                </ul>
            </template>
        </div>

        <!-- Confirmation -->
        <div v-else>
            <div class="alert alert-danger small" role="alert">
                <p class="fw-semibold mb-1"><i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>This replaces all current data in the portal.</p>
                <p class="mb-1">Every application, user, payment, scrutiny record and audit entry is set back to how it was on <strong>{{ formatDateTime(backup.completed_at ?? backup.created_at) }}</strong>. Anything recorded after that is lost. The portal is offline while the restore runs.</p>
                <p class="mb-0">A safety backup of the current database is taken first, and the database is rolled back to it automatically if the restore fails. Uploaded documents are not part of the database backup and are not changed.</p>
            </div>

            <dl class="row small mb-3">
                <dt class="col-sm-3 fw-medium">Backup ID</dt><dd class="col-sm-9 font-monospace">{{ backup.identifier }}</dd>
                <dt class="col-sm-3 fw-medium">File</dt><dd class="col-sm-9 text-break">{{ backup.filename }}</dd>
                <dt class="col-sm-3 fw-medium">Taken</dt><dd class="col-sm-9">{{ formatDateTime(backup.completed_at ?? backup.created_at) }} · {{ BACKUP_TYPES[backup.type] }} · {{ formatBytes(backup.size_bytes) }}</dd>
                <template v-if="backup.note"><dt class="col-sm-3 fw-medium">Note</dt><dd class="col-sm-9">{{ backup.note }}</dd></template>
            </dl>

            <h3 class="h6">Checks</h3>
            <div v-if="loadingChecks" class="small text-muted mb-3" role="status"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Checking the backup…</div>
            <div v-else-if="checkError" class="alert alert-danger small">{{ checkError }}</div>
            <ul v-else class="list-unstyled small vstack gap-1 mb-3">
                <li v-for="c in checks" :key="c.key ?? c.label"><i class="bi me-1" :class="[CHECK_ICON[c.status].icon, CHECK_ICON[c.status].cls]" :title="CHECK_ICON[c.status].label" aria-hidden="true"></i><span class="visually-hidden">{{ CHECK_ICON[c.status].label }}: </span>{{ c.label }}<span v-if="c.detail" class="text-muted"> — {{ c.detail }}</span></li>
                <li class="text-muted"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>The complete file is decrypted and checked again before anything is changed.</li>
            </ul>
            <div v-if="busy" class="alert alert-warning small">{{ busy }}</div>

            <form id="restore-form" novalidate @submit.prevent="ready && start()">
                <div class="mb-3">
                    <label class="form-label" for="rs-reason">Reason for the restore</label>
                    <textarea id="rs-reason" v-model="f.reason" class="form-control" rows="2" maxlength="1000" required aria-describedby="rs-reason-help" :class="{ 'is-invalid': fieldError(form.errors, 'reason') }"></textarea>
                    <div id="rs-reason-help" class="form-text">Recorded in the restore history and the audit trail (at least 10 characters).</div>
                    <FieldError :errors="form.errors" name="reason" />
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label" for="rs-confirm">Type the backup ID to confirm</label>
                        <input id="rs-confirm" v-model="f.confirmation" class="form-control font-monospace" autocomplete="off" spellcheck="false" :placeholder="backup.identifier" :class="{ 'is-invalid': fieldError(form.errors, 'confirmation') }">
                        <FieldError :errors="form.errors" name="confirmation" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="rs-password">Your password</label>
                        <input id="rs-password" v-model="f.password" type="password" class="form-control" autocomplete="current-password" :class="{ 'is-invalid': fieldError(form.errors, 'password') }">
                        <FieldError :errors="form.errors" name="password" />
                    </div>
                </div>
                <div class="form-check">
                    <input id="rs-ack" v-model="f.acknowledge" class="form-check-input" type="checkbox">
                    <label class="form-check-label small" for="rs-ack">I understand that all data recorded after {{ formatDateTime(backup.completed_at ?? backup.created_at) }} will be replaced, and that the portal is offline during the restore.</label>
                </div>
                <div v-if="form.message.value && !Object.keys(form.errors).length" class="alert alert-danger small mt-3 mb-0">{{ form.message.value }}</div>
                <div v-if="fieldError(form.errors, 'backup')" class="alert alert-danger small mt-3 mb-0">{{ fieldError(form.errors, 'backup') }}</div>
            </form>
        </div>

        <template #footer>
            <template v-if="!restore">
                <button type="button" class="btn btn-outline-secondary" @click="close">Cancel</button>
                <button type="submit" form="restore-form" class="btn btn-danger" :disabled="!ready || form.busy.value"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Restore database</button>
            </template>
            <button v-else-if="!running" type="button" class="btn btn-primary" @click="done">{{ restore.status === 'completed' ? 'Reload the portal' : 'Close' }}</button>
        </template>
    </BaseModal>
</template>
