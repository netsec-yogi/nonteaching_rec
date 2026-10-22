<script setup lang="ts">
/** Pagination in the hostel style: summary on the left, compact page controls on the right. */
defineProps<{ page: number; lastPage: number; total?: number }>();
const emit = defineEmits<{ (e: 'change', page: number): void }>();
</script>

<template>
    <nav v-if="lastPage > 1 || (total ?? 0) > 0" class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3" style="font-size: .78rem" aria-label="Pagination">
        <span class="text-muted">
            <template v-if="total !== undefined"><span class="fw-medium text-body">{{ total.toLocaleString('en-IN') }}</span> records · </template>page <span class="fw-medium text-body">{{ page }}</span> of {{ Math.max(lastPage, 1) }}
        </span>
        <ul v-if="lastPage > 1" class="pagination pagination-sm mb-0">
            <li class="page-item" :class="{ disabled: page <= 1 }"><button class="page-link" aria-label="Previous page" @click="emit('change', page - 1)"><i class="bi bi-chevron-left"></i></button></li>
            <li v-for="p in [page - 1, page, page + 1].filter((n) => n >= 1 && n <= lastPage)" :key="p" class="page-item" :class="{ active: p === page }">
                <button class="page-link" :aria-current="p === page ? 'page' : undefined" @click="emit('change', p)">{{ p }}</button>
            </li>
            <li class="page-item" :class="{ disabled: page >= lastPage }"><button class="page-link" aria-label="Next page" @click="emit('change', page + 1)"><i class="bi bi-chevron-right"></i></button></li>
        </ul>
    </nav>
</template>
