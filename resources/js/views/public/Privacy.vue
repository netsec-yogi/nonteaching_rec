<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { api, toApiError } from '@/api/client';
import LoadingState from '@/components/LoadingState.vue';
import type { PublicSettings } from './parts/types';

const settings = ref<PublicSettings>({});
const loading = ref(true);
const error = ref<string | null>(null);

onMounted(async () => {
    try {
        const { data } = await api.get<PublicSettings>('/public/settings');
        settings.value = data;
    } catch (e) {
        error.value = toApiError(e).message;
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <h1 class="page-title h3 mb-3">Privacy notice</h1>
            <LoadingState :loading="loading" :error="error">
                <section class="card section-card mb-3">
                    <h2 class="card-header h6 mb-0">How your information is used</h2>
                    <div class="card-body">
                        <p v-if="settings['privacy.notice']" class="text-pre mb-0">{{ settings['privacy.notice'] }}</p>
                        <p v-else class="text-muted mb-0">The privacy notice has not been published yet. Please contact the Recruitment Section for details.</p>
                    </div>
                </section>

                <section class="card section-card mb-3">
                    <h2 class="card-header h6 mb-0">What this portal records</h2>
                    <div class="card-body">
                        <ul class="mb-0">
                            <li>The details and documents you enter in your application, and the versions you submit.</li>
                            <li>Referee names and contact details you provide, used only to invite them to send a recommendation.</li>
                            <li>Sign-in activity and changes made to your application, kept for audit purposes.</li>
                            <li>Emails sent to you by the portal about your account and applications.</li>
                        </ul>
                    </div>
                </section>

                <section class="card section-card">
                    <h2 class="card-header h6 mb-0">Contact</h2>
                    <div class="card-body">
                        <p class="mb-2">For questions about your information or this portal, contact the Recruitment Section:</p>
                        <address class="mb-0">
                            <strong>{{ settings['institute.name'] || 'ABV-Indian Institute of Information Technology and Management, Gwalior' }}</strong><br>
                            <span v-if="settings['institute.address']">{{ settings['institute.address'] }}<br></span>
                            <span v-if="settings['institute.recruitment_email']">
                                <i class="bi bi-envelope me-1" aria-hidden="true"></i><a :href="`mailto:${settings['institute.recruitment_email']}`">{{ settings['institute.recruitment_email'] }}</a><br>
                            </span>
                            <span v-if="settings['institute.recruitment_phone']">
                                <i class="bi bi-telephone me-1" aria-hidden="true"></i><a :href="`tel:${settings['institute.recruitment_phone']}`">{{ settings['institute.recruitment_phone'] }}</a>
                            </span>
                        </address>
                    </div>
                </section>
            </LoadingState>
        </div>
    </div>
</template>
