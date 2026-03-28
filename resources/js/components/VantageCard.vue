<script setup lang="ts">
import axios from 'axios';
import {
    FolderSearch, RefreshCcw, Send, Loader2, Bot, User,
    FileText, Search, Database, HardDrive, Trash2, Eraser,
    Folder, CheckCircle, ExternalLink
} from 'lucide-vue-next';
import { ref, onMounted, nextTick, watch } from 'vue';
import Markdown from '@/components/Markdown.vue';
import Button from '@/components/ui/button/Button.vue';
import Card from '@/components/ui/card/Card.vue';
import CardContent from '@/components/ui/card/CardContent.vue';
import CardDescription from '@/components/ui/card/CardDescription.vue';
import CardFooter from '@/components/ui/card/CardFooter.vue';
import CardHeader from '@/components/ui/card/CardHeader.vue';
import CardTitle from '@/components/ui/card/CardTitle.vue';
import Input from '@/components/ui/input/Input.vue';
import ScrollArea from '@/components/ui/scroll-area/ScrollArea.vue';
import Select from '@/components/ui/select/Select.vue';
import SelectContent from '@/components/ui/select/SelectContent.vue';
import SelectItem from '@/components/ui/select/SelectItem.vue';
import SelectTrigger from '@/components/ui/select/SelectTrigger.vue';
import SelectValue from '@/components/ui/select/SelectValue.vue';
import CommandInput from '@/components/CommandInput.vue';

const props = defineProps<{
    vertical?: any;
    managedFolders: any[];
}>();

const emit = defineEmits(['created', 'deleted']);

// State
const currentVertical = ref(props.vertical);
const selectedFolderId = ref<string>('');
const isCreating = ref(false);
const isSyncing = ref(false);
const isQuerying = ref(false);
const isClearing = ref(false);
const query = ref('');
const messages = ref<any[]>(props.vertical?.interactions ? [...props.vertical.interactions].reverse() : []);
const sources = ref<any[]>([]);
const isExecutingAction = ref<Record<string, boolean>>({});
const scrollAreaRef = ref<any>(null);

const getActions = (msg: any) => {
    // Priority 1: Direct property (for new messages)
    if (msg.pending_actions && Array.isArray(msg.pending_actions)) return msg.pending_actions;

    // Priority 2: Parsed metadata (for historical messages)
    if (!msg.metadata) return [];

    let meta = msg.metadata;
    try {
        // Double-string recursive parsing for SQLite robustness
        while (typeof meta === 'string') {
            meta = JSON.parse(meta);
        }
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

const confirmAction = async (interaction: any, action: any) => {
    const actionKey = action.id;
    if (isExecutingAction.value[actionKey]) return;

    isExecutingAction.value[actionKey] = true;

    try {
        const response = await axios.post(`/verticals/${currentVertical.value.id}/action`, {
            type: action.type,
            params: action.params
        });

        if (response.data.success) {
            action.status = 'executed';
            // Update metadata for persistence
            let meta = typeof interaction.metadata === 'string' ? JSON.parse(interaction.metadata) : interaction.metadata;
            if (meta && meta.pending_actions) {
                meta.pending_actions = meta.pending_actions.map((a: any) =>
                    a.id === action.id ? { ...a, status: 'executed' } : a
                );
                interaction.metadata = meta;
            }
            // Update direct property for immediate UI
            if (interaction.pending_actions) {
                interaction.pending_actions = interaction.pending_actions.map((a: any) =>
                    a.id === action.id ? { ...a, status: 'executed' } : a
                );
            }
        } else {
            const errorMsg = response.data.error || `Action failed for: ${action.description}. Check macOS permissions for this folder.`;
            alert(errorMsg);
        }
    } catch (e) {
        console.error("Action execution failed", e);
        alert('Action execution failed.');
    } finally {
        isExecutingAction.value[actionKey] = false;
    }
};

const confirmAll = async (interaction: any) => {
    const actions = getActions(interaction).filter(a => a.status !== 'executed');
    if (actions.length === 0) return;

    for (const action of actions) {
        await confirmAction(interaction, action);
    }
};

const clearHistory = async () => {
    if (!currentVertical.value || isClearing.value) return;
    if (!confirm('Clear all conversation history for this Vantage card?')) return;
    isClearing.value = true;
    try {
        await axios.delete(`/verticals/${currentVertical.value.id}/history`);
        messages.value = [];
        sources.value = [];
    } catch (e) {
        console.error("Failed to clear history", e);
    } finally {
        isClearing.value = false;
    }
};

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

watch(messages, () => { scrollToBottom(); }, { deep: true });
watch(isQuerying, (val) => { if (val) scrollToBottom(); });

const createVertical = async () => {
    if (!selectedFolderId.value) return;
    isCreating.value = true;
    try {
        const folder = props.managedFolders.find(f => f.id.toString() === selectedFolderId.value);
        if (!folder) return;
        const response = await axios.post('/verticals', {
            name: `${folder.name} Vantage`,
            folder_id: folder.id,
            type: 'rag'
        });
        currentVertical.value = response.data;
        const fullRes = await axios.get('/verticals');
        currentVertical.value = fullRes.data.verticals.find((v: any) => v.id === response.data.id);
        emit('created', currentVertical.value);
    } catch (e) {
        console.error("Failed to create vertical", e);
    } finally {
        isCreating.value = false;
    }
};

const deleteVertical = async () => {
    if (!currentVertical.value) return;
    if (!confirm('Are you sure you want to remove this Vantage card?')) return;
    try {
        await axios.delete(`/verticals/${currentVertical.value.id}`);
        emit('deleted', currentVertical.value.id);
        currentVertical.value = null;
    } catch (e) {
        console.error("Failed to delete vertical", e);
    }
};

const syncVertical = async () => {
    if (!currentVertical.value) return;
    isSyncing.value = true;
    try {
        await axios.post(`/verticals/${currentVertical.value.id}/sync`);
        setTimeout(() => { isSyncing.value = false; }, 3000);
    } catch (e) {
        isSyncing.value = false;
    }
};

const sendQuery = async () => {
    if (!query.value.trim() || isQuerying.value) return;
    const userMsg = query.value;
    messages.value.push({ role: 'user', content: userMsg });

    // Create a reactive object for the assistant message
    const assistantMessage = {
        role: 'assistant',
        content: '',
        status: 'Searching Knowledge...',
        pending_actions: []
    };
    const assistantIndex = messages.value.length;
    messages.value.push(assistantMessage);

    query.value = '';
    isQuerying.value = true;
    sources.value = []; // Clear previous sources for new context
    scrollToBottom();

    try {
        const response = await fetch(`/verticals/${currentVertical.value.id}/stream`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || ''
            },
            body: JSON.stringify({ query: userMsg })
        });

        if (!response.body) throw new Error('ReadableStream not supported');

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        while (true) {
            const { value, done } = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop() || '';

            for (const line of lines) {
                if (!line.startsWith('data: ')) continue;
                const dataStr = line.replace('data: ', '').trim();
                if (dataStr === '[DONE]') break;

                try {
                    const { event, data } = JSON.parse(dataStr);

                    if (event === 'status') {
                        messages.value[assistantIndex].status = data;
                    } else if (event === 'sources') {
                        sources.value = data;
                    } else if (event === 'chunk') {
                        messages.value[assistantIndex].content += data;
                        messages.value[assistantIndex].status = 'Synthesizing...';
                        scrollToBottom();
                    } else if (event === 'completed' || event === 'final') {
                        // Fully replace with final record from DB for permanence (ID, timestamps, etc)
                        const finalInteraction = data.interaction || data;
                        if (data.response) finalInteraction.content = data.response;
                        if (data.pending_actions) finalInteraction.pending_actions = data.pending_actions;

                        messages.value[assistantIndex] = finalInteraction;
                        sources.value = data.sources || sources.value;
                    }
                } catch (e) {
                    // Silent fail for malformed JSON chunks
                }
            }
        }
    } catch (e) {
        console.error("Arkhein Query Error", e);
        messages.value[assistantIndex].content = "Analysis failed. Ensure the folder is indexed and Ollama is online.";
        messages.value[assistantIndex].status = 'Error';
    } finally {
        isQuerying.value = false;
        scrollToBottom();
    }
};
</script>

<template>
    <Card class="flex flex-col h-[750px] shadow-sm border-sidebar-border/70 dark:border-sidebar-border transition-all hover:border-primary/20 bg-card overflow-visible">
        <!-- 1. Selection State -->
        <template v-if="!currentVertical">
            <div class="overflow-hidden flex flex-col h-full">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base">
                        <Database class="h-4 w-4 text-primary" />
                        Initialize Vantage
                    </CardTitle>
                    <CardDescription class="text-xs">Connect a folder for deep document analysis.</CardDescription>
                </CardHeader>
                <CardContent class="flex-1 flex flex-col items-center justify-center gap-4 text-center">
                    <div class="h-12 w-12 rounded-full bg-muted/50 flex items-center justify-center mb-2">
                        <FolderSearch class="h-6 w-6 opacity-20" />
                    </div>
                    <Select v-model="selectedFolderId">
                        <SelectTrigger class="w-full max-w-[200px] h-9 text-xs">
                            <SelectValue placeholder="Select Folder" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="folder in managedFolders" :key="folder.id" :value="folder.id.toString()">
                                {{ folder.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </CardContent>
                <CardFooter>
                    <Button class="w-full h-9 text-xs" :disabled="!selectedFolderId || isCreating" @click="createVertical">
                        <Loader2 v-if="isCreating" class="mr-2 h-3.5 w-3.5 animate-spin" />
                        Deploy Vertical
                    </Button>
                </CardFooter>
            </div>
        </template>

        <!-- 2. Active State -->
        <template v-else>
            <CardHeader class="pb-3 border-b bg-muted/10 shrink-0 rounded-t-xl overflow-hidden">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2 overflow-hidden">
                        <div class="p-1.5 rounded-lg bg-primary/10">
                            <Search class="h-3.5 w-3.5 text-primary" />
                        </div>
                        <div class="overflow-hidden">
                            <CardTitle class="text-sm font-bold truncate">{{ currentVertical.name }}</CardTitle>
                            <CardDescription class="text-[9px] uppercase tracking-widest font-bold opacity-60 flex items-center gap-1">
                                <HardDrive class="h-2.5 w-2.5" />
                                {{ currentVertical.folder?.name || 'Local' }}
                            </CardDescription>
                        </div>
                    </div>
                    <div class="flex gap-1">
                        <Button variant="ghost" size="icon" class="h-7 w-7 rounded-md" :disabled="isClearing || messages.length === 0" @click="clearHistory" title="Clear Conversation">
                            <Eraser class="h-3 w-3" />
                        </Button>
                        <Button variant="ghost" size="icon" class="h-7 w-7 rounded-md" :disabled="isSyncing" @click="syncVertical" title="Re-index Folder">
                            <RefreshCcw class="h-3 w-3" :class="{ 'animate-spin': isSyncing }" />
                        </Button>
                        <Button variant="ghost" size="icon" class="h-7 w-7 rounded-md text-destructive hover:text-destructive hover:bg-destructive/10" @click="deleteVertical">
                            <Trash2 class="h-3 w-3" />
                        </Button>
                    </div>
                </div>
            </CardHeader>

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

                                <!-- Pending Actions UI -->
                                <div v-if="msg.role === 'assistant' && getActions(msg).length > 0" class="mt-4 flex flex-col gap-2">
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
                                            @click="confirmAll(msg)"
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
                                                @click="confirmAction(msg, action)"
                                            >
                                                <Loader2 v-if="isExecutingAction[action.id]" class="mr-1.5 h-3 w-3 animate-spin" />
                                                Confirm
                                            </Button>
                                            <div v-else class="flex items-center gap-1.5 text-green-500 px-2">
                                                <CheckCircle class="h-3.5 w-3.5" />
                                                <span class="text-[10px] font-bold uppercase tracking-widest">Done</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
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
                        v-model="query"
                        placeholder="Query documents... (try /help)"
                        :disabled="isQuerying"
                        @submit="sendQuery"
                    />
                    <Button size="icon" class="h-8 w-8 shrink-0 rounded-lg shadow-sm" @click="sendQuery" :disabled="!query.trim() || isQuerying">
                        <Send class="h-3.5 w-3.5" />
                    </Button>
                </div>
            </CardFooter>
        </template>
    </Card>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>
