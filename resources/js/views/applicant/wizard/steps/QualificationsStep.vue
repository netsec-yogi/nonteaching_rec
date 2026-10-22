<script setup lang="ts">
/** Section 18 — Educational / Professional / Technical qualifications, from Class 10 onwards. */
import { computed } from 'vue';
import FieldError from '@/components/FieldError.vue';
import { errorsUnder, rowKey, useWizard } from '../context';
import RowUpload from '../RowUpload.vue';
import StepUploads from '../StepUploads.vue';
import type { QualificationRow, QualificationsData } from '../types';

const props = defineProps<{ form: QualificationsData }>();
const { payload, errors, readonly } = useWizard();
const levels = computed(() => payload.value.options.qualification_levels);
const thisYear = new Date().getFullYear();
const hasClass10 = computed(() => props.form.rows.some((r) => r.level === 'class_10'));

const blank = (level: string | null = null): QualificationRow => ({
    id: null, level, examination: '', board_university: '', duration: '', year_of_passing: null,
    division: '', percentage: null, subjects: '', specialization: '',
});

function add() {
    props.form.rows.push(blank(props.form.rows.length ? null : 'class_10'));
}
function remove(i: number) {
    const r = props.form.rows[i];
    if (r.id && !confirm('Remove this qualification? Documents attached to it will be detached when you save.')) return;
    props.form.rows.splice(i, 1);
}
function move(i: number, d: -1 | 1) {
    const rows = props.form.rows;
    const j = i + d;
    if (j < 0 || j >= rows.length) return;
    [rows[i], rows[j]] = [rows[j], rows[i]];
}
const k = (i: number, f: string) => `rows.${i}.${f}`;
const inv = (i: number, f: string) => ({ 'is-invalid': !!errors[k(i, f)] });
const rowLabel = (r: QualificationRow) => r.examination || (r.level ? levels.value[r.level] : 'New qualification');
</script>

<template>
    <div class="vstack gap-3">
        <div class="card section-card">
            <div class="card-header">18. Educational / Professional / Technical qualifications</div>
            <div class="card-body">
                <p class="small text-muted mb-2">
                    Give details of all examinations passed, <strong>starting from Class 10 / Matriculation</strong>, in the order you
                    passed them. Attach the certificate and mark sheets against each row after saving.
                </p>
                <div v-if="!hasClass10" class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1"></i>Class 10 / Matriculation must be included.</div>
                <FieldError :errors="errors" name="rows" />

                <div v-for="(row, i) in form.rows" :key="rowKey(row)" class="border rounded p-3 mb-3" :class="{ 'border-danger': errorsUnder(errors, `rows.${i}.`).length }">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge text-bg-secondary">{{ i + 1 }}</span>
                        <span class="fw-semibold flex-grow-1 text-truncate">{{ rowLabel(row) }}</span>
                        <template v-if="!readonly">
                            <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="i === 0" title="Move up" @click="move(i, -1)"><i class="bi bi-arrow-up"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="i === form.rows.length - 1" title="Move down" @click="move(i, 1)"><i class="bi bi-arrow-down"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-danger" title="Remove" @click="remove(i)"><i class="bi bi-trash"></i><span class="visually-hidden">Remove</span></button>
                        </template>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small required" :for="`q${i}-level`">Level</label>
                            <select :id="`q${i}-level`" v-model="row.level" class="form-select form-select-sm" :class="inv(i, 'level')">
                                <option :value="null" disabled>Select…</option>
                                <option v-for="(label, v) in levels" :key="v" :value="v">{{ label }}</option>
                            </select>
                            <FieldError :errors="errors" :name="k(i, 'level')" />
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small required" :for="`q${i}-exam`">Examination passed</label>
                            <input :id="`q${i}-exam`" v-model="row.examination" class="form-control form-control-sm" :class="inv(i, 'examination')" maxlength="200" placeholder="e.g. High School, B.Tech (CSE)">
                            <FieldError :errors="errors" :name="k(i, 'examination')" />
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small required" :for="`q${i}-board`">Board / University</label>
                            <input :id="`q${i}-board`" v-model="row.board_university" class="form-control form-control-sm" :class="inv(i, 'board_university')" maxlength="250">
                            <FieldError :errors="errors" :name="k(i, 'board_university')" />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small" :for="`q${i}-dur`">Duration of course</label>
                            <input :id="`q${i}-dur`" v-model="row.duration" class="form-control form-control-sm" :class="inv(i, 'duration')" maxlength="60" placeholder="e.g. 4 years">
                            <FieldError :errors="errors" :name="k(i, 'duration')" />
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small required" :for="`q${i}-year`">Year of passing</label>
                            <input :id="`q${i}-year`" v-model.number="row.year_of_passing" type="number" min="1950" :max="thisYear" class="form-control form-control-sm" :class="inv(i, 'year_of_passing')">
                            <FieldError :errors="errors" :name="k(i, 'year_of_passing')" />
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small" :for="`q${i}-div`">Division / Class</label>
                            <input :id="`q${i}-div`" v-model="row.division" class="form-control form-control-sm" :class="inv(i, 'division')" maxlength="30">
                            <FieldError :errors="errors" :name="k(i, 'division')" />
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small" :for="`q${i}-pct`">% of marks / CGPA %</label>
                            <input :id="`q${i}-pct`" v-model.number="row.percentage" type="number" min="0" max="100" step="0.01" class="form-control form-control-sm" :class="inv(i, 'percentage')">
                            <FieldError :errors="errors" :name="k(i, 'percentage')" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small" :for="`q${i}-sub`">Subjects</label>
                            <input :id="`q${i}-sub`" v-model="row.subjects" class="form-control form-control-sm" :class="inv(i, 'subjects')" maxlength="500">
                            <FieldError :errors="errors" :name="k(i, 'subjects')" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small" :for="`q${i}-spec`">Specialization</label>
                            <input :id="`q${i}-spec`" v-model="row.specialization" class="form-control form-control-sm" :class="inv(i, 'specialization')" maxlength="250">
                            <FieldError :errors="errors" :name="k(i, 'specialization')" />
                        </div>
                    </div>
                    <div class="mt-2"><RowUpload step="qualifications" :row-id="row.id" /></div>
                </div>

                <p v-if="!form.rows.length" class="text-muted">No qualifications added yet.</p>
                <button v-if="!readonly" type="button" class="btn btn-outline-primary btn-sm" @click="add"><i class="bi bi-plus-lg me-1"></i>Add qualification</button>
            </div>
        </div>
        <StepUploads step="qualifications" />
    </div>
</template>
