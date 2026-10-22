<script setup lang="ts">
/** Section 1 — Details of Demand Draft / NEFT. */
import { computed } from 'vue';
import FieldError from '@/components/FieldError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { formatMoney } from '@/utils/format';
import { PAYMENT_METHOD_LABELS, today, useWizard } from '../context';
import StepUploads from '../StepUploads.vue';
import type { PaymentData } from '../types';

const props = defineProps<{ form: PaymentData }>();
const { payload, errors } = useWizard();
const fee = computed(() => payload.value.application.fee);
const ad = computed(() => payload.value.application.advertisement);
const exempt = computed(() => Number(fee.value.amount) <= 0);
const methods = computed(() => (ad.value.payment_methods ?? []).filter((m) => m !== 'exempt'));
const inv = (k: string) => ({ 'is-invalid': !!errors[k] });
const recorded = computed(() => props.form.status && props.form.status !== 'exempt');
</script>

<template>
    <div class="vstack gap-3">
        <div class="card section-card">
            <div class="card-header">1. Details of Demand Draft / NEFT</div>
            <div class="card-body">
                <div class="row g-3 align-items-center mb-3">
                    <div class="col-sm-6">
                        <div class="text-muted small">Fee payable for this application</div>
                        <div class="fs-4 fw-bold">{{ exempt ? 'Fee exempted' : formatMoney(fee.amount) }}</div>
                        <div v-if="fee.basis" class="small text-muted">Basis: {{ fee.basis }}</div>
                    </div>
                    <div v-if="ad.fee_rules_text" class="col-sm-6 small text-muted text-pre">{{ ad.fee_rules_text }}</div>
                </div>

                <div v-if="exempt" class="alert alert-success mb-0">
                    <i class="bi bi-check-circle me-1"></i>
                    You are exempted from paying the application fee<span v-if="fee.basis"> ({{ fee.basis }})</span>.
                    Save this step to confirm the exemption. The basis of exemption (category, PwBD status, etc.) will be
                    verified against your certificates during scrutiny.
                </div>

                <template v-else>
                    <div v-if="ad.payment_instructions" class="alert alert-light border small">
                        <div class="fw-semibold mb-1"><i class="bi bi-bank me-1"></i>How to pay</div>
                        <div class="text-pre">{{ ad.payment_instructions }}</div>
                    </div>
                    <div class="alert alert-warning small py-2">
                        <i class="bi bi-info-circle me-1"></i>
                        Entering the payment details here does <strong>not</strong> mean your payment has been verified.
                        The Recruitment Section will verify the payment against the Institute's bank records after you submit.
                        <span v-if="recorded" class="ms-1">Current status: <StatusBadge :value="form.status" /></span>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required" for="pay-method">Mode of payment</label>
                            <select id="pay-method" v-model="form.method" class="form-select" :class="inv('method')">
                                <option :value="null" disabled>Select…</option>
                                <option v-for="m in methods" :key="m" :value="m">{{ PAYMENT_METHOD_LABELS[m] ?? m }}</option>
                            </select>
                            <FieldError :errors="errors" name="method" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required" for="pay-utr">{{ form.method === 'demand_draft' ? 'DD number' : 'NEFT UTR / transaction number' }}</label>
                            <input id="pay-utr" v-model.trim="form.utr_number" class="form-control text-uppercase" :class="inv('utr_number')" maxlength="30" autocomplete="off">
                            <FieldError :errors="errors" name="utr_number" />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required" for="pay-date">Date of NEFT / DD</label>
                            <input id="pay-date" v-model="form.transaction_date" type="date" class="form-control" :class="inv('transaction_date')" :max="today()">
                            <FieldError :errors="errors" name="transaction_date" />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required" for="pay-amount">Amount (₹)</label>
                            <input id="pay-amount" v-model.number="form.amount" type="number" min="0" step="0.01" class="form-control" :class="inv('amount')">
                            <FieldError :errors="errors" name="amount" />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required" for="pay-bank">Name of the bank</label>
                            <input id="pay-bank" v-model="form.bank_name" class="form-control" :class="inv('bank_name')" maxlength="200">
                            <FieldError :errors="errors" name="bank_name" />
                        </div>
                    </div>
                </template>
            </div>
        </div>
        <StepUploads step="payment" />
    </div>
</template>
