<script setup lang="ts">
import { Loader2, Folder, FileText, ExternalLink } from 'lucide-vue-next';
import Button from '@/components/ui/button/Button.vue';

const props = defineProps<{
    msg: any;
    isExecutingAction: Record<string, boolean>;
}>();

const emit = defineEmits(['confirmAll', 'confirmAction']);

const getActions = (msg: any) => {
    if (msg.pending_actions && Array.isArray(msg.pending_actions)) return msg.pending_actions;
    if (!msg.metadata) return [];
    let meta = msg.metadata;
    try {
        while (typeof meta === 'string') meta = JSON.parse(meta);
        return (meta && Array.isArray(meta.pending_actions)) ? meta.pending_actions : [];
    } catch (e) {
        return [];
    }
};

const getPendingCount = (msg: any) => {
    return getActions(msg).filter((a: any) => a.status !== 'executed').length;
};

const getReasoning = (msg: any) => {
    if (!msg.metadata) return null;
    let meta = msg.metadata;
    try {
        while (typeof meta === 'string') meta = JSON.parse(meta);
        return meta.reasoning || null;
    } catch (e) {
        return null;
    }
};
</script>

<template>
    <div v-if="getActions(msg).length > 0" class="mt-4 flex flex-col gap-2">
        <!-- Reasoning Block -->
        <div v-if="getReasoning(msg)" class="mb-2 px-3 py-2 rounded-xl bg-primary/5 border border-primary/10 text-[10px] italic opacity-80 leading-relaxed">
            <span class="font-bold uppercase not-italic text-[8px] opacity-50 block mb-1">Strategist Reasoning</span>
            {{ getReasoning(msg) }}
        </div>

        <!-- Bulk Action Header -->
        <div v-if="getPendingCount(msg) > 1" class="flex items-center justify-between mb-2 px-2 py-1.5 rounded-lg bg-primary/5 border border-primary/10">
            <div class="flex items-center gap-2">
                <div class="h-1.5 w-1.5 rounded-full bg-primary animate-pulse"></div>
                <span class="text-[10px] font-bold uppercase tracking-wider text-primary/80">
                    {{ getPendingCount(msg) }} Operations Pending
                </span>
            </div>
            <Button
                size="sm"
                variant="default"
                class="h-7 text-[10px] px-4 font-bold shadow-sm"
                @click="emit('confirmAll', msg)"
            >
                Confirm All
            </Button>
        </div>

        <div v-for="(action, aIdx) in getActions(msg)" :key="action.id || aIdx"
            class="flex flex-col p-2 rounded-xl bg-background/50 border border-border/40 shadow-sm"
            :class="{ 'opacity-40 grayscale-[0.5]': action.status === 'executed' }"
        >
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-2 overflow-hidden">
                    <div class="p-1.5 rounded-lg bg-primary/10">
                        <Folder v-if="action.type === 'create_folder'" class="h-3 w-3 text-primary" />
                        <FileText v-else-if="action.type === 'create_file'" class="h-3 w-3 text-primary" />
                        <ExternalLink v-else class="h-3 w-3 text-primary" />
                    </div>
                    <div class="flex flex-col overflow-hidden">
                        <span class="text-[10px] font-bold opacity-80 uppercase tracking-tight">{{ action.type.replace('_', ' ') }}</span>
                        <span class="text-[9px] truncate opacity-60">{{ action.description }}</span>
                    </div>
                </div>

                <Button
                    v-if="action.status !== 'executed'"
                    size="sm"
                    variant="outline"
                    class="h-7 text-[10px] px-3 font-bold border-primary/20 hover:bg-primary/5 text-primary"
                    :disabled="isExecutingAction[action.id]"
                    @click="emit('confirmAction', msg, action)"
                >
                    <Loader2 v-if="isExecutingAction[action.id]" class="mr-1.5 h-3 w-3 animate-spin" />
                    <span v-else>Confirm</span>
                </Button>
                <div v-else class="flex items-center gap-1.5 text-green-500 px-2">
                    <CheckCircle class="h-3.5 w-3.5" />
                    <span class="text-[10px] font-bold uppercase tracking-widest">Done</span>
                </div>
            </div>
        </div>
    </div>
</template>