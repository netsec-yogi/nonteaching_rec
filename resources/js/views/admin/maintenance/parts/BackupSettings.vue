<script setup lang="ts">
/** Automatic backup schedule and retention (backups.manage). Runs from the Laravel scheduler, in the server's time zone. */
import { onMounted, reactive, ref } from 'vue';
import { api, fieldError, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import FieldError from '@/components/FieldError.vue';
import { formatDateTime } from '@/utils/format';
import type { BackupSettingsValues } from './types';

interface Payload { values: BackupSettingsValues; schedule: string; next_run_at: string | null; last_scheduled_success_at: string | null; encrypted: boolean; dedicated_key: boolean; offsite_copy: boolean; timezone: string }
const emit = defineEmits<{ (e: 'saved'): void }>();

const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const form = useForm();
const data = ref<Payload | null>(null);
const error = ref<string | null>(null);
const editing = ref(false);
const f = reactive<BackupSettingsValues>({ enabled: true, frequency: 'daily', time: '01:30', weekday: 0, month_day: 1, retention_days: 30, max_backups: 0 });

function fill(p: Payload) {
    data.value = p;
    Object.assign(f, p.values);
}
onMounted(async () => {
    try { fill((await api.get<Payload>('/admin/maintenance/backup-settings')).data); } catch (e) { error.value = toApiError(e).message; }
});

async function save() {
    const res = await form.run(() => api.put<Payload>('/admin/maintenance/backup-settings', { values: { ...f } }), 'Backup schedule saved.');
    if (res) {
        fill(res.data);
        editing.value = false;
        emit('saved');
    }
}
function cancel() {
    if (data.value) Object.assign(f, data.value.values);
    form.clear();
    editing.value = false;
}
</script>

<template>
    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <span><i class="bi bi-calendar-check me-1" aria-hidden="true"></i>Schedule and retention</span>
            <button v-if="data && !editing" type="button" class="btn btn-sm btn-outline-secondary ms-auto" @click="editing = true"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Change</button>
        </div>
        <div class="card-body">
            <div v-if="error" class="alert alert-danger small mb-0">{{ error }}</div>
            <template v-else-if="data">
                <dl v-if="!editing" class="row small mb-0">
                    <dt class="col-sm-4 col-lg-3 fw-medium">Automatic backups</dt>
                    <dd class="col-sm-8 col-lg-9">{{ data.schedule }} <span class="text-muted">({{ data.timezone }})</span></dd>
                    <dt class="col-sm-4 col-lg-3 fw-medium">Next scheduled backup</dt>
                    <dd class="col-sm-8 col-lg-9">{{ data.next_run_at ? formatDateTime(data.next_run_at) : '—' }}</dd>
                    <dt class="col-sm-4 col-lg-3 fw-medium">Last successful scheduled backup</dt>
                    <dd class="col-sm-8 col-lg-9">{{ data.last_scheduled_success_at ? formatDateTime(data.last_scheduled_success_at) : 'None yet' }}</dd>
                    <dt class="col-sm-4 col-lg-3 fw-medium">Retention</dt>
                    <dd class="col-sm-8 col-lg-9">
                        {{ data.values.retention_days ? `Delete backups older than ${data.values.retention_days} days` : 'No age limit' }}
                        · {{ data.values.max_backups ? `keep at most ${data.values.max_backups}` : 'no count limit' }}.
                        <span class="text-muted">The most recent valid backup is always kept.</span>
                    </dd>
                    <dt class="col-sm-4 col-lg-3 fw-medium">Protection</dt>
                    <dd class="col-sm-8 col-lg-9 mb-0">
                        {{ data.encrypted ? 'Encrypted at rest' : 'Not encrypted (BACKUP_ENCRYPT=false)' }}{{ data.encrypted ? (data.dedicated_key ? ' with a dedicated backup key' : ' with a key derived from APP_KEY') : '' }}
                        · {{ data.offsite_copy ? 'a second copy is stored off-site' : 'no off-site copy configured (BACKUP_COPY_DISK)' }}.
                    </dd>
                </dl>

                <form v-else novalidate @submit.prevent="save">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input id="bs-enabled" v-model="f.enabled" class="form-check-input" type="checkbox" role="switch">
                                <label class="form-check-label" for="bs-enabled">Take automatic backups</label>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="bs-frequency">Frequency</label>
                            <select id="bs-frequency" v-model="f.frequency" class="form-select" :disabled="!f.enabled" :class="{ 'is-invalid': fieldError(form.errors, 'frequency') }">
                                <option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option>
                            </select>
                            <FieldError :errors="form.errors" name="frequency" />
                        </div>
                        <div v-if="f.frequency === 'weekly'" class="col-6 col-md-3">
                            <label class="form-label" for="bs-weekday">Day of the week</label>
                            <select id="bs-weekday" v-model.number="f.weekday" class="form-select" :disabled="!f.enabled">
                                <option v-for="(d, i) in WEEKDAYS" :key="d" :value="i">{{ d }}</option>
                            </select>
                        </div>
                        <div v-if="f.frequency === 'monthly'" class="col-6 col-md-3">
                            <label class="form-label" for="bs-monthday">Day of the month</label>
                            <input id="bs-monthday" v-model.number="f.month_day" type="number" min="1" max="28" class="form-control" :disabled="!f.enabled" :class="{ 'is-invalid': fieldError(form.errors, 'month_day') }">
                            <FieldError :errors="form.errors" name="month_day" />
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="bs-time">Time ({{ data.timezone }})</label>
                            <input id="bs-time" v-model="f.time" type="time" class="form-control" :disabled="!f.enabled" :class="{ 'is-invalid': fieldError(form.errors, 'time') }">
                            <FieldError :errors="form.errors" name="time" />
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="bs-retention">Keep backups for (days)</label>
                            <input id="bs-retention" v-model.number="f.retention_days" type="number" min="0" max="3650" class="form-control" aria-describedby="bs-retention-help" :class="{ 'is-invalid': fieldError(form.errors, 'retention_days') }">
                            <div id="bs-retention-help" class="form-text">0 = no age limit</div>
                            <FieldError :errors="form.errors" name="retention_days" />
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="bs-max">Maximum number of backups</label>
                            <input id="bs-max" v-model.number="f.max_backups" type="number" min="0" max="1000" class="form-control" aria-describedby="bs-max-help" :class="{ 'is-invalid': fieldError(form.errors, 'max_backups') }">
                            <div id="bs-max-help" class="form-text">0 = no count limit</div>
                            <FieldError :errors="form.errors" name="max_backups" />
                        </div>
                    </div>
                    <p class="small text-muted mt-3 mb-2">Retention applies to every backup, manual ones included. The most recent valid backup is never deleted by it. A changed schedule starts from now: earlier slots are not caught up.</p>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm" :disabled="form.busy.value">Save</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" @click="cancel">Cancel</button>
                    </div>
                </form>
            </template>
            <div v-else class="small text-muted" role="status">Loading…</div>
        </div>
    </div>
</template>
