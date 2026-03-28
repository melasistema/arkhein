<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

defineProps<{
    items: NavItem[];
    title?: string;
}>();

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel v-if="title">{{ title }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="item.href.startsWith('/') ? isCurrentUrl(item.href) : false"
                    :tooltip="item.title"
                >
                    <component 
                        :is="item.href.startsWith('http') ? 'a' : Link" 
                        :href="item.href"
                        v-bind="item.href.startsWith('http') ? { target: '_blank', rel: 'noopener noreferrer' } : {}"
                    >
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </component>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
