<script setup lang="ts">
import { Search, HardDrive, Loader2, ScanEye, EyeOff, Eraser, RefreshCcw, Trash2 } from 'lucide-vue-next';
import Button from '@/components/ui/button/Button.vue';
import CardTitle from '@/components/ui/card/CardTitle.vue';
import CardDescription from '@/components/ui/card/CardDescription.vue';

defineProps<{
    currentVertical: any;
    visionEnabled?: boolean;
    isTogglingVisual: boolean;
    isSyncing: boolean;
    isClearing: boolean;
    hasMessages: boolean;
}>();

const emit = defineEmits(['toggleVisual', 'clearHistory', 'syncVertical', 'deleteVertical']);
</script>

<template>
    <div class="pb-3 border-b bg-muted/10 shrink-0 rounded-t-xl overflow-hidden p-6 pb-4">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2 overflow-hidden">
                <div class="p-1.5 rounded-lg bg-primary/10">
                    <Search class="h-3.5 w-3.5 text-primary" />
                </div>
                <div class="overflow-hidden">
                    <CardTitle class="text-sm font-bold truncate">{{ currentVertical.name }}</CardTitle>
                    <CardDescription class="text-[9px] uppercase tracking-widest font-bold opacity-60 flex items-center gap-1 mt-1">
                        <HardDrive class="h-2.5 w-2.5" />
                        {{ currentVertical.folder?.name || 'Local' }}
                    </CardDescription>
                </div>
            </div>
            <div class="flex gap-1">
                <Button
                    v-if="currentVertical.folder"
                    variant="ghost"
                    size="icon"
                    class="h-7 w-7 rounded-md transition-colors"
                    :class="[
                        currentVertical.folder.allow_visual_indexing ? 'text-blue-500 bg-blue-500/10' : 'text-muted-foreground opacity-40',
                        (currentVertical.folder.is_indexing || !visionEnabled) ? 'opacity-20 cursor-not-allowed' : ''
                    ]"
                    :disabled="isTogglingVisual || isSyncing || currentVertical.folder.is_indexing || !visionEnabled"
                    @click="emit('toggleVisual')"
                    :title="!visionEnabled ? 'Vision Intelligence is globally disabled in Settings' : (currentVertical.folder.is_indexing ? 'System busy: Finish indexing before modifying vision' : 'Toggle Visual Intelligence')"
                >                            
                    <Loader2 v-if="isTogglingVisual" class="h-3 w-3 animate-spin" />
                    <component v-else :is="currentVertical.folder.allow_visual_indexing ? ScanEye : EyeOff" class="h-3 w-3" />
                </Button>
                <Button variant="ghost" size="icon" class="h-7 w-7 rounded-md" :disabled="isClearing || !hasMessages" @click="emit('clearHistory')" title="Clear Conversation">
                    <Eraser class="h-3 w-3" />
                </Button>
                <Button variant="ghost" size="icon" class="h-7 w-7 rounded-md" :disabled="isSyncing" @click="emit('syncVertical')" title="Re-index Folder">
                    <RefreshCcw class="h-3 w-3" :class="{ 'animate-spin': isSyncing }" />
                </Button>
                <Button variant="ghost" size="icon" class="h-7 w-7 rounded-md text-destructive hover:text-destructive hover:bg-destructive/10" @click="emit('deleteVertical')">
                    <Trash2 class="h-3 w-3" />
                </Button>
            </div>
        </div>
    </div>
</template>