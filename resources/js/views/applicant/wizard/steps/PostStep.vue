<script setup lang="ts">
/** Sections 2–4 — Advertisement number, date and post applied for. */
import { computed } from 'vue';
import FieldError from '@/components/FieldError.vue';
import { formatDate } from '@/utils/format';
import { useWizard } from '../context';
import StepUploads from '../StepUploads.vue';
import type { PostData } from '../types';

defineProps<{ form: PostData }>();
const { payload, errors } = useWizard();
const app = computed(() => payload.value.application);
const canChange = computed(() => app.value.status === 'draft');
</script>

<template>
    <div class="vstack gap-3">
        <div class="card section-card">
            <div class="card-header">Sections 2–4 · Advertisement and post</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="post-ad-no">2. Advertisement No.</label>
                        <input id="post-ad-no" class="form-control" :value="form.advertisement_number" readonly>
                        <div class="form-text">{{ app.advertisement.title }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="post-app-date">3. Date of application</label>
                        <input id="post-app-date" class="form-control" :value="formatDate(form.application_date)" readonly>
                        <div class="form-text">Recorded automatically on final submission.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label required" for="post-id">4. Post applied for</label>
                        <select id="post-id" v-model.number="form.post_id" class="form-select" :class="{ 'is-invalid': errors.post_id }" :disabled="!canChange">
                            <option v-if="!payload.options.posts.some((p) => p.id === form.post_id)" :value="form.post_id">{{ form.post_name }}</option>
                            <option v-for="p in payload.options.posts" :key="p.id" :value="p.id">{{ p.name }} ({{ p.code }})</option>
                        </select>
                        <FieldError :errors="errors" name="post_id" />
                        <div class="form-text">
                            <template v-if="canChange">You may change the post only while the application is a draft. Fee and document requirements may change with the post.</template>
                            <template v-else>The post cannot be changed after first submission.</template>
                        </div>
                    </div>
                </div>
                <table class="table table-sm kv-table mt-3 mb-0">
                    <tbody>
                        <tr><th>Department</th><td>{{ app.post.department ?? '—' }}</td></tr>
                        <tr><th>Pay level / scale</th><td>{{ app.post.pay_level ?? '—' }}<span v-if="app.post.pay_scale"> · {{ app.post.pay_scale }}</span></td></tr>
                    </tbody>
                </table>
                <details v-if="app.advertisement.instructions" class="mt-3 small">
                    <summary class="fw-semibold">Instructions to candidates</summary>
                    <div class="text-pre mt-2">{{ app.advertisement.instructions }}</div>
                </details>
            </div>
        </div>
        <StepUploads step="post" />
    </div>
</template>
