<script setup lang="ts">
/**
 * Referee pages: institute identity only, no portal navigation. Same brand
 * strip as the application shell so the email link lands somewhere that is
 * recognisably the Institute.
 */
</script>

<template>
    <div class="d-flex flex-column min-vh-100">
        <a href="#main" class="visually-hidden-focusable position-fixed top-0 start-0 m-2 btn btn-secondary" style="z-index: 1070">Skip to content</a>
        <header class="shell-brand" style="height: 3.75rem">
            <div class="container d-flex align-items-center gap-2" style="max-width: 920px">
                <span class="shell-brand-mark"><img src="/images/institute-logo.svg" alt="ABV-IIITM Gwalior"></span>
                <span class="min-w-0">
                    <span class="shell-brand-name d-block text-truncate">ABV-Indian Institute of Information Technology and Management, Gwalior</span>
                    <span class="shell-brand-sub d-block">Confidential referee recommendation</span>
                </span>
            </div>
        </header>
        <main id="main" tabindex="-1" class="flex-grow-1 py-4 animate-fade-up"><div class="container" style="max-width: 920px"><router-view /></div></main>
        <footer class="public-footer py-3 text-center">ABV-IIITM Gwalior · Morena Link Road, Gwalior – 474015</footer>
    </div>
</template>
