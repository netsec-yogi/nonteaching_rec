<script setup lang="ts">
/** YES / NO radio pair bound to a boolean (null = unanswered). */
const props = withDefaults(defineProps<{
    modelValue: boolean | null | undefined;
    name: string;
    disabled?: boolean;
    invalid?: boolean;
    yesLabel?: string;
    noLabel?: string;
}>(), { disabled: false, invalid: false, yesLabel: 'YES', noLabel: 'NO' });
const emit = defineEmits<{ (e: 'update:modelValue', value: boolean): void }>();
const id = (v: string) => `${props.name}-${v}`.replace(/[^\w-]/g, '_');
</script>

<template>
    <div class="d-flex gap-4" role="radiogroup">
        <div class="form-check">
            <input :id="id('yes')" class="form-check-input" :class="{ 'is-invalid': invalid }" type="radio" :name="name"
                :checked="modelValue === true" :disabled="disabled" @change="emit('update:modelValue', true)">
            <label class="form-check-label fw-semibold" :for="id('yes')">{{ yesLabel }}</label>
        </div>
        <div class="form-check">
            <input :id="id('no')" class="form-check-input" :class="{ 'is-invalid': invalid }" type="radio" :name="name"
                :checked="modelValue === false" :disabled="disabled" @change="emit('update:modelValue', false)">
            <label class="form-check-label fw-semibold" :for="id('no')">{{ noLabel }}</label>
        </div>
    </div>
</template>
