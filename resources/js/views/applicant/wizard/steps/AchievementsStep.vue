<script setup lang="ts">
/** Section 31 — Any other achievement not covered above. */
import FieldError from '@/components/FieldError.vue';
import { useWizard } from '../context';
import StepUploads from '../StepUploads.vue';
import type { AchievementsData } from '../types';

defineProps<{ form: AchievementsData }>();
const { errors } = useWizard();
</script>

<template>
    <div class="vstack gap-3">
        <div class="card section-card">
            <div class="card-header">31. Any other achievement</div>
            <div class="card-body">
                <label class="form-label" for="ach">Any other achievement / information not covered above which you wish to bring to the notice of the Institute</label>
                <textarea id="ach" v-model="form.other_achievements" rows="10" class="form-control" :class="{ 'is-invalid': errors.other_achievements }" maxlength="20000"></textarea>
                <div class="form-text">Optional. Leave blank if you have nothing to add, but save this step to confirm you have reviewed it.</div>
                <FieldError :errors="errors" name="other_achievements" />
            </div>
        </div>
        <StepUploads step="achievements" />
    </div>
</template>
