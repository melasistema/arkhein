<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { Activity, RefreshCw, HardDrive, BrainCircuit, AlertCircle, ChevronDown, Clock, PenTool } from 'lucide-vue-next';
import axios from 'axios';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

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

// High-intensity state: More than 1 folder indexing or reconciling memory
const isRedBusy = computed(() => {
    if (!heartbeat.value) return false;
    return heartbeat.value.is_reconciling || heartbeat.value.task_count > 1 || heartbeat.value.is_stale;
});

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
        <DropdownMenu v-if="heartbeat?.is_busy">
            <DropdownMenuTrigger as-child>
                <button class="flex items-center gap-3 px-3 py-1.5 rounded-xl transition-all hover:bg-muted group animate-in fade-in zoom-in-95 duration-500 outline-none">
                    <div class="flex flex-col items-end">
                        <span 
                            class="text-[9px] font-black uppercase tracking-widest flex items-center gap-1.5 transition-colors"
                            :class="isRedBusy ? 'text-red-500' : 'text-primary'"
                        >
                            <Activity 
                                class="h-2.5 w-2.5" 
                                :class="isRedBusy ? 'animate-[pulse_1s_infinite]' : 'animate-pulse'" 
                            />
                            {{ isRedBusy ? 'System Busy' : 'System Heartbeat' }}
                        </span>
                        <span class="text-[8px] opacity-50 font-bold truncate max-w-[120px] flex items-center gap-1">
                            {{ heartbeat.details.status_text }}
                            <ChevronDown class="h-2 w-2 opacity-0 group-hover:opacity-100 transition-opacity" />
                        </span>
                    </div>
                    <div class="relative h-8 w-8 flex items-center justify-center">
                        <RefreshCw 
                            class="h-4 w-4 animate-spin opacity-30" 
                            :class="isRedBusy ? 'text-red-500' : 'text-primary'"
                        />
                        <div v-if="heartbeat.is_reconciling" class="absolute inset-0 flex items-center justify-center">
                            <span class="text-[7px] font-black" :class="isRedBusy ? 'text-red-500' : 'text-primary'">{{ heartbeat.reconcile_progress }}%</span>
                        </div>
                    </div>
                </button>
            </DropdownMenuTrigger>
            
            <DropdownMenuContent align="end" class="w-64 rounded-2xl p-2 shadow-2xl border-primary/10 bg-card">
                <DropdownMenuLabel class="text-[10px] uppercase tracking-widest opacity-50 font-black px-3 py-2 flex items-center justify-between">
                    Active Operations
                    <div v-if="isRedBusy" class="flex items-center gap-1 text-red-500">
                        <AlertCircle class="h-2.5 w-2.5" />
                        High Load
                    </div>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                
                <div class="p-2 space-y-3">
                    <!-- Memory Reconciliation -->
                    <div v-if="heartbeat.is_reconciling" class="p-2.5 rounded-xl bg-primary/5 border border-primary/10 space-y-2">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <BrainCircuit class="h-3 w-3 text-primary" />
                                <span class="text-[10px] font-bold uppercase">Memory Sync</span>
                            </div>
                            <span class="text-[10px] font-black text-primary">{{ heartbeat.reconcile_progress }}%</span>
                        </div>
                        <div class="h-1 w-full bg-primary/10 rounded-full overflow-hidden">
                            <div class="h-full bg-primary transition-all duration-500" :style="{ width: heartbeat.reconcile_progress + '%' }"></div>
                        </div>
                    </div>

                    <!-- System Tasks (Full Pipeline) -->
                    <div v-for="task in heartbeat.details.tasks" :key="task.id" class="p-2.5 rounded-xl bg-muted/50 border border-border/50 space-y-2">
                        <div class="flex items-center justify-between overflow-hidden">
                            <div class="flex items-center gap-2 overflow-hidden">
                                <component 
                                    :is="task.type === 'drafting' ? PenTool : (task.type === 'sync' || task.type === 'vision' ? HardDrive : Clock)" 
                                    class="h-3 w-3" 
                                    :class="{
                                        'text-primary': task.status === 'running' && task.type === 'drafting',
                                        'text-blue-500': task.status === 'running' && (task.type === 'sync' || task.type === 'vision'),
                                        'text-amber-500': task.status === 'queued'
                                    }"
                                />
                                <span class="text-[10px] font-bold truncate uppercase">{{ task.description }}</span>
                            </div>
                            <span v-if="task.status === 'running'" class="text-[10px] font-black opacity-40">
                                {{ task.progress > 0 ? task.progress + '%' : 'Active' }}
                            </span>
                            <span v-else class="text-[8px] font-black uppercase tracking-tighter opacity-40">Queued</span>
                        </div>
                        
                        <div v-if="task.status === 'running' && task.progress > 0" class="h-1 w-full bg-border/50 rounded-full overflow-hidden">
                            <div class="h-full bg-blue-500 transition-all duration-500" :style="{ width: task.progress + '%' }"></div>
                        </div>

                        <p v-if="task.status === 'queued'" class="text-[8px] text-muted-foreground truncate opacity-60 italic">
                            Waiting for system resources...
                        </p>
                    </div>

                    <!-- Stale Folders (Manual Changes Detected) -->
                    <div v-for="folder in heartbeat.details.stale_folders" :key="'stale-' + folder.id" class="p-2.5 rounded-xl bg-amber-500/5 border border-amber-500/20 space-y-2 animate-in fade-in">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <AlertCircle class="h-3 w-3 text-amber-500" />
                                <span class="text-[10px] font-bold truncate uppercase">{{ folder.name }}</span>
                            </div>
                            <span class="text-[8px] font-black uppercase tracking-tighter text-amber-600">Stale</span>
                        </div>
                        <p class="text-[8px] text-muted-foreground leading-relaxed italic">
                            Manual changes detected on disk. Memory sync recommended.
                        </p>
                    </div>
                </div>

                <DropdownMenuSeparator />
                <div class="px-3 py-2 text-center">
                    <p class="text-[8px] leading-relaxed text-muted-foreground italic">
                        Arkhein is optimizing your local silos. Intelligence depth may be limited during this phase.
                    </p>
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    </header>
</template>
