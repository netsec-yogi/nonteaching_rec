<script setup lang="ts">
import { computed } from 'vue';
import { useAuth } from '@/stores/auth';
import AppShell, { type ShellSection } from './AppShell.vue';

const auth = useAuth();

interface NavItem { to: string; label: string; icon: string; show: boolean; exact?: boolean }

const groups = computed<ShellSection[]>(() => [
    { heading: 'Recruitment', items: [
        { to: '/admin', label: 'Dashboard', icon: 'house', exact: true, show: auth.can('applications.view') || auth.can('reports.view') },
        { to: '/admin/applications', label: 'Applications', icon: 'folder2', show: auth.isStaff },
        { to: '/scrutiny', label: 'My scrutiny queue', icon: 'clipboard-check', show: auth.hasRole('scrutiny_user', 'recruitment_admin', 'super_admin') },
        { to: '/admin/payments', label: 'Fee verification', icon: 'cash-coin', show: auth.can('applications.verify') },
        { to: '/committee', label: 'Screening Committee', icon: 'people', show: auth.hasRole('committee_member', 'super_admin') },
        { to: '/admin/reports', label: 'Reports & exports', icon: 'bar-chart-line', show: auth.can('reports.view') || auth.can('exports.applications') },
    ] },
    { heading: 'Configuration', items: [
        { to: '/admin/advertisements', label: 'Advertisements', icon: 'megaphone', show: auth.can('advertisements.manage') },
        { to: '/admin/cycles', label: 'Recruitment cycles', icon: 'calendar3', show: auth.can('advertisements.manage') },
        { to: '/admin/checklists', label: 'Checklists', icon: 'list-check', show: auth.can('configuration.manage') },
        { to: '/admin/document-types', label: 'Document types', icon: 'file-earmark-text', show: auth.can('configuration.manage') },
        { to: '/admin/forms/referee', label: 'Referee form', icon: 'envelope-paper', show: auth.can('configuration.manage') },
        { to: '/admin/forms/evaluation', label: 'Committee form', icon: 'ui-checks', show: auth.can('configuration.manage') },
        { to: '/admin/email-templates', label: 'Email templates', icon: 'envelope', show: auth.can('templates.manage') },
        { to: '/admin/settings', label: 'Settings', icon: 'gear', show: auth.can('settings.manage') },
    ] },
    { heading: 'Administration', items: [
        { to: '/admin/fee-settings', label: 'Application fee settings', icon: 'bank', show: auth.can('payments.configure') },
        { to: '/admin/users', label: 'Users', icon: 'person-badge', show: auth.can('users.manage') },
        { to: '/admin/permissions', label: 'Roles & permissions', icon: 'shield-lock', show: auth.can('users.manage') },
        { to: '/admin/logs/emails', label: 'Email delivery log', icon: 'send-check', show: auth.can('email_logs.view') },
        { to: '/admin/logs/audit', label: 'Audit trail', icon: 'journal-text', show: auth.can('audit.view') },
    ] },
].map((g) => ({ title: g.heading, items: g.items.filter((i) => i.show) })).filter((g) => g.items.length));

</script>

<template>
    <AppShell :sections="groups" app-name="Non-Teaching Recruitment" :home-to="auth.homeRoute()">
        <router-view />
    </AppShell>
</template>
