<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { LayoutGrid, Database, FolderSearch, BrainCircuit, ChevronRight } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

defineProps<{
    stats: {
        verticals_count: number;
        folders_count: number;
        knowledge_count: number;
        latest_fragments: any[];
    }
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

const truncate = (text: string, length: number) => {
    if (text.length <= length) return text;
    return text.substring(0, length) + '...';
};
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-8 p-8 overflow-y-auto max-w-6xl mx-auto w-full pb-20">
            
            <!-- Welcome Header -->
            <div class="space-y-2">
                <h1 class="text-3xl font-bold tracking-tight">System Overview</h1>
                <p class="text-muted-foreground">Monitor Arkhein's core intelligence and memory state.</p>
            </div>

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="p-6 rounded-3xl bg-card border border-border/50 shadow-sm flex flex-col gap-4">
                    <div class="p-2 rounded-xl bg-primary/10 text-primary w-fit">
                        <Database class="h-5 w-5" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold">{{ stats.knowledge_count }}</div>
                        <div class="text-xs text-muted-foreground uppercase tracking-wider font-semibold">Total Knowledge Chunks</div>
                    </div>
                </div>

                <div class="p-6 rounded-3xl bg-card border border-border/50 shadow-sm flex flex-col gap-4">
                    <div class="p-2 rounded-xl bg-primary/10 text-primary w-fit">
                        <FolderSearch class="h-5 w-5" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold">{{ stats.folders_count }}</div>
                        <div class="text-xs text-muted-foreground uppercase tracking-wider font-semibold">Managed Folders</div>
                    </div>
                </div>

                <div class="p-6 rounded-3xl bg-card border border-border/50 shadow-sm flex flex-col gap-4">
                    <div class="p-2 rounded-xl bg-primary/10 text-primary w-fit">
                        <BrainCircuit class="h-5 w-5" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold">{{ stats.verticals_count }}</div>
                        <div class="text-xs text-muted-foreground uppercase tracking-wider font-semibold">Active Verticals</div>
                    </div>
                </div>
            </div>

            <!-- Quick Navigation -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <Link href="/vantage" class="group p-8 rounded-[2rem] bg-muted/40 border border-border/40 hover:bg-muted/60 transition-all flex items-center justify-between relative overflow-hidden">
                    <div class="flex items-center gap-4 relative z-10">
                        <div class="p-4 rounded-2xl bg-background border border-border shadow-sm group-hover:scale-110 transition-transform">
                            <LayoutGrid class="h-6 w-6" />
                        </div>
                        <div>
                            <h3 class="font-bold text-lg">Vantage Hub</h3>
                            <p class="text-sm text-muted-foreground">Deep analysis of your documents.</p>
                        </div>
                    </div>
                    <ChevronRight class="h-5 w-5 text-muted-foreground opacity-0 group-hover:opacity-100 -translate-x-2 group-hover:translate-x-0 transition-all" />
                </Link>

                <Link href="/settings" class="group p-8 rounded-[2rem] bg-muted/40 border border-border/40 hover:bg-muted/60 transition-all flex items-center justify-between relative overflow-hidden">
                    <div class="flex items-center gap-4 relative z-10">
                        <div class="p-4 rounded-2xl bg-background border border-border shadow-sm group-hover:scale-110 transition-transform">
                            <FolderSearch class="h-6 w-6" />
                        </div>
                        <div>
                            <h3 class="font-bold text-lg">Source Control</h3>
                            <p class="text-sm text-muted-foreground">Authorize and manage file system access.</p>
                        </div>
                    </div>
                    <ChevronRight class="h-5 w-5 text-muted-foreground opacity-0 group-hover:opacity-100 -translate-x-2 group-hover:translate-x-0 transition-all" />
                </Link>
            </div>

            <!-- Memory Fragments Section -->
            <div class="space-y-4">
                <div class="flex items-center gap-2 px-2">
                    <Database class="h-4 w-4 text-primary" />
                    <h2 class="text-lg font-bold tracking-tight">Recent Memory Fragments</h2>
                </div>
                <div class="grid grid-cols-1 gap-3">
                    <div v-for="(fragment, idx) in stats.latest_fragments" :key="idx" 
                        class="p-4 rounded-2xl bg-card border border-border/50 shadow-sm flex items-start gap-4 hover:border-primary/20 transition-colors group"
                    >
                        <div class="p-2 rounded-xl bg-muted/50 text-muted-foreground group-hover:bg-primary/10 group-hover:text-primary transition-colors">
                            <BrainCircuit class="h-4 w-4" />
                        </div>
                        <div class="flex-1 overflow-hidden">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-[10px] font-black uppercase tracking-widest opacity-40">
                                    {{ fragment.type === 'file' ? (fragment.metadata?.filename || 'File') : 'Insight' }}
                                </span>
                            </div>
                            <p class="text-xs leading-relaxed opacity-80 italic">"{{ truncate(fragment.content, 180) }}"</p>
                        </div>
                    </div>

                    <div v-if="stats.latest_fragments.length === 0" class="p-12 rounded-[2rem] border border-dashed border-border flex flex-col items-center justify-center text-center gap-4 opacity-40">
                        <Database class="h-8 w-8" />
                        <p class="text-sm italic">Arkhein's memory is currently empty. Authorize a folder to begin indexing.</p>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
