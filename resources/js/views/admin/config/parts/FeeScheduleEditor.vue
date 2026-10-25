<script setup lang="ts">
/**
 * Default fee plus category exemptions (category code → amount payable).
 * Emits a `{ default, exemptions }` object, or null when `optional` and switched off.
 */
import { computed, ref, watch } from 'vue';
import { FEE_CATEGORIES, type FeeSchedule } from './types';

const props = defineProps<{ modelValue: FeeSchedule | null; optional?: boolean; errors?: Record<string, string[]>; prefix?: string }>();
const emit = defineEmits<{ (e: 'update:modelValue', v: FeeSchedule | null): void }>();

interface Row { key: string; amount: string | number }
const enabled = ref(!!props.modelValue);
const amount = ref<string | number>(props.modelValue?.default ?? '');
const rows = ref<Row[]>(Object.entries(props.modelValue?.exemptions ?? {}).map(([key, a]) => ({ key, amount: a })));
const unused = computed(() => FEE_CATEGORIES.filter((c) => !rows.value.some((r) => r.key.toUpperCase() === c)));
const err = (k: string) => props.errors?.[`${props.prefix ?? 'fee_schedule'}.${k}`]?.[0];

watch([enabled, amount, rows], () => {
    if (props.optional && !enabled.value) { emit('update:modelValue', null); return; }
    const exemptions: Record<string, string | number> = {};
    rows.value.forEach((r) => { if (r.key.trim()) exemptions[r.key.trim().toUpperCase()] = r.amount; });
    emit('update:modelValue', { default: amount.value, exemptions });
}, { deep: true });

function add(key = '') { rows.value.push({ key, amount: 0 }); }
</script>

<template>
    <div>
        <div v-if="optional" class="form-check form-switch mb-2">
            <input :id="`fee-on-${prefix}`" v-model="enabled" class="form-check-input" type="checkbox">
            <label class="form-check-label" :for="`fee-on-${prefix}`">Override the advertisement's fee schedule for this post</label>
        </div>
        <template v-if="!optional || enabled">
            <div class="row g-2 align-items-end mb-2">
                <div class="col-sm-4">
                    <label class="form-label small mb-1 required" :for="`fee-default-${prefix}`">Default fee (₹)</label>
                    <input :id="`fee-default-${prefix}`" v-model="amount" type="number" min="0" step="1" class="form-control form-control-sm" :class="{ 'is-invalid': err('default') }">
                    <div v-if="err('default')" class="invalid-feedback">{{ err('default') }}</div>
                </div>
                <div class="col-sm-8 small text-muted">Applies to every candidate unless an exemption below matches their category, gender, disability or ex-serviceman status. The lowest matching amount is charged.</div>
            </div>
            <table class="table table-sm mb-1">
                <thead class="table-light"><tr><th style="width: 45%">Category / condition</th><th>Amount payable (₹)</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="(r, i) in rows" :key="i">
                        <td><input v-model="r.key" aria-label="Category or condition" class="form-control form-control-sm text-uppercase" maxlength="20" placeholder="e.g. SC" list="fee-categories"></td>
                        <td>
                            <input v-model="r.amount" :aria-label="`Amount payable for ${r.key || 'this category'} (₹)`" type="number" min="0" class="form-control form-control-sm" :class="{ 'is-invalid': err(`exemptions.${r.key}`) }">
                        </td>
                        <td class="text-end"><button type="button" class="btn btn-sm btn-link text-danger" @click="rows.splice(i, 1)"><i class="bi bi-x-lg"></i><span class="visually-hidden">Remove</span></button></td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="3" class="small text-muted">No exemptions — every candidate pays the default fee.</td></tr>
                </tbody>
            </table>
            <datalist id="fee-categories"><option v-for="c in FEE_CATEGORIES" :key="c" :value="c" /></datalist>
            <div class="d-flex flex-wrap gap-1 align-items-center">
                <span class="small text-muted me-1">Add exemption:</span>
                <button v-for="c in unused" :key="c" type="button" class="btn btn-sm btn-outline-secondary py-0" @click="add(c)">{{ c }}</button>
                <button type="button" class="btn btn-sm btn-outline-primary py-0" @click="add()"><i class="bi bi-plus"></i> Other</button>
            </div>
        </template>
    </div>
</template>
