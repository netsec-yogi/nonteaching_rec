<script setup lang="ts">
/**
 * Filter panel shared by the application list and reports. Edits a local
 * copy and emits `apply` with the new values so the parent controls when
 * the server is queried.
 */
import { computed, reactive, ref, watch } from 'vue';
import { humanize } from '@/utils/format';
import { CATEGORIES, GENDERS, STATUS_LABELS, STATUS_OPTIONS, emptyFilters, type AdOption, type CycleOption, type Filters } from './options';

const props = withDefaults(defineProps<{ modelValue: Filters; ads: AdOption[]; cycles: CycleOption[]; showSearch?: boolean; idPrefix?: string }>(), { showSearch: true, idPrefix: 'flt' });
const emit = defineEmits<{ (e: 'apply', value: Filters): void }>();

const f = reactive<Filters>({ ...emptyFilters(), ...props.modelValue });
watch(() => props.modelValue, (v) => Object.assign(f, emptyFilters(), v), { deep: true });

const advanced = ref(Object.keys(STATUS_OPTIONS).some((k) => !!props.modelValue[k]) || !!props.modelValue.cycle_id || !!props.modelValue.department || !!props.modelValue.reference_no);

const visibleAds = computed(() => (f.cycle_id ? props.ads.filter((a) => String(a.recruitment_cycle_id) === f.cycle_id) : props.ads));
const posts = computed(() => {
    const ads = f.advertisement_id ? props.ads.filter((a) => String(a.id) === f.advertisement_id) : visibleAds.value;
    return ads.flatMap((a) => a.posts.map((p) => ({ ...p, adNumber: a.number })));
});
const departments = computed(() => [...new Set(posts.value.map((p) => p.department).filter((d): d is string => !!d))].sort());

watch(() => f.advertisement_id, () => {
    if (f.post_id && !posts.value.some((p) => String(p.id) === f.post_id)) f.post_id = '';
});

const id = (k: string) => `${props.idPrefix}-${k}`;
const apply = () => emit('apply', { ...f });
function reset() {
    Object.assign(f, emptyFilters());
    apply();
}
</script>

<template>
    <form class="card mb-3" @submit.prevent="apply">
        <div class="card-body pb-2">
            <div class="row g-2">
                <div v-if="showSearch" class="col-md-4">
                    <label class="form-label small mb-1" :for="id('q')">Search</label>
                    <input :id="id('q')" v-model.trim="f.q" class="form-control form-control-sm" type="search" placeholder="Name, reference no., email or mobile">
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1" :for="id('ad')">Advertisement</label>
                    <select :id="id('ad')" v-model="f.advertisement_id" class="form-select form-select-sm">
                        <option value="">All advertisements</option>
                        <option v-for="a in visibleAds" :key="a.id" :value="String(a.id)">{{ a.number }} — {{ a.title }}</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1" :for="id('post')">Post</label>
                    <select :id="id('post')" v-model="f.post_id" class="form-select form-select-sm">
                        <option value="">All posts</option>
                        <option v-for="p in posts" :key="p.id" :value="String(p.id)">{{ p.code }} — {{ p.name }}<template v-if="!f.advertisement_id"> ({{ p.adNumber }})</template></option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" :for="id('cat')">Category</label>
                    <select :id="id('cat')" v-model="f.category" class="form-select form-select-sm">
                        <option value="">Any</option>
                        <option v-for="(label, k) in CATEGORIES" :key="k" :value="k">{{ label }}</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" :for="id('gender')">Gender</label>
                    <select :id="id('gender')" v-model="f.gender" class="form-select form-select-sm">
                        <option value="">Any</option>
                        <option v-for="(label, k) in GENDERS" :key="k" :value="k">{{ label }}</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" :for="id('from')">Submitted from</label>
                    <input :id="id('from')" v-model="f.date_from" class="form-control form-control-sm" type="date">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" :for="id('to')">Submitted to</label>
                    <input :id="id('to')" v-model="f.date_to" class="form-control form-control-sm" type="date" :min="f.date_from || undefined">
                </div>
                <div class="col-md-4 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Apply</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" @click="reset">Clear</button>
                    <button type="button" class="btn btn-link btn-sm ms-auto" :aria-expanded="advanced" @click="advanced = !advanced">
                        {{ advanced ? 'Fewer filters' : 'More filters' }} <i class="bi" :class="advanced ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                    </button>
                </div>
            </div>
            <div v-if="advanced" class="row g-2 mt-1 pt-2 border-top">
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1" :for="id('cycle')">Recruitment cycle</label>
                    <select :id="id('cycle')" v-model="f.cycle_id" class="form-select form-select-sm">
                        <option value="">All cycles</option>
                        <option v-for="c in cycles" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1" :for="id('dept')">Department</label>
                    <select :id="id('dept')" v-model="f.department" class="form-select form-select-sm">
                        <option value="">All departments</option>
                        <option v-for="d in departments" :key="d" :value="d">{{ d }}</option>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1" :for="id('ref')">Reference no.</label>
                    <input :id="id('ref')" v-model.trim="f.reference_no" class="form-control form-control-sm" placeholder="e.g. 26-03/00012">
                </div>
                <div class="col-6 col-md-3 d-flex align-items-end">
                    <div class="form-check mb-1">
                        <input :id="id('sub')" class="form-check-input" type="checkbox" :checked="f.submitted_only === '1'" @change="f.submitted_only = ($event.target as HTMLInputElement).checked ? '1' : ''">
                        <label class="form-check-label small" :for="id('sub')">Submitted applications only</label>
                    </div>
                </div>
                <div v-for="(values, key) in STATUS_OPTIONS" :key="key" class="col-6 col-md-3">
                    <label class="form-label small mb-1" :for="id(key)">{{ STATUS_LABELS[key] }}</label>
                    <select :id="id(key)" v-model="f[key]" class="form-select form-select-sm">
                        <option value="">Any</option>
                        <option v-for="v in values" :key="v" :value="v">{{ humanize(v) }}</option>
                    </select>
                </div>
            </div>
        </div>
    </form>
</template>
