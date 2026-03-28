<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { LayoutGrid, LayoutDashboard, MessageSquare, Settings, Globe, BookOpen } from 'lucide-vue-next';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard, settings } from '@/routes';
import type { NavItem } from '@/types';

const dashboardItem: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard.url(),
        icon: LayoutGrid,
    },
];

const operationsItems: NavItem[] = [
    {
        title: 'Vantage Hub',
        href: '/vantage',
        icon: LayoutDashboard,
    },
    {
        title: 'Archivist',
        href: '/help',
        icon: MessageSquare,
    },
];

const settingsItem: NavItem[] = [
    {
        title: 'Settings',
        href: settings.url(),
        icon: Settings,
    },
];

const officialLinks: NavItem[] = [
    {
        title: 'Website',
        href: 'https://arkhein.melasistema.com',
        icon: Globe,
    },
    {
        title: 'Documentation',
        href: 'https://docs.arkhein.melasistema.com',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset" class="gap-4">
        <SidebarHeader class="pb-6">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard.url()">
                            <AppLogo className="min-w-0" />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="gap-4">
            <NavMain :items="dashboardItem" />
            <NavMain :items="operationsItems" title="Operations" />
        </SidebarContent>

        <SidebarFooter class="gap-2">
            <NavMain :items="settingsItem" title="Arkhein" />
            <NavMain :items="officialLinks" />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
