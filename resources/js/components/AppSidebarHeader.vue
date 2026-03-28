<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';
import { ref, onMounted, onUnmounted } from 'vue';
import { Activity, RefreshCw } from 'lucide-vue-next';
import axios from 'axios';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const heartbeat = ref<any>(null);
const pollInterval = ref<any>(null);

const fetchHeartbeat = async () => {
    try {
        const res = await axios.get('/system/heartbeat');
        heartbeat.value = res.data;
    } catch (e) {
        // Silent fail
    }
};

onMounted(() => {
    fetchHeartbeat();
    pollInterval.value = setInterval(fetchHeartbeat, 5000);
});

onUnmounted(() => {
    if (pollInterval.value) clearInterval(pollInterval.value);
});
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>

        <!-- Global System Heartbeat -->
        <div v-if="heartbeat?.is_busy" class="flex items-center gap-3 animate-in fade-in zoom-in-95 duration-500">
            <div class="flex flex-col items-end">
                <span class="text-[9px] font-black uppercase tracking-widest text-primary flex items-center gap-1.5">
                    <Activity class="h-2.5 w-2.5 animate-pulse" />
                    System Heartbeat
                </span>
                <span class="text-[8px] opacity-50 font-bold truncate max-w-[150px]">
                    {{ heartbeat.details.status_text }}
                </span>
            </div>
            <div class="relative h-8 w-8 flex items-center justify-center">
                <RefreshCw class="h-4 w-4 text-primary animate-spin opacity-30" />
                <div v-if="heartbeat.is_reconciling" class="absolute inset-0 flex items-center justify-center">
                    <span class="text-[7px] font-black">{{ heartbeat.reconcile_progress }}%</span>
                </div>
            </div>
        </div>
    </header>
</template>
