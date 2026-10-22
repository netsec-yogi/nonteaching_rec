<script setup lang="ts">
/** Password field with a show/hide toggle. Use v-model. */
import { ref } from 'vue';

withDefaults(defineProps<{
    id: string;
    autocomplete?: string;
    invalid?: boolean;
    required?: boolean;
    describedby?: string;
}>(), { autocomplete: 'current-password', invalid: false, required: true });

const model = defineModel<string>({ required: true });
const visible = ref(false);
</script>

<template>
    <div class="input-group" :class="{ 'has-validation': invalid }">
        <input
            :id="id"
            v-model="model"
            :type="visible ? 'text' : 'password'"
            class="form-control"
            :class="{ 'is-invalid': invalid }"
            :autocomplete="autocomplete"
            :required="required"
            :aria-invalid="invalid || undefined"
            :aria-describedby="describedby"
            spellcheck="false"
            autocapitalize="off"
        >
        <button
            type="button"
            class="btn btn-outline-secondary"
            :aria-pressed="visible"
            :aria-controls="id"
            :title="visible ? 'Hide password' : 'Show password'"
            @click="visible = !visible"
        >
            <i class="bi" :class="visible ? 'bi-eye-slash' : 'bi-eye'" aria-hidden="true"></i>
            <span class="visually-hidden">{{ visible ? 'Hide password' : 'Show password' }}</span>
        </button>
    </div>
</template>
