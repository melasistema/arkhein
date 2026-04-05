<script setup lang="ts">
import { Loader2, Sparkles, FileText, Send } from 'lucide-vue-next';
import { ref, watch, nextTick } from 'vue';
import Markdown from '@/components/Markdown.vue';
import ScrollArea from '@/components/ui/scroll-area/ScrollArea.vue';
import Button from '@/components/ui/button/Button.vue';
import CommandInput from '@/components/CommandInput.vue';
import ActionConfirmationBridge from '@/components/vantage/ActionConfirmationBridge.vue';
import CardContent from '@/components/ui/card/CardContent.vue';
import CardFooter from '@/components/ui/card/CardFooter.vue';

const props = defineProps<{
    messages: any[];
    sources: any[];
    isQuerying: boolean;
    isExecutingAction: Record<string, boolean>;
    queryModel: string;
}>();

const emit = defineEmits(['update:queryModel', 'sendQuery', 'confirmAction', 'confirmAll']);

const scrollAreaRef = ref<any>(null);

const scrollToBottom = async () => {
    await nextTick();
    setTimeout(() => {
        if (scrollAreaRef.value?.$el) {
            const viewport = scrollAreaRef.value.$el.querySelector('[data-slot="scroll-area-viewport"]');
            if (viewport) {
                viewport.scrollTo({ top: viewport.scrollHeight, behavior: 'smooth' });
            }
        }
    }, 100);
};

watch(() => props.messages, () => { scrollToBottom(); }, { deep: true });
watch(() => props.isQuerying, (val) => { if (val) scrollToBottom(); });
</script>

<template>
    <CardContent class="flex-1 p-0 overflow-hidden relative flex flex-col min-h-0">
        <ScrollArea ref="scrollAreaRef" class="h-full w-full">
            <div class="px-4 py-4 min-h-full flex flex-col gap-4">
                <!-- Empty State / Introduction -->
                <div v-if="messages.length === 0" class="flex-1 flex flex-col items-center justify-center text-center py-12 px-6">
                    <div class="p-4 rounded-3xl bg-primary/5 mb-4 shadow-inner">
                        <Sparkles class="h-8 w-8 text-primary opacity-40" />
                    </div>
                    <h3 class="text-sm font-bold uppercase tracking-widest opacity-80 mb-2">Vantage Intelligence Active</h3>
                    <p class="text-[11px] text-muted-foreground leading-relaxed max-w-[240px] italic mb-6">
                        Ask anything about the documents in this silo, or use Magic Commands to command your silicon.
                    </p>
                    
                    <div class="grid grid-cols-1 gap-2 w-full max-w-[280px]">
                        <div class="p-2.5 rounded-xl bg-muted/30 border border-border/50 text-left flex flex-col gap-1">
                            <span class="text-[10px] font-black text-primary">/help</span>
                            <span class="text-[9px] opacity-60 italic">See all magic commands available in this silo.</span>
                        </div>
                        <div class="p-2.5 rounded-xl bg-muted/30 border border-border/50 text-left flex flex-col gap-1">
                            <span class="text-[10px] font-black text-primary">/create [filename]</span>
                            <span class="text-[9px] opacity-60 italic">Deep Creation: Generate files from your knowledge.</span>
                        </div>
                        <div class="p-2.5 rounded-xl bg-muted/30 border border-border/50 text-left flex flex-col gap-1">
                            <span class="text-[10px] font-black text-primary">/organize</span>
                            <span class="text-[9px] opacity-60 italic">Silo Structuring: Group files by thematic relevance.</span>
                        </div>
                    </div>
                </div>

                <div v-for="(msg, idx) in messages" :key="msg.id || idx" class="flex flex-col gap-1">
                    <div class="flex items-center gap-1.5 mb-1" :class="msg.role === 'user' ? 'justify-end' : 'justify-start'">
                        <span v-if="msg.role === 'assistant'" class="text-[9px] font-bold uppercase tracking-wider opacity-30">ARKHEIN VANTAGE</span>
                        <span v-else class="text-[9px] font-bold uppercase tracking-wider opacity-30">USER</span>
                    </div>
                    <div
                        class="text-xs p-3 rounded-2xl leading-relaxed"
                        :class="msg.role === 'user' ? 'bg-primary text-primary-foreground ml-8 rounded-tr-none shadow-sm whitespace-pre-wrap' : 'bg-muted/50 border border-border/50 mr-8 rounded-tl-none'"
                    >
                        <Markdown v-if="msg.role === 'assistant'" :content="msg.content" />
                        <template v-else>{{ msg.content }}</template>

                        <ActionConfirmationBridge 
                            v-if="msg.role === 'assistant'" 
                            :msg="msg" 
                            :isExecutingAction="isExecutingAction"
                            @confirmAction="(interaction, action) => emit('confirmAction', interaction, action)"
                            @confirmAll="(interaction) => emit('confirmAll', interaction)"
                        />
                    </div>
                </div>

                <div v-if="isQuerying" class="flex gap-2 items-center px-1 py-2">
                    <Loader2 class="h-3 w-3 animate-spin text-primary" />
                    <span class="text-[9px] font-bold opacity-30 uppercase tracking-tighter">
                        {{ messages[messages.length - 1]?.status || 'Analyzing Registry...' }}
                    </span>
                </div>
            </div>
        </ScrollArea>

        <!-- Source Tags -->
        <div v-if="sources.length > 0" class="px-4 py-2 border-t bg-muted/5 flex gap-1 overflow-x-auto no-scrollbar shrink-0">
            <div v-for="source in sources" :key="source.filename" class="px-1.5 py-0.5 rounded-md bg-background border text-[8px] whitespace-nowrap opacity-60 hover:opacity-100 transition-opacity flex items-center gap-1 shadow-sm">
                <FileText class="h-2.5 w-2.5" />
                {{ source.filename }}
            </div>
        </div>
    </CardContent>

    <CardFooter class="p-3 border-t bg-background shrink-0">
        <div class="flex w-full items-center gap-2">
            <CommandInput
                :modelValue="queryModel"
                @update:modelValue="(val) => emit('update:queryModel', val)"
                placeholder="Query documents... (try /help)"
                :disabled="isQuerying"
                @submit="emit('sendQuery')"
            />
            <Button size="icon" class="h-8 w-8 shrink-0 rounded-lg shadow-sm" @click="emit('sendQuery')" :disabled="!queryModel.trim() || isQuerying">
                <Send class="h-3.5 w-3.5" />
            </Button>
        </div>
    </CardFooter>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>