<script setup lang="ts">
/** One postal address (used for Sections 15 and 16). */
import FieldError from '@/components/FieldError.vue';
import { useWizard } from '../context';
import type { Address } from '../types';

const props = defineProps<{ address: Address; prefix: 'correspondence' | 'permanent' }>();
const { errors } = useWizard();
const k = (f: string) => `${props.prefix}.${f}`;
const inv = (f: string) => ({ 'is-invalid': !!errors[k(f)] });
const id = (f: string) => `${props.prefix}-${f}`;
</script>

<template>
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label required" :for="id('line1')">House / street / locality</label>
            <input :id="id('line1')" v-model="address.line1" class="form-control" :class="inv('line1')" maxlength="255" autocomplete="address-line1">
            <FieldError :errors="errors" :name="k('line1')" />
        </div>
        <div class="col-12">
            <label class="form-label" :for="id('line2')">Address line 2</label>
            <input :id="id('line2')" v-model="address.line2" class="form-control" :class="inv('line2')" maxlength="255" autocomplete="address-line2">
            <FieldError :errors="errors" :name="k('line2')" />
        </div>
        <div class="col-md-4">
            <label class="form-label required" :for="id('city')">City / town / village</label>
            <input :id="id('city')" v-model="address.city" class="form-control" :class="inv('city')" maxlength="100" autocomplete="address-level2">
            <FieldError :errors="errors" :name="k('city')" />
        </div>
        <div class="col-md-4">
            <label class="form-label" :for="id('district')">District</label>
            <input :id="id('district')" v-model="address.district" class="form-control" :class="inv('district')" maxlength="100">
            <FieldError :errors="errors" :name="k('district')" />
        </div>
        <div class="col-md-4">
            <label class="form-label required" :for="id('state')">State / UT</label>
            <input :id="id('state')" v-model="address.state" class="form-control" :class="inv('state')" maxlength="100" autocomplete="address-level1">
            <FieldError :errors="errors" :name="k('state')" />
        </div>
        <div class="col-md-4">
            <label class="form-label required" :for="id('pincode')">PIN code</label>
            <input :id="id('pincode')" v-model.trim="address.pincode" class="form-control" :class="inv('pincode')" inputmode="numeric" maxlength="6" pattern="[1-9][0-9]{5}" autocomplete="postal-code">
            <FieldError :errors="errors" :name="k('pincode')" />
        </div>
        <div class="col-md-4">
            <label class="form-label" :for="id('country')">Country</label>
            <input :id="id('country')" v-model="address.country" class="form-control" :class="inv('country')" maxlength="60" autocomplete="country-name">
            <FieldError :errors="errors" :name="k('country')" />
        </div>
    </div>
</template>
