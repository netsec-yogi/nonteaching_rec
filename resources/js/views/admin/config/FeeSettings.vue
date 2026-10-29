<script setup lang="ts">
/**
 * Administration → Application fee settings: the bank details and QR code applicants pay the fee to,
 * per advertisement (or per post). The fee amount and exemptions stay in the advertisement / post fee
 * schedule, the single source the server calculates fees from; this page shows them alongside.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { api, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import BaseModal from '@/components/BaseModal.vue';
import FieldError from '@/components/FieldError.vue';
import LoadingState from '@/components/LoadingState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { useToast } from '@/stores/toast';
import { formatDateTime, formatMoney, humanize } from '@/utils/format';
import { PAYMENT_METHODS, type FeeConfigAdvertisement, type FeeConfigDetail, type FeeConfigSummary, type FeeSchedule } from './parts/types';

const toast = useToast();
const form = useForm();
const loading = ref(true);
const error = ref<string | null>(null);
const configs = ref<FeeConfigSummary[]>([]);
const ads = ref<FeeConfigAdvertisement[]>([]);
const accountTypes = ref<Record<string, string>>({});
const limits = ref({ max_kb: 2048, min_px: 200, max_px: 4000 });
const adFilter = ref<number | ''>('');

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/admin/fee-configs');
        configs.value = data.configs; ads.value = data.advertisements; accountTypes.value = data.account_types; limits.value = data.qr_limits;
    } catch (e) { error.value = toApiError(e).message; } finally { loading.value = false; }
}
onMounted(load);
const shown = computed(() => configs.value.filter((c) => !adFilter.value || c.advertisement.id === adFilter.value));

function feeText(s: FeeSchedule | null | undefined): string {
    if (!s || Number(s.default) <= 0) return 'No fee';
    const ex = Object.entries(s.exemptions ?? {}).filter(([, v]) => Number(v) < Number(s.default));
    return formatMoney(s.default) + (ex.length ? ` · ${ex.map(([k, v]) => `${k}: ${Number(v) === 0 ? 'exempt' : formatMoney(v)}`).join(', ')}` : '');
}

// ------------------------------------------------------------------ editor
const editing = ref<FeeConfigDetail | null | undefined>(undefined); // undefined = closed, null = new
const blank = () => ({
    advertisement_id: '' as number | '', post_id: '' as number | '', label: '', account_holder: '', bank_name: '', branch_name: '',
    account_number: '', account_type: '', ifsc: '', branch_address: '', upi_id: '', instructions: '', reference_instructions: '',
    payment_deadline: '', is_active: false,
});
const f = reactive(blank());
const qrFile = ref<File | null>(null);
const qrPreview = ref<string | null>(null);
const qrError = ref<string | null>(null);
const removeQr = ref(false);
const verified = ref(false);
const needsConfirm = ref<string | null>(null);
const confirmChange = ref(false);
const reason = ref('');
const qrInput = ref<HTMLInputElement | null>(null);

const toLocal = (iso: string | null) => {
    if (!iso) return '';
    const d = new Date(iso);
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
};
function resetQr() {
    if (qrPreview.value) URL.revokeObjectURL(qrPreview.value);
    qrFile.value = null; qrPreview.value = null; qrError.value = null; removeQr.value = false;
    if (qrInput.value) qrInput.value.value = '';
}
onBeforeUnmount(resetQr);

async function open(c: FeeConfigSummary | null) {
    form.clear(); resetQr(); verified.value = false; needsConfirm.value = null; confirmChange.value = false; reason.value = '';
    Object.assign(f, blank());
    if (!c) { f.advertisement_id = adFilter.value || (ads.value[0]?.id ?? ''); editing.value = null; return; }
    try {
        const d = (await api.get<FeeConfigDetail>(`/admin/fee-configs/${c.id}`)).data;
        Object.assign(f, {
            advertisement_id: d.advertisement.id, post_id: d.post?.id ?? '', label: d.label ?? '', account_holder: d.account_holder ?? '',
            bank_name: d.bank_name ?? '', branch_name: d.branch_name ?? '', account_number: d.account_number ?? '', account_type: d.account_type ?? '',
            ifsc: d.ifsc ?? '', branch_address: d.branch_address ?? '', upi_id: d.upi_id ?? '', instructions: d.instructions ?? '',
            reference_instructions: d.reference_instructions ?? '', payment_deadline: toLocal(d.payment_deadline), is_active: d.is_active,
        });
        editing.value = d;
    } catch (e) { toast.error(toApiError(e).message); }
}
const close = () => { resetQr(); editing.value = undefined; };

const selectedAd = computed(() => ads.value.find((a) => a.id === Number(f.advertisement_id)) ?? null);
const selectedPost = computed(() => selectedAd.value?.posts.find((p) => p.id === Number(f.post_id)) ?? null);
const schedule = computed(() => selectedPost.value?.fee_schedule ?? selectedAd.value?.fee_schedule ?? null);
const adMethods = computed<string[]>(() => selectedAd.value?.payment_methods ?? []);
const methodLabel = (m: string): string => PAYMENT_METHODS[m] ?? humanize(m);
watch(() => f.advertisement_id, () => { if (editing.value === null) f.post_id = ''; });

/** Checked here for a quick answer; the server re-checks type, signature, size and dimensions. */
async function pickQr(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    resetQr();
    if (!file) return;
    if (!['image/png', 'image/jpeg'].includes(file.type)) { qrError.value = 'Choose a PNG or JPEG image.'; return; }
    if (file.size > limits.value.max_kb * 1024) { qrError.value = 'The image must not be larger than 2 MB.'; return; }
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
        const small = Math.min(img.naturalWidth, img.naturalHeight) < limits.value.min_px;
        const big = Math.max(img.naturalWidth, img.naturalHeight) > limits.value.max_px;
        if (small || big) {
            URL.revokeObjectURL(url);
            qrError.value = small ? `The image is too small to scan reliably (at least ${limits.value.min_px} × ${limits.value.min_px} pixels).` : `The image must not exceed ${limits.value.max_px} pixels on either side.`;
            return;
        }
        qrFile.value = file; qrPreview.value = url;
    };
    img.onerror = () => { URL.revokeObjectURL(url); qrError.value = 'The image could not be read.'; };
    img.src = url;
}

const existingQrUrl = computed(() => (editing.value?.has_qr && !removeQr.value ? `/api/admin/fee-configs/${editing.value.id}/qr?v=${editing.value.version}` : null));
const previewQr = computed(() => qrPreview.value ?? existingQrUrl.value);
const activating = computed(() => f.is_active && !editing.value?.is_active);
const ifscOk = computed(() => !f.ifsc || /^[A-Z]{4}0[A-Z0-9]{6}$/.test(f.ifsc.toUpperCase()));

async function save() {
    if (activating.value && !verified.value) { toast.error('Check the preview and tick the confirmation before activating.'); return; }
    const data = new FormData();
    const e = editing.value;
    const fields: Record<string, unknown> = { ...f, ifsc: f.ifsc.toUpperCase(), is_active: f.is_active ? '1' : '0' };
    if (e) { delete fields.advertisement_id; delete fields.post_id; }
    Object.entries(fields).forEach(([k, v]) => data.append(k, v === null || v === undefined ? '' : String(v)));
    if (qrFile.value) data.append('qr', qrFile.value);
    if (removeQr.value) data.append('remove_qr', '1');
    if (confirmChange.value) data.append('confirm', '1');
    if (reason.value) data.append('reason', reason.value);
    const res = await form.run(() => api.post(e ? `/admin/fee-configs/${e.id}` : '/admin/fee-configs', data), e ? 'Payment configuration saved.' : 'Payment configuration created.');
    if (res) { close(); load(); return; }
    if (form.errors.confirm) needsConfirm.value = form.errors.confirm[0];
}

// ------------------------------------------------------------------ row actions
const statusTarget = ref<FeeConfigSummary | null>(null);
const statusConfirm = ref<string | null>(null);
const statusForm = useForm();
async function setActive(c: FeeConfigSummary, active: boolean, confirm = false) {
    const res = await statusForm.run(() => api.post(`/admin/fee-configs/${c.id}/status`, { active, confirm }), active ? 'Configuration activated.' : 'Configuration deactivated.');
    if (res) { statusTarget.value = null; statusConfirm.value = null; load(); return; }
    if (statusForm.errors.confirm) { statusTarget.value = c; statusConfirm.value = statusForm.errors.confirm[0]; }
    else if (statusForm.errors.is_active) toast.error(statusForm.errors.is_active.join(' '));
}

const copySource = ref<FeeConfigSummary | null>(null);
const copyTo = reactive({ advertisement_id: '' as number | '', post_id: '' as number | '' });
const copyForm = useForm();
const copyAd = computed(() => ads.value.find((a) => a.id === Number(copyTo.advertisement_id)) ?? null);
function openCopy(c: FeeConfigSummary) { copySource.value = c; copyTo.advertisement_id = ''; copyTo.post_id = ''; copyForm.clear(); }
async function doCopy() {
    const c = copySource.value!;
    const res = await copyForm.run(() => api.post(`/admin/fee-configs/${c.id}/copy`, { advertisement_id: copyTo.advertisement_id, post_id: copyTo.post_id || null }), 'Copied as an inactive configuration. Review it and activate it.');
    if (res) { copySource.value = null; load(); }
}

async function remove(c: FeeConfigSummary) {
    if (!window.confirm(`Delete this payment configuration for ${c.advertisement.number}? This cannot be undone.`)) return;
    try { await api.delete(`/admin/fee-configs/${c.id}`); toast.success('Configuration deleted.'); load(); } catch (e) { toast.error(toApiError(e).message); }
}
</script>

<template>
    <div>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
            <div>
                <h1 class="page-title h4 mb-0">Application fee settings</h1>
                <p class="page-subtitle mb-0">Bank details and QR code applicants use to pay the application fee, for each advertisement or post.</p>
            </div>
            <div class="ms-auto d-flex flex-wrap gap-2">
                <label class="visually-hidden" for="fee-ad-filter">Advertisement</label>
                <select id="fee-ad-filter" v-model="adFilter" class="form-select form-select-sm" style="max-width: 18rem">
                    <option value="">All advertisements</option>
                    <option v-for="a in ads" :key="a.id" :value="a.id">{{ a.number }}</option>
                </select>
                <button type="button" class="btn btn-primary btn-sm" :disabled="!ads.length" @click="open(null)"><i class="bi bi-plus-lg me-1"></i>New configuration</button>
            </div>
        </div>
        <p class="small text-muted mt-2">
            Applicants see the active configuration for their post, or else the one for the whole advertisement. The fee amount and exemptions come from the
            advertisement / post fee schedule (edit them under <router-link to="/admin/advertisements">Advertisements</router-link>); the server always calculates the fee from it.
            Every change is versioned, and payments keep the details they were made against.
        </p>

        <LoadingState :loading="loading" :error="error">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 table-cards">
                        <thead class="table-light"><tr><th>Advertisement / post</th><th>Bank</th><th>Account</th><th>QR</th><th>Fee</th><th>Status</th><th>Updated</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
                        <tbody>
                            <tr v-for="c in shown" :key="c.id">
                                <td>
                                    <div class="fw-semibold small">{{ c.advertisement.number }}</div>
                                    <div class="small text-muted">{{ c.post ? `${c.post.code} · ${c.post.name}` : 'All posts' }}<span v-if="c.label"> · {{ c.label }}</span></div>
                                </td>
                                <td class="small">{{ c.bank_name ?? '—' }}<div v-if="c.ifsc" class="text-muted font-monospace">{{ c.ifsc }}</div></td>
                                <td class="small font-monospace">{{ c.account_number_masked ?? '—' }}<div v-if="c.upi_id" class="text-muted">{{ c.upi_id }}</div></td>
                                <td class="small">{{ c.has_qr ? 'Uploaded' : '—' }}</td>
                                <td class="small">{{ feeText(c.fee_schedule) }}</td>
                                <td><StatusBadge :value="c.is_active ? 'active' : 'inactive'" /><div class="small text-muted">v{{ c.version }} · {{ c.payments_count }} payment(s)</div></td>
                                <td class="small text-nowrap">{{ formatDateTime(c.updated_at) }}<div class="text-muted">{{ c.updated_by }}</div></td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-primary me-1" @click="open(c)"><i class="bi bi-pencil"></i> Edit</button>
                                    <button v-if="c.is_active" type="button" class="btn btn-sm btn-outline-secondary me-1" :disabled="statusForm.busy.value" @click="setActive(c, false)">Deactivate</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary me-1" :aria-label="`Copy configuration for ${c.advertisement.number}`" title="Copy to another advertisement or post" @click="openCopy(c)"><i class="bi bi-copy"></i></button>
                                    <button v-if="!c.is_active && !c.payments_count" type="button" class="btn btn-sm btn-outline-danger" :aria-label="`Delete configuration for ${c.advertisement.number}`" title="Delete" @click="remove(c)"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                            <tr v-if="!shown.length"><td colspan="8" class="text-center text-muted small py-4">No payment configuration yet. Create one so applicants can see where to pay the fee.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </LoadingState>

        <!-- Editor -->
        <BaseModal v-if="editing !== undefined" :title="editing ? `Edit payment configuration (version ${editing.version})` : 'New payment configuration'" size="xl" @close="close">
            <div v-if="form.message && !Object.keys(form.errors).length" class="alert alert-danger small">{{ form.message }}</div>
            <div class="row g-4">
                <div class="col-lg-7">
                    <fieldset class="mb-3">
                        <legend class="h6">Applies to</legend>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label required" for="fc-ad">Advertisement</label>
                                <select id="fc-ad" v-model="f.advertisement_id" class="form-select" :disabled="!!editing" :class="{ 'is-invalid': form.errors.advertisement_id }">
                                    <option v-for="a in ads" :key="a.id" :value="a.id">{{ a.number }} — {{ a.title }}</option>
                                </select>
                                <FieldError :errors="form.errors" name="advertisement_id" />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="fc-post">Post</label>
                                <select id="fc-post" v-model="f.post_id" class="form-select" :disabled="!!editing" :class="{ 'is-invalid': form.errors.post_id }">
                                    <option value="">All posts of the advertisement</option>
                                    <option v-for="p in selectedAd?.posts ?? []" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option>
                                </select>
                                <FieldError :errors="form.errors" name="post_id" />
                                <div v-if="editing" class="form-text">Fixed after creation — copy the configuration to use it elsewhere.</div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-label">Application fee</div>
                                <div class="small">{{ feeText(schedule) }}</div>
                                <div class="form-text">From the {{ selectedPost?.fee_schedule ? 'post' : 'advertisement' }} fee schedule.</div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-label">Accepted payment methods</div>
                                <div class="small">{{ adMethods.map(methodLabel).join(', ') || '—' }}</div>
                                <div class="form-text">Set on the advertisement.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="fc-label">Internal label</label>
                                <input id="fc-label" v-model="f.label" class="form-control" maxlength="150" placeholder="e.g. Recruitment fee account 2026" :class="{ 'is-invalid': form.errors.label }">
                                <FieldError :errors="form.errors" name="label" />
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="mb-3">
                        <legend class="h6">Bank account</legend>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="fc-holder">Account holder name</label>
                                <input id="fc-holder" v-model="f.account_holder" class="form-control" maxlength="200" :class="{ 'is-invalid': form.errors.account_holder }">
                                <FieldError :errors="form.errors" name="account_holder" />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="fc-bank">Bank name</label>
                                <input id="fc-bank" v-model="f.bank_name" class="form-control" maxlength="200" :class="{ 'is-invalid': form.errors.bank_name }">
                                <FieldError :errors="form.errors" name="bank_name" />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="fc-branch">Branch name</label>
                                <input id="fc-branch" v-model="f.branch_name" class="form-control" maxlength="200" :class="{ 'is-invalid': form.errors.branch_name }">
                                <FieldError :errors="form.errors" name="branch_name" />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="fc-ifsc">IFSC code</label>
                                <input id="fc-ifsc" v-model.trim="f.ifsc" class="form-control font-monospace text-uppercase" maxlength="11" placeholder="SBIN0001234" :class="{ 'is-invalid': form.errors.ifsc || !ifscOk }" aria-describedby="fc-ifsc-help">
                                <FieldError :errors="form.errors" name="ifsc" />
                                <div id="fc-ifsc-help" class="form-text" :class="{ 'text-danger': !ifscOk }">11 characters: four letters, 0, then six letters or digits.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="fc-acno">Account number</label>
                                <input id="fc-acno" v-model.trim="f.account_number" class="form-control font-monospace" inputmode="numeric" maxlength="20" autocomplete="off" :class="{ 'is-invalid': form.errors.account_number }">
                                <FieldError :errors="form.errors" name="account_number" />
                                <div class="form-text">Stored encrypted; shown only to administrators with this permission and to applicants who must pay.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="fc-actype">Account type</label>
                                <select id="fc-actype" v-model="f.account_type" class="form-select" :class="{ 'is-invalid': form.errors.account_type }">
                                    <option value="">—</option>
                                    <option v-for="(label, key) in accountTypes" :key="key" :value="key">{{ label }}</option>
                                </select>
                                <FieldError :errors="form.errors" name="account_type" />
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="fc-addr">Branch address</label>
                                <input id="fc-addr" v-model="f.branch_address" class="form-control" maxlength="500" :class="{ 'is-invalid': form.errors.branch_address }">
                                <FieldError :errors="form.errors" name="branch_address" />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="fc-upi">UPI ID</label>
                                <input id="fc-upi" v-model.trim="f.upi_id" class="form-control font-monospace" maxlength="100" placeholder="name@bank" :class="{ 'is-invalid': form.errors.upi_id }">
                                <FieldError :errors="form.errors" name="upi_id" />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="fc-deadline">Payment deadline</label>
                                <input id="fc-deadline" v-model="f.payment_deadline" type="datetime-local" class="form-control" :class="{ 'is-invalid': form.errors.payment_deadline }">
                                <FieldError :errors="form.errors" name="payment_deadline" />
                                <div class="form-text">Shown to applicants; leave empty to use the application closing date.</div>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="h6">Instructions</legend>
                        <div class="mb-3">
                            <label class="form-label" for="fc-instr">Payment instructions</label>
                            <textarea id="fc-instr" v-model="f.instructions" class="form-control" rows="4" maxlength="5000" :class="{ 'is-invalid': form.errors.instructions }"></textarea>
                            <FieldError :errors="form.errors" name="instructions" />
                        </div>
                        <div>
                            <label class="form-label" for="fc-ref">Payment reference instructions</label>
                            <textarea id="fc-ref" v-model="f.reference_instructions" class="form-control" rows="2" maxlength="2000" placeholder="e.g. Write your registered email address in the transfer remarks and keep the UTR number." :class="{ 'is-invalid': form.errors.reference_instructions }"></textarea>
                            <FieldError :errors="form.errors" name="reference_instructions" />
                        </div>
                    </fieldset>
                </div>

                <div class="col-lg-5">
                    <fieldset class="mb-3">
                        <legend class="h6">Bank QR code</legend>
                        <div class="fc-qr-box mb-2">
                            <img v-if="previewQr" :src="previewQr" alt="Bank QR code preview" class="fc-qr-img">
                            <div v-else class="text-muted small py-4"><i class="bi bi-qr-code fs-2 d-block mb-1" aria-hidden="true"></i>No QR code. Applicants will see the bank details only.</div>
                        </div>
                        <div v-if="qrFile" class="small text-muted mb-2">New image: {{ qrFile.name }} ({{ Math.round(qrFile.size / 1024) }} KB) — saved when you save.</div>
                        <div v-else-if="editing?.qr && !removeQr" class="small text-muted mb-2">Current image: {{ editing.qr.width }} × {{ editing.qr.height }} px, {{ Math.round(editing.qr.size / 1024) }} KB<span v-if="!editing.qr.available" class="text-danger"> — file missing, upload it again</span>.</div>
                        <label class="form-label" for="fc-qr">{{ editing?.has_qr || qrFile ? 'Replace the QR code' : 'Upload the QR code' }}</label>
                        <input id="fc-qr" ref="qrInput" type="file" class="form-control" accept="image/png,image/jpeg" :class="{ 'is-invalid': qrError || form.errors.qr }" @change="pickQr">
                        <div v-if="qrError" class="invalid-feedback d-block">{{ qrError }}</div>
                        <FieldError :errors="form.errors" name="qr" />
                        <div class="form-text">PNG or JPEG, up to 2 MB, at least {{ limits.min_px }} × {{ limits.min_px }} px. Stored exactly as uploaded (never resized or cropped).</div>
                        <div v-if="editing?.has_qr && !qrFile" class="form-check mt-2">
                            <input id="fc-qr-remove" v-model="removeQr" class="form-check-input" type="checkbox">
                            <label class="form-check-label" for="fc-qr-remove">Remove the current QR code</label>
                        </div>
                    </fieldset>

                    <div class="card section-card mb-3">
                        <div class="card-header small">Applicant preview</div>
                        <div class="card-body small">
                            <table class="table table-sm kv-table mb-2">
                                <tbody>
                                    <tr><th scope="row">Amount</th><td>{{ feeText(schedule) }}</td></tr>
                                    <tr><th scope="row">Account holder</th><td>{{ f.account_holder || '—' }}</td></tr>
                                    <tr><th scope="row">Bank / branch</th><td>{{ [f.bank_name, f.branch_name].filter(Boolean).join(', ') || '—' }}</td></tr>
                                    <tr><th scope="row">Account number</th><td class="font-monospace">{{ f.account_number || '—' }}</td></tr>
                                    <tr><th scope="row">IFSC</th><td class="font-monospace">{{ f.ifsc.toUpperCase() || '—' }}</td></tr>
                                    <tr v-if="f.upi_id"><th scope="row">UPI ID</th><td class="font-monospace">{{ f.upi_id }}</td></tr>
                                </tbody>
                            </table>
                            <div v-if="editing?.activation_problems.length && !f.is_active" class="text-muted">To activate, the saved configuration still needs: {{ editing.activation_problems.join(' ') }}</div>
                        </div>
                    </div>

                    <div class="form-check form-switch mb-2">
                        <input id="fc-active" v-model="f.is_active" class="form-check-input" type="checkbox" role="switch">
                        <label class="form-check-label fw-semibold" for="fc-active">Active — show these details to applicants</label>
                    </div>
                    <FieldError :errors="form.errors" name="is_active" />
                    <div v-if="activating" class="form-check mb-2">
                        <input id="fc-verified" v-model="verified" class="form-check-input" type="checkbox">
                        <label class="form-check-label small" for="fc-verified">I have checked the bank details and the QR code in the preview against the Institute's bank records. Any other active configuration for the same advertisement / post will be deactivated.</label>
                    </div>
                    <div v-if="editing?.is_active" class="alert alert-warning small py-2">
                        <i class="bi bi-exclamation-triangle me-1"></i>This configuration is active<span v-if="editing.affected_applications"> and {{ editing.affected_applications }} application(s) use it</span>. Changes are shown to applicants immediately; payments already entered keep the details they were made against.
                    </div>
                    <div v-if="needsConfirm" class="alert alert-warning small">
                        <div class="mb-2">{{ needsConfirm }}</div>
                        <div class="form-check mb-2">
                            <input id="fc-confirm" v-model="confirmChange" class="form-check-input" type="checkbox">
                            <label class="form-check-label" for="fc-confirm">I confirm this change</label>
                        </div>
                        <label class="form-label" for="fc-reason">Reason for the change (recorded in the history)</label>
                        <input id="fc-reason" v-model="reason" class="form-control form-control-sm" maxlength="500">
                    </div>

                    <div v-if="editing?.revisions.length" class="mt-3">
                        <div class="fw-semibold small mb-1">History</div>
                        <ul class="list-unstyled small mb-0">
                            <li v-for="r in editing.revisions" :key="r.version" class="mb-1">
                                <span class="badge text-bg-light border me-1">v{{ r.version }}</span>{{ humanize(r.event) }} · {{ r.by ?? '—' }} · {{ formatDateTime(r.at) }}
                                <div v-if="r.reason" class="text-muted">{{ r.reason }}</div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="close">Cancel</button>
                <button type="button" class="btn btn-primary" :disabled="form.busy.value || (activating && !verified) || (!!needsConfirm && !confirmChange)" @click="save">
                    <span v-if="form.busy.value" class="spinner-border spinner-border-sm me-1"></span>{{ activating ? 'Save and activate' : 'Save' }}
                </button>
            </template>
        </BaseModal>

        <!-- Deactivation needing confirmation -->
        <BaseModal v-if="statusTarget && statusConfirm" title="Confirm deactivation" @close="statusTarget = null">
            <p class="small mb-0">{{ statusConfirm }}</p>
            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="statusTarget = null">Cancel</button>
                <button type="button" class="btn btn-danger" :disabled="statusForm.busy.value" @click="setActive(statusTarget, false, true)">Deactivate</button>
            </template>
        </BaseModal>

        <!-- Copy -->
        <BaseModal v-if="copySource" title="Copy payment configuration" @close="copySource = null">
            <p class="small text-muted">Creates an independent, inactive copy (with the QR code) for another advertisement or post. The original is not changed.</p>
            <div class="mb-3">
                <label class="form-label required" for="cp-ad">Advertisement</label>
                <select id="cp-ad" v-model="copyTo.advertisement_id" class="form-select" :class="{ 'is-invalid': copyForm.errors.advertisement_id }" @change="copyTo.post_id = ''">
                    <option value="" disabled>Select…</option>
                    <option v-for="a in ads" :key="a.id" :value="a.id">{{ a.number }} — {{ a.title }}</option>
                </select>
                <FieldError :errors="copyForm.errors" name="advertisement_id" />
            </div>
            <div>
                <label class="form-label" for="cp-post">Post</label>
                <select id="cp-post" v-model="copyTo.post_id" class="form-select" :class="{ 'is-invalid': copyForm.errors.post_id }">
                    <option value="">All posts of the advertisement</option>
                    <option v-for="p in copyAd?.posts ?? []" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option>
                </select>
                <FieldError :errors="copyForm.errors" name="post_id" />
            </div>
            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="copySource = null">Cancel</button>
                <button type="button" class="btn btn-primary" :disabled="!copyTo.advertisement_id || copyForm.busy.value" @click="doCopy">Copy</button>
            </template>
        </BaseModal>
    </div>
</template>

<style scoped>
.fc-qr-box { display: flex; align-items: center; justify-content: center; min-height: 12rem; padding: 0.75rem; border: 1px dashed var(--line); border-radius: 0.625rem; background: var(--surface-sunken); text-align: center; }
.fc-qr-img { width: auto; height: auto; max-width: 100%; max-height: 16rem; object-fit: contain; background: #fff; padding: 0.5rem; border-radius: 0.375rem; }
</style>
