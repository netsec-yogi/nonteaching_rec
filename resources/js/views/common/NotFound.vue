<script setup lang="ts">
import { useAuth } from '@/stores/auth';
const auth = useAuth();
</script>

<template>
    <div class="row justify-content-center py-5">
        <div class="col-md-8 col-lg-6 text-center">
            <i class="bi bi-signpost-split fs-1 text-muted d-block mb-3" aria-hidden="true"></i>
            <h1 class="page-title h3">Page not found</h1>
            <p class="text-muted">The page you are looking for does not exist or may have been moved. Check the address, or use one of the links below.</p>
            <div class="d-flex flex-wrap justify-content-center gap-2">
                <router-link to="/" class="btn btn-primary">View advertisements</router-link>
                <router-link v-if="auth.user" :to="auth.homeRoute()" class="btn btn-outline-primary">{{ auth.isStaff ? 'Staff console' : 'My applications' }}</router-link>
                <router-link v-else to="/login" class="btn btn-outline-primary">Sign in</router-link>
            </div>
        </div>
    </div>
</template>
