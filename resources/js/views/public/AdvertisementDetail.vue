<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api, toApiError } from '@/api/client';
import { useAuth } from '@/stores/auth';
import LoadingState from '@/components/LoadingState.vue';
import { formatDate, formatDateTime, formatMoney, formatSize } from '@/utils/format';
import { documentUrl, type AdPostDetail, type AdvertisementDetail, type FeeSchedule } from './parts/types';

const props = defineProps<{ id: string }>();
const auth = useAuth();
const router = useRouter();

const ad = ref<AdvertisementDetail | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const appliedPostIds = ref<Set<number>>(new Set());
const applying = ref<number | null>(null);
const applyErrors = reactive<Record<number, string>>({});

const endorsementText: Record<string, string> = {
    not_applicable: 'Not required for this advertisement.',
    optional: 'Optional — in-service candidates may submit an employer endorsement.',
    required_for_in_service: 'Required for candidates who are in Government / PSU / autonomous body service.',
};

const isOpenNow = computed(() => !!ad.value?.is_open);
const notYetOpen = computed(() => !!ad.value && ad.value.status === 'published' && new Date(ad.value.opens_at).getTime() > Date.now());

function exemptions(fee: FeeSchedule): [string, number | string][] {
    const ex = fee.exemptions;
    if (!ex || Array.isArray(ex)) return [];
    return Object.entries(ex);
}

const categoryLabel: Record<string, string> = {
    UR: 'Unreserved (UR)', OBC: 'OBC (NCL)', SC: 'SC', ST: 'ST', EWS: 'EWS', PWD: 'Persons with Benchmark Disabilities',
    PWBD: 'Persons with Benchmark Disabilities', FEMALE: 'Women', ESM: 'Ex-servicemen',
};

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await api.get<AdvertisementDetail>(`/public/advertisements/${props.id}`);
        ad.value = data;
        document.title = `${data.number} — Non-Teaching Recruitment — ABV-IIITM Gwalior`;
    } catch (e) {
        const ae = toApiError(e);
        error.value = ae.status === 404 ? 'This advertisement was not found or is no longer published.' : ae.message;
    } finally {
        loading.value = false;
    }
    if (auth.isApplicant) {
        try {
            const { data } = await api.get<{ open_advertisements: { posts: { id: number; applied: boolean }[] }[] }>('/applicant/dashboard');
            appliedPostIds.value = new Set(data.open_advertisements.flatMap((a) => a.posts.filter((p) => p.applied).map((p) => p.id)));
        } catch {
            // Non-essential: the server still refuses duplicate applications.
        }
    }
}

async function apply(post: AdPostDetail) {
    delete applyErrors[post.id];
    if (!auth.user) {
        router.push({ path: '/login', query: { redirect: `/advertisements/${props.id}` } });
        return;
    }
    applying.value = post.id;
    try {
        const { data } = await api.post<{ uuid: string }>('/applications', { post_id: post.id });
        router.push(`/applications/${data.uuid}/edit/payment`);
    } catch (e) {
        const ae = toApiError(e);
        applyErrors[post.id] = ae.errors.post_id?.[0] ?? ae.message;
    } finally {
        applying.value = null;
    }
}

onMounted(load);
watch(() => props.id, load);
</script>

<template>
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small">
                <li class="breadcrumb-item"><router-link to="/">Advertisements</router-link></li>
                <li class="breadcrumb-item active" aria-current="page">{{ ad?.number ?? 'Advertisement' }}</li>
            </ol>
        </nav>

        <LoadingState :loading="loading" :error="error">
            <template v-if="ad">
                <header class="mb-3">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <span class="text-muted">Advt. No. {{ ad.number }}</span>
                        <span v-if="isOpenNow" class="badge text-bg-success">Open for applications</span>
                        <span v-else-if="notYetOpen" class="badge text-bg-info">Opens {{ formatDateTime(ad.opens_at) }}</span>
                        <span v-else class="badge text-bg-secondary">Closed</span>
                    </div>
                    <h1 class="page-title h3 mb-1">{{ ad.title }}</h1>
                    <div class="text-muted small">
                        <span v-if="ad.department">{{ ad.department }}</span>
                        <span v-if="ad.cycle"> · {{ ad.cycle }}</span>
                    </div>
                </header>

                <div class="row g-3">
                    <div class="col-lg-8 order-2 order-lg-1 vstack gap-3">
                        <p v-if="ad.summary" class="lead fs-6 mb-0">{{ ad.summary }}</p>

                        <section v-if="ad.instructions" class="card section-card">
                            <h2 class="card-header h6 mb-0">General instructions</h2>
                            <div class="card-body text-pre">{{ ad.instructions }}</div>
                        </section>

                        <!-- Posts -->
                        <section aria-labelledby="posts-heading">
                            <h2 id="posts-heading" class="h5 page-title mt-2">Posts advertised</h2>
                            <div class="vstack gap-3">
                                <article v-for="post in ad.posts" :id="`post-${post.id}`" :key="post.id" class="card section-card">
                                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                                        <h3 class="h6 mb-0">{{ post.name }} <span class="text-muted fw-normal small">({{ post.code }})</span></h3>
                                        <span class="badge" :class="post.accepting ? 'text-bg-success' : 'text-bg-secondary'">{{ post.accepting ? 'Accepting applications' : 'Not accepting applications' }}</span>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-sm kv-table mb-3">
                                            <tbody>
                                                <tr v-if="post.department"><th scope="row">Department / Section</th><td>{{ post.department }}</td></tr>
                                                <tr v-if="post.group_classification"><th scope="row">Group</th><td>{{ post.group_classification }}</td></tr>
                                                <tr><th scope="row">Pay level / scale</th><td>{{ post.pay_level ?? '—' }}<span v-if="post.pay_scale"> · {{ post.pay_scale }}</span></td></tr>
                                                <tr>
                                                    <th scope="row">Vacancies</th>
                                                    <td>
                                                        <strong>{{ post.total_vacancies }}</strong>
                                                        <span v-if="post.vacancies.length" class="ms-1">
                                                            (<span v-for="(v, i) in post.vacancies" :key="v.category">{{ i ? ', ' : '' }}{{ v.category }}: {{ v.count }}<span v-if="v.remarks" class="text-muted"> — {{ v.remarks }}</span></span>)
                                                        </span>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <th scope="row">Age limit</th>
                                                    <td>
                                                        <template v-if="post.age_min || post.age_max">
                                                            <span v-if="post.age_min">Minimum {{ post.age_min }} years</span>
                                                            <span v-if="post.age_min && post.age_max">; </span>
                                                            <span v-if="post.age_max">Maximum {{ post.age_max }} years</span>
                                                            <span v-if="post.age_cutoff_date" class="text-muted"> as on {{ formatDate(post.age_cutoff_date) }}</span>
                                                        </template>
                                                        <span v-else>As per rules</span>
                                                        <div v-if="post.age_relaxation" class="small text-pre mt-1">{{ post.age_relaxation }}</div>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <th scope="row">Application fee</th>
                                                    <td>
                                                        {{ formatMoney(post.fee.default ?? 0) }}
                                                        <span v-if="exemptions(post.fee).length" class="small d-block text-muted">
                                                            <span v-for="([cat, amt], i) in exemptions(post.fee)" :key="cat">{{ i ? '; ' : '' }}{{ categoryLabel[cat] ?? cat }}: {{ Number(amt) === 0 ? 'exempted' : formatMoney(amt) }}</span>
                                                        </span>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>

                                        <h4 class="h6 small text-uppercase text-muted">Essential qualifications</h4>
                                        <p class="text-pre">{{ post.essential_qualifications || '—' }}</p>
                                        <template v-if="post.desirable_qualifications">
                                            <h4 class="h6 small text-uppercase text-muted">Desirable qualifications</h4>
                                            <p class="text-pre">{{ post.desirable_qualifications }}</p>
                                        </template>
                                        <template v-if="post.experience_requirements">
                                            <h4 class="h6 small text-uppercase text-muted">Experience</h4>
                                            <p class="text-pre">{{ post.experience_requirements }}</p>
                                        </template>
                                        <template v-if="post.other_conditions">
                                            <h4 class="h6 small text-uppercase text-muted">Other conditions</h4>
                                            <p class="text-pre">{{ post.other_conditions }}</p>
                                        </template>

                                        <div v-if="!auth.isStaff" class="border-top pt-3 d-flex flex-wrap align-items-center gap-2">
                                            <template v-if="post.accepting">
                                                <template v-if="appliedPostIds.has(post.id)">
                                                    <span class="text-success small"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>You have already started an application for this post.</span>
                                                    <router-link to="/dashboard" class="btn btn-sm btn-outline-primary">Go to My Applications</router-link>
                                                </template>
                                                <template v-else>
                                                    <button type="button" class="btn btn-primary" :disabled="applying !== null" @click="apply(post)">
                                                        <span v-if="applying === post.id" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                                        <i v-else class="bi bi-pencil-square me-1" aria-hidden="true"></i>
                                                        Apply for this post
                                                    </button>
                                                    <span v-if="!auth.user" class="small text-muted">
                                                        You will be asked to sign in. New applicant? <router-link to="/register">Register first</router-link>.
                                                    </span>
                                                </template>
                                            </template>
                                            <span v-else class="small text-muted">This post is not accepting applications at present.</span>
                                            <div v-if="applyErrors[post.id]" class="alert alert-danger py-2 px-3 small mb-0 w-100" role="alert">
                                                <i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i>{{ applyErrors[post.id] }}
                                                <router-link v-if="/already/i.test(applyErrors[post.id])" to="/dashboard" class="alert-link ms-1">View My Applications</router-link>
                                            </div>
                                        </div>
                                    </div>
                                </article>
                                <p v-if="!ad.posts.length" class="text-muted">No posts are listed under this advertisement.</p>
                            </div>
                        </section>

                        <section v-if="ad.reservation_details" class="card section-card">
                            <h2 class="card-header h6 mb-0">Reservation</h2>
                            <div class="card-body text-pre">{{ ad.reservation_details }}</div>
                        </section>
                        <section v-if="ad.age_relaxation_rules" class="card section-card">
                            <h2 class="card-header h6 mb-0">Age relaxation</h2>
                            <div class="card-body text-pre">{{ ad.age_relaxation_rules }}</div>
                        </section>
                        <section v-if="ad.fee_rules_text || ad.payment_instructions" class="card section-card">
                            <h2 class="card-header h6 mb-0">Application fee and payment</h2>
                            <div class="card-body">
                                <p v-if="ad.fee_rules_text" class="text-pre">{{ ad.fee_rules_text }}</p>
                                <template v-if="ad.payment_instructions">
                                    <h3 class="h6 small text-uppercase text-muted">How to pay</h3>
                                    <div class="text-pre mb-0">{{ ad.payment_instructions }}</div>
                                </template>
                            </div>
                        </section>
                        <section class="card section-card">
                            <h2 class="card-header h6 mb-0">Referees and employer endorsement</h2>
                            <div class="card-body">
                                <table class="table table-sm kv-table mb-0">
                                    <tbody>
                                        <tr>
                                            <th scope="row">Referees</th>
                                            <td>
                                                <template v-if="ad.referees_required">
                                                    Details of {{ ad.referees_required }} referee{{ ad.referees_required === 1 ? '' : 's' }} are required. After you submit, each referee is invited by email to send a confidential recommendation directly to the Institute.
                                                </template>
                                                <template v-else>Not required.</template>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Employer endorsement</th>
                                            <td>
                                                {{ endorsementText[ad.endorsement_mode] ?? ad.endorsement_mode }}
                                                <div v-if="ad.endorsement_instructions && ad.endorsement_mode !== 'not_applicable'" class="text-pre small mt-1">{{ ad.endorsement_instructions }}</div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </div>

                    <aside class="col-lg-4 order-1 order-lg-2">
                        <div class="card section-card sticky-lg-top" style="top: 1rem">
                            <h2 class="card-header h6 mb-0">Key dates</h2>
                            <ul class="list-group list-group-flush small">
                                <li v-if="ad.publication_date" class="list-group-item d-flex justify-content-between gap-2"><span class="text-muted">Published</span><span>{{ formatDate(ad.publication_date) }}</span></li>
                                <li class="list-group-item d-flex justify-content-between gap-2"><span class="text-muted">Applications open</span><span>{{ formatDateTime(ad.opens_at) }}</span></li>
                                <li class="list-group-item d-flex justify-content-between gap-2"><span class="text-muted">Last date</span><strong>{{ formatDateTime(ad.closes_at) }}</strong></li>
                            </ul>
                            <div v-if="ad.documents.length" class="card-body border-top">
                                <h3 class="h6 small text-uppercase text-muted">Advertisement documents</h3>
                                <ul class="list-unstyled mb-0 small">
                                    <li v-for="d in ad.documents" :key="d.id" class="py-1">
                                        <a :href="documentUrl(ad.id, d.id)" target="_blank" rel="noopener">
                                            <i class="bi bi-file-earmark-pdf text-danger me-1" aria-hidden="true"></i>{{ d.title || d.original_name }}
                                        </a>
                                        <span v-if="d.size" class="text-muted"> ({{ formatSize(d.size) }})</span>
                                    </li>
                                </ul>
                            </div>
                            <div v-if="ad.contact_email" class="card-body border-top small">
                                Queries: <a :href="`mailto:${ad.contact_email}`">{{ ad.contact_email }}</a>
                            </div>
                            <div v-if="!auth.user && isOpenNow" class="card-body border-top">
                                <p class="small mb-2">To apply, register with your email address and mobile number, then sign in.</p>
                                <div class="d-flex gap-2">
                                    <router-link to="/register" class="btn btn-primary btn-sm">Register</router-link>
                                    <router-link :to="{ path: '/login', query: { redirect: `/advertisements/${ad.id}` } }" class="btn btn-outline-primary btn-sm">Sign in</router-link>
                                </div>
                            </div>
                            <div v-else-if="auth.isStaff" class="card-body border-top small text-muted">
                                You are signed in with a staff account. Applications can be made only from an applicant account.
                            </div>
                        </div>
                    </aside>
                </div>
            </template>
        </LoadingState>
    </div>
</template>
