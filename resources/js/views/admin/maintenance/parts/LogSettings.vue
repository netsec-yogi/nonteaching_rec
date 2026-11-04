<script setup lang="ts">
/** Automatic log archival and retention (logs.manage), applied daily by `logs:maintain`. */
import { reactive, watch } from 'vue';
import { api, fieldError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import FieldError from '@/components/FieldError.vue';
import type { LogSettingsValues } from './types';

const props = defineProps<{ values: LogSettingsValues; dailyDays: number; channel: string }>();
const emit = defineEmits<{ (e: 'saved', v: LogSettingsValues): void }>();

const form = useForm();
const f = reactive<LogSettingsValues>({ ...props.values });
watch(() => props.values, (v) => Object.assign(f, v));

async function save() {
    const res = await form.run(() => api.put<{ values: LogSettingsValues }>('/admin/maintenance/log-settings', { values: { ...f } }), 'Log retention saved.');
    if (res) emit('saved', res.data.values);
}
</script>

<template>
    <div class="card">
        <div class="card-header"><i class="bi bi-archive me-1" aria-hidden="true"></i>Archival and retention</div>
        <form class="card-body" novalidate @submit.prevent="save">
            <div class="row g-3">
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input id="ls-auto" v-model="f.auto_archive" class="form-check-input" type="checkbox" role="switch">
                        <label class="form-check-label" for="ls-auto">Archive old log files automatically (every night)</label>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="ls-after">Archive files older than (days)</label>
                    <input id="ls-after" v-model.number="f.archive_after_days" type="number" min="1" max="3650" class="form-control" :disabled="!f.auto_archive" :class="{ 'is-invalid': fieldError(form.errors, 'archive_after_days') }">
                    <FieldError :errors="form.errors" name="archive_after_days" />
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="ls-retention">Delete archives older than (days)</label>
                    <input id="ls-retention" v-model.number="f.retention_days" type="number" min="0" max="3650" class="form-control" aria-describedby="ls-retention-help" :class="{ 'is-invalid': fieldError(form.errors, 'retention_days') }">
                    <div id="ls-retention-help" class="form-text">0 = keep archives</div>
                    <FieldError :errors="form.errors" name="retention_days" />
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="ls-warn">Warn when logs use more than (MB)</label>
                    <input id="ls-warn" v-model.number="f.warn_at_mb" type="number" min="1" class="form-control" :class="{ 'is-invalid': fieldError(form.errors, 'warn_at_mb') }">
                    <FieldError :errors="form.errors" name="warn_at_mb" />
                </div>
                <div class="col-6 col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm" :disabled="form.busy.value">Save</button>
                </div>
            </div>
            <p class="small text-muted mt-3 mb-0">
                Log channel: <span class="font-monospace">{{ channel }}</span>. With daily files, Laravel itself deletes files older than {{ dailyDays }} days (LOG_DAILY_DAYS),
                so archive them sooner than that to keep them. Files being written to are never archived or deleted.
            </p>
        </form>
    </div>
</template>
