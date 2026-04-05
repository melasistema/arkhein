<script setup lang="ts">
import axios from 'axios';
import {
    FolderSearch, Loader2, Database, HardDrive
} from 'lucide-vue-next';
import { ref, onMounted, nextTick, watch } from 'vue';
import Button from '@/components/ui/button/Button.vue';
import Card from '@/components/ui/card/Card.vue';
import CardContent from '@/components/ui/card/CardContent.vue';
import CardDescription from '@/components/ui/card/CardDescription.vue';
import CardFooter from '@/components/ui/card/CardFooter.vue';
import CardHeader from '@/components/ui/card/CardHeader.vue';
import CardTitle from '@/components/ui/card/CardTitle.vue';
import Select from '@/components/ui/select/Select.vue';
import SelectContent from '@/components/ui/select/SelectContent.vue';
import SelectItem from '@/components/ui/select/SelectItem.vue';
import SelectTrigger from '@/components/ui/select/SelectTrigger.vue';
import SelectValue from '@/components/ui/select/SelectValue.vue';
import SiloStatusPanel from '@/components/vantage/SiloStatusPanel.vue';
import ChatInterface from '@/components/vantage/ChatInterface.vue';

const props = defineProps<{
    vertical?: any;
    managedFolders: any[];
    visionEnabled?: boolean;
}>();

const emit = defineEmits(['created', 'deleted']);

// State
const currentVertical = ref(props.vertical);
const selectedFolderId = ref<string>('');
const isCreating = ref(false);
const isSyncing = ref(false);
const isTogglingVisual = ref(false);
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

const toggleVisual = async () => {
    if (!currentVertical.value || !currentVertical.value.folder || isTogglingVisual.value) return;

    const folder = currentVertical.value.folder;
    const isAlreadyEnabled = folder.allow_visual_indexing;

    // Elegant Confirmation Flow
    const message = isAlreadyEnabled
        ? "Vision Intelligence is already active for this folder. Re-analyze all images? (High resource usage)"
        : "RESOURCE KILLER: Enable Vision Intelligence for this folder? Arkhein will use VL models to describe every image. This is extremely compute-intensive and will significantly drain battery. Continue?";

    if (!confirm(message)) return;
    isTogglingVisual.value = true;
    try {
        const folderId = folder.id;
        // If it's already enabled, we might want a 'force' sync on the backend
        // For now, the existing toggle logic is fine, but we trigger the sync UI
        await axios.post(`/settings/folders/${folderId}/toggle-visual`);
        
        // Update local state if it was a first-time activation
        if (!isAlreadyEnabled) {
            currentVertical.value.folder.allow_visual_indexing = true;
        }
        
        // Always trigger the sync UI to show progress to the user
        isSyncing.value = true;
        setTimeout(() => { isSyncing.value = false; }, 3000);
    } catch (e) {
        console.error("Failed to toggle visual indexing", e);
    } finally {
        isTogglingVisual.value = false;
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
            <SiloStatusPanel
                :currentVertical="currentVertical"
                :visionEnabled="visionEnabled"
                :isTogglingVisual="isTogglingVisual"
                :isSyncing="isSyncing"
                :isClearing="isClearing"
                :hasMessages="messages.length > 0"
                @toggleVisual="toggleVisual"
                @clearHistory="clearHistory"
                @syncVertical="syncVertical"
                @deleteVertical="deleteVertical"
            />

            <ChatInterface
                :messages="messages"
                :sources="sources"
                :isQuerying="isQuerying"
                :isExecutingAction="isExecutingAction"
                v-model:queryModel="query"
                @sendQuery="sendQuery"
                @confirmAction="confirmAction"
                @confirmAll="confirmAll"
            />
        </template>
    </Card>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>
