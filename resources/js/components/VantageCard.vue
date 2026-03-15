<script setup lang="ts">
import { ref, onMounted, nextTick, watch } from 'vue';
import axios from 'axios';
import Card from '@/components/ui/card/Card.vue';
import CardHeader from '@/components/ui/card/CardHeader.vue';
import CardTitle from '@/components/ui/card/CardTitle.vue';
import CardDescription from '@/components/ui/card/CardDescription.vue';
import CardContent from '@/components/ui/card/CardContent.vue';
import CardFooter from '@/components/ui/card/CardFooter.vue';
import Button from '@/components/ui/button/Button.vue';
import Input from '@/components/ui/input/Input.vue';
import Select from '@/components/ui/select/Select.vue';
import SelectContent from '@/components/ui/select/SelectContent.vue';
import SelectItem from '@/components/ui/select/SelectItem.vue';
import SelectTrigger from '@/components/ui/select/SelectTrigger.vue';
import SelectValue from '@/components/ui/select/SelectValue.vue';
import ScrollArea from '@/components/ui/scroll-area/ScrollArea.vue';
import Markdown from '@/components/Markdown.vue';
import { 
    FolderSearch, RefreshCcw, Send, Loader2, Bot, User, 
    FileText, Search, Database, HardDrive, Trash2, Eraser
} from 'lucide-vue-next';

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
const scrollAreaRef = ref<any>(null);

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
                viewport.scrollTo({
                    top: viewport.scrollHeight,
                    behavior: 'smooth'
                });
            }
        }
    }, 100);
};

watch(messages, () => {
    scrollToBottom();
}, { deep: true });

watch(isQuerying, (val) => {
    if (val) scrollToBottom();
});

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
        setTimeout(() => {
            isSyncing.value = false;
        }, 3000);
    } catch (e) {
        isSyncing.value = false;
    }
};

const sendQuery = async () => {
    if (!query.value.trim() || isQuerying.value) return;
    
    const userMsg = query.value;
    messages.value.push({ role: 'user', content: userMsg });
    query.value = '';
    isQuerying.value = true;
    scrollToBottom();

    try {
        const response = await axios.post(`/verticals/${currentVertical.value.id}/query`, {
            query: userMsg
        });
        
        messages.value.push({
            role: 'assistant',
            content: response.data.response
        });
        sources.value = response.data.sources;
    } catch (e) {
        messages.value.push({
            role: 'assistant',
            content: "Analysis failed. Ensure the folder is indexed."
        });
    } finally {
        isQuerying.value = false;
        scrollToBottom();
    }
};
</script>

<template>
    <Card class="flex flex-col h-[520px] shadow-sm border-sidebar-border/70 dark:border-sidebar-border transition-all hover:border-primary/20 bg-card overflow-hidden">
        <!-- 1. Selection State -->
        <template v-if="!currentVertical">
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
        </template>

        <!-- 2. Active State -->
        <template v-else>
            <CardHeader class="pb-3 border-b bg-muted/10 shrink-0">
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
                        <div v-if="messages.length === 0" class="h-32 flex flex-col items-center justify-center text-center opacity-40 mt-12">
                            <FileText class="h-8 w-8 mb-2" />
                            <p class="text-[11px] font-medium italic">Ask anything about the documents in this folder.</p>
                        </div>
                        
                        <div v-for="(msg, idx) in messages" :key="idx" class="flex flex-col gap-1">
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
                            </div>
                        </div>

                        <div v-if="isQuerying" class="flex gap-2 items-center px-1 py-2">
                            <Loader2 class="h-3 w-3 animate-spin text-primary" />
                            <span class="text-[9px] font-bold opacity-30 uppercase tracking-tighter">Analyzing Registry...</span>
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
                    <Input 
                        v-model="query" 
                        placeholder="Query documents..." 
                        class="h-8 text-xs rounded-lg bg-muted/20 border-none shadow-none focus-visible:ring-1 focus-visible:ring-primary/20"
                        @keydown.enter="sendQuery"
                        :disabled="isQuerying"
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
