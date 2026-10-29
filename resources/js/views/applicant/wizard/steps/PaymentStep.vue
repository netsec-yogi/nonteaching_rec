<script setup lang="ts">
/**
 * Section 1 — Application fee. Shows the fee payable, the bank details / QR code configured for this
 * advertisement (Administration → Application fee settings), and takes the transaction details.
 * Recording a payment here never marks it verified: staff verify it after submission.
 */
import { computed, ref } from 'vue';
import BaseModal from '@/components/BaseModal.vue';
import FieldError from '@/components/FieldError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { useToast } from '@/stores/toast';
import { formatDateTime, formatMoney } from '@/utils/format';
import { PAYMENT_METHOD_LABELS, today, useWizard } from '../context';
import StepUploads from '../StepUploads.vue';
import type { PaymentData } from '../types';

const props = defineProps<{ form: PaymentData }>();
const { payload, errors } = useWizard();
const toast = useToast();
const app = computed(() => payload.value.application);
const fee = computed(() => app.value.fee);
const ad = computed(() => app.value.advertisement);
const bank = computed(() => app.value.fee_payment);
const exempt = computed(() => Number(fee.value.amount) <= 0);
const methods = computed(() => (ad.value.payment_methods ?? []).filter((m) => m !== 'exempt'));
const inv = (k: string) => ({ 'is-invalid': !!errors[k] });
const recorded = computed(() => props.form.status && props.form.status !== 'exempt');
const qrUrl = computed(() => `/api/applications/${app.value.uuid}/fee-qr?v=${bank.value?.version ?? 0}`);
const qrOpen = ref(false);
const qrFailed = ref(false);
const deadlinePassed = computed(() => !!bank.value?.payment_deadline && new Date(bank.value.payment_deadline) < new Date());
const mismatch = computed(() => props.form.amount !== null && props.form.amount !== undefined && (props.form.amount as unknown) !== ''
    && Math.abs(Number(props.form.amount) - Number(fee.value.amount)) > 0.001);
const refLabel = computed(() => (props.form.method === 'demand_draft' ? 'DD number' : props.form.method === 'upi' ? 'UPI transaction ID / UTR' : 'UTR / transaction number'));

async function copy(value: string | null, what: string) {
    if (!value) return;
    try { await navigator.clipboard.writeText(value); toast.success(`${what} copied.`); } catch { toast.error('Could not copy — select the text instead.'); }
}
</script>

<template>
    <div class="vstack gap-3">
        <div class="card section-card">
            <div class="card-header">1. Application fee</div>
            <div class="card-body">
                <div class="row g-3 align-items-center">
                    <div class="col-sm-5 col-xl-4">
                        <div class="text-muted small">Fee payable for this application</div>
                        <div class="fs-3 fw-bold">{{ exempt ? 'Fee exempted' : formatMoney(fee.amount) }}</div>
                        <div v-if="fee.basis" class="small text-muted">Basis: {{ fee.basis }}</div>
                    </div>
                    <div v-if="ad.fee_rules_text" class="col-sm-7 col-xl-8 small text-muted text-pre">{{ ad.fee_rules_text }}</div>
                </div>
                <div v-if="exempt" class="alert alert-success mb-0 mt-3">
                    <i class="bi bi-check-circle me-1"></i>
                    You are exempted from paying the application fee<span v-if="fee.basis"> ({{ fee.basis }})</span>.
                    Save this step to confirm the exemption. The basis of exemption (category, PwBD status, etc.) will be
                    verified against your certificates during scrutiny.
                </div>
            </div>
        </div>

        <template v-if="!exempt">
            <section class="card section-card" aria-labelledby="fee-pay-heading">
                <div class="card-header d-flex flex-wrap align-items-center gap-2">
                    <span id="fee-pay-heading"><i class="bi bi-bank me-1" aria-hidden="true"></i>Application Fee Payment Details</span>
                    <span v-if="bank?.source === 'as_paid'" class="badge text-bg-light border ms-auto">Details your payment was made against</span>
                </div>
                <div v-if="bank" class="card-body">
                    <div class="row g-4">
                        <div class="col-lg-7 col-xxl-8">
                            <table class="table table-sm kv-table mb-3">
                                <tbody>
                                    <tr><th scope="row">Amount to pay</th><td class="fw-semibold">{{ formatMoney(fee.amount) }}</td></tr>
                                    <tr v-if="bank.account_holder"><th scope="row">Account holder</th><td>{{ bank.account_holder }}</td></tr>
                                    <tr v-if="bank.bank_name"><th scope="row">Bank</th><td>{{ bank.bank_name }}</td></tr>
                                    <tr v-if="bank.branch_name"><th scope="row">Branch</th><td>{{ bank.branch_name }}<div v-if="bank.branch_address" class="small text-muted">{{ bank.branch_address }}</div></td></tr>
                                    <tr v-if="bank.account_number">
                                        <th scope="row">Account number</th>
                                        <td><span class="font-monospace fw-semibold user-select-all">{{ bank.account_number }}</span>
                                            <button type="button" class="btn btn-link btn-sm py-0" @click="copy(bank.account_number, 'Account number')"><i class="bi bi-copy" aria-hidden="true"></i> Copy</button>
                                            <span v-if="bank.account_type" class="small text-muted ms-1">({{ bank.account_type }} account)</span></td>
                                    </tr>
                                    <tr v-if="bank.ifsc">
                                        <th scope="row">IFSC code</th>
                                        <td><span class="font-monospace fw-semibold user-select-all">{{ bank.ifsc }}</span>
                                            <button type="button" class="btn btn-link btn-sm py-0" @click="copy(bank.ifsc, 'IFSC code')"><i class="bi bi-copy" aria-hidden="true"></i> Copy</button></td>
                                    </tr>
                                    <tr v-if="bank.upi_id">
                                        <th scope="row">UPI ID</th>
                                        <td><span class="font-monospace user-select-all">{{ bank.upi_id }}</span>
                                            <button type="button" class="btn btn-link btn-sm py-0" @click="copy(bank.upi_id, 'UPI ID')"><i class="bi bi-copy" aria-hidden="true"></i> Copy</button></td>
                                    </tr>
                                    <tr v-if="bank.payment_deadline">
                                        <th scope="row">Pay by</th>
                                        <td>{{ formatDateTime(bank.payment_deadline) }} <span v-if="deadlinePassed" class="badge text-bg-danger ms-1">Passed</span></td>
                                    </tr>
                                </tbody>
                            </table>
                            <div v-if="bank.instructions" class="mb-3">
                                <div class="fw-semibold small mb-1">Payment instructions</div>
                                <div class="small text-pre">{{ bank.instructions }}</div>
                            </div>
                            <div v-if="bank.reference_instructions" class="alert alert-light border small mb-0">
                                <div class="fw-semibold mb-1"><i class="bi bi-tag me-1" aria-hidden="true"></i>Payment reference</div>
                                <div class="text-pre">{{ bank.reference_instructions }}</div>
                            </div>
                        </div>
                        <div class="col-lg-5 col-xxl-4">
                            <div class="fee-qr-box">
                                <template v-if="bank.has_qr && !qrFailed">
                                    <!-- Links, not buttons: the step's fieldset is disabled when the form is read-only. -->
                                    <a href="#" role="button" class="fee-qr-button" title="Enlarge the QR code" @click.prevent="qrOpen = true">
                                        <img :src="qrUrl" alt="Bank QR code for paying the application fee" class="fee-qr-img" @error="qrFailed = true">
                                    </a>
                                    <div class="small text-muted mt-2">Scan with any UPI / banking app on your phone.</div>
                                    <div class="d-flex flex-wrap justify-content-center gap-2 mt-2">
                                        <a href="#" role="button" class="btn btn-sm btn-outline-secondary" @click.prevent="qrOpen = true"><i class="bi bi-arrows-fullscreen me-1"></i>Enlarge</a>
                                        <a :href="`${qrUrl}&download=1`" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download me-1"></i>Download</a>
                                    </div>
                                </template>
                                <div v-else class="text-muted small py-4">
                                    <i class="bi bi-qr-code fs-2 d-block mb-2" aria-hidden="true"></i>
                                    {{ qrFailed ? 'The QR code could not be loaded.' : 'No QR code is provided for this advertisement.' }}
                                    Pay using the bank details shown.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="card-body">
                    <div v-if="ad.payment_instructions" class="small text-pre">{{ ad.payment_instructions }}</div>
                    <div v-else class="alert alert-info small mb-0"><i class="bi bi-info-circle me-1"></i>The bank details for this advertisement have not been published yet. Please check again later or contact the Recruitment Section.</div>
                </div>
            </section>

            <div class="card section-card">
                <div class="card-header d-flex flex-wrap align-items-center gap-2">
                    <span>Details of the payment you made</span>
                    <span v-if="recorded" class="ms-auto small">Status: <StatusBadge :value="form.status" /></span>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning small py-2">
                        <i class="bi bi-info-circle me-1"></i>
                        Entering the payment details here does <strong>not</strong> mean your payment has been verified.
                        The Recruitment Section will verify the payment against the Institute's bank records after you submit.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6 col-xl-4">
                            <label class="form-label required" for="pay-method">Mode of payment</label>
                            <select id="pay-method" v-model="form.method" class="form-select" :class="inv('method')">
                                <option :value="null" disabled>Select…</option>
                                <option v-for="m in methods" :key="m" :value="m">{{ PAYMENT_METHOD_LABELS[m] ?? m }}</option>
                            </select>
                            <FieldError :errors="errors" name="method" />
                        </div>
                        <div class="col-md-6 col-xl-4">
                            <label class="form-label required" for="pay-utr">{{ refLabel }}</label>
                            <input id="pay-utr" v-model.trim="form.utr_number" class="form-control text-uppercase" :class="inv('utr_number')" maxlength="30" autocomplete="off">
                            <FieldError :errors="errors" name="utr_number" />
                        </div>
                        <div class="col-md-4 col-xl-4">
                            <label class="form-label required" for="pay-date">Date of payment</label>
                            <input id="pay-date" v-model="form.transaction_date" type="date" class="form-control" :class="inv('transaction_date')" :max="today()">
                            <FieldError :errors="errors" name="transaction_date" />
                        </div>
                        <div class="col-md-4 col-xl-4">
                            <label class="form-label required" for="pay-amount">Amount paid (₹)</label>
                            <input id="pay-amount" v-model.number="form.amount" type="number" min="0" step="0.01" class="form-control" :class="inv('amount')" aria-describedby="pay-amount-help">
                            <FieldError :errors="errors" name="amount" />
                            <div id="pay-amount-help" class="form-text">Fee payable: {{ formatMoney(fee.amount) }}</div>
                        </div>
                        <div class="col-md-4 col-xl-8">
                            <label class="form-label required" for="pay-bank">Name of the bank you paid from</label>
                            <input id="pay-bank" v-model="form.bank_name" class="form-control" :class="inv('bank_name')" maxlength="200">
                            <FieldError :errors="errors" name="bank_name" />
                        </div>
                    </div>
                    <div v-if="mismatch" class="alert alert-info small py-2 mt-3 mb-0" role="status">
                        <i class="bi bi-exclamation-circle me-1"></i>The amount entered ({{ formatMoney(form.amount) }}) is different from the fee payable ({{ formatMoney(fee.amount) }}).
                        Check it before saving; any difference is flagged to the Recruitment Section for verification.
                    </div>
                </div>
            </div>
        </template>
        <StepUploads step="payment" />

        <BaseModal v-if="qrOpen" title="Bank QR code" size="lg" @close="qrOpen = false">
            <div class="text-center">
                <img :src="qrUrl" alt="Bank QR code for paying the application fee (enlarged)" class="fee-qr-large">
                <p class="small text-muted mt-2 mb-0">Amount to pay: <strong>{{ formatMoney(fee.amount) }}</strong><span v-if="bank?.account_holder"> · Payee: {{ bank.account_holder }}</span></p>
            </div>
            <template #footer>
                <a :href="`${qrUrl}&download=1`" class="btn btn-outline-secondary"><i class="bi bi-download me-1"></i>Download</a>
                <button type="button" class="btn btn-primary" @click="qrOpen = false">Close</button>
            </template>
        </BaseModal>
    </div>
</template>

<style scoped>
.fee-qr-box { height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 1rem; border: 1px dashed var(--line); border-radius: 0.625rem; background: var(--surface-sunken); }
.fee-qr-button { display: inline-block; border: 0; padding: 0.5rem; background: #fff; border-radius: 0.5rem; box-shadow: 0 0 0 1px var(--line); cursor: zoom-in; }
/* Never stretched or cropped: scaled down proportionally only, on a white background for scanning in dark mode. */
.fee-qr-img { display: block; width: auto; height: auto; max-width: min(100%, 15rem); max-height: 15rem; object-fit: contain; }
.fee-qr-large { width: auto; height: auto; max-width: 100%; max-height: 70vh; object-fit: contain; background: #fff; padding: 0.75rem; border-radius: 0.5rem; }
</style>
