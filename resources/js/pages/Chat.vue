<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, nextTick, onMounted, computed } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/button/Button.vue';
import CommandInput from '@/components/CommandInput.vue';
import { ScrollArea } from '@/components/ui/scroll-area';
import Markdown from '@/components/Markdown.vue';
import { 
    MessageSquare, Send, Bot, User, BrainCircuit, Check, X, ShieldAlert, 
    Loader2, Plus, History, ChevronRight
} from 'lucide-vue-next';
import { chat } from '@/routes';

import { 
    Dialog, DialogContent, DialogDescription, DialogFooter, 
    DialogHeader, DialogTitle, DialogTrigger 
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    conversations: any[];
}>();

interface Message {
    role: 'user' | 'assistant' | 'system';
    content: string;
    pending_actions?: any[];
    status?: 'pending' | 'executing' | 'success' | 'error';
    error?: string;
}

const breadcrumbs = [
    { title: 'Chat', href: chat.url() },
];

/**
 * Session State
 */
const activeConversation = ref<any>(null);
const localConversations = ref([...props.conversations]);
const messages = ref<Message[]>([]);
const newMessage = ref('');
const isLoading = ref(false);
const isOllamaOnline = ref(true);
const scrollAreaRef = ref<HTMLElement | null>(null);

// Modal State
const isNewSessionModalOpen = ref(false);
const newSessionTitle = ref('');

const checkOllamaStatus = async () => {
    try {
        const response = await axios.get('/ollama/status');
        isOllamaOnline.value = response.data.online;
    } catch (error) {
        isOllamaOnline.value = false;
    }
};

const scrollToBottom = async () => {
    await nextTick();
    if (scrollAreaRef.value) {
        scrollAreaRef.value.scrollTop = scrollAreaRef.value.scrollHeight;
    }
};

/**
 * Conversation Logic
 */
const startNewConversation = () => {
    newSessionTitle.value = '';
    isNewSessionModalOpen.value = true;
};

const createSession = async () => {
    if (!newSessionTitle.value.trim()) return;

    try {
        const response = await axios.post('/chat/start', { title: newSessionTitle.value });
        activeConversation.value = response.data;
        localConversations.value.unshift(response.data);
        messages.value = [
            { role: 'assistant', content: `Session "${newSessionTitle.value}" initialized. I am Arkhein. How shall we begin?` }
        ];
        isNewSessionModalOpen.value = false;
    } catch (e) {
        console.error("Failed to start conversation");
    }
};

const loadConversation = async (conversation: any) => {
    activeConversation.value = conversation;
    isLoading.value = true;
    try {
        const response = await axios.get(`/chat/history/${conversation.id}`);
        messages.value = response.data.messages.map((m: any) => ({
            role: m.role,
            content: m.content,
            pending_actions: m.metadata?.pending_actions || [],
            status: m.metadata?.pending_actions?.length > 0 ? 'pending' : undefined
        }));
        await scrollToBottom();
    } catch (e) {
        console.error("Failed to load history");
    } finally {
        isLoading.value = false;
    }
};

const handleCommand = (command: string) => {
    if (command === 'help') {
        messages.value.push({
            role: 'assistant',
            content: "Available Commands:\n/help - Show this message\n/sync - Sync all managed folders\n\nUse @ to mention authorized files and folders.",
        });
        scrollToBottom();
    } else if (command === 'sync') {
        messages.value.push({ role: 'assistant', content: "Starting archive synchronization..." });
        scrollToBottom();
        axios.post('/settings/sync').then(response => {
            messages.value.push({ role: 'assistant', content: "Sync complete!" });
            scrollToBottom();
        });
    }
};

const approveAction = async (msgIndex: number, actionIndex: number) => {
    const msg = messages.value[msgIndex];
    if (!msg.pending_actions) return;
    
    const action = msg.pending_actions[actionIndex];
    action.status = 'executing';
    
    try {
        const response = await axios.post('/chat/action/execute', { action });
        action.status = response.data.success ? 'success' : 'error';
        if (!response.data.success) action.error = response.data.error;
    } catch (e) {
        action.status = 'error';
        action.error = "Connection error";
    }
};

const denyAction = (msgIndex: number, actionIndex: number) => {
    const msg = messages.value[msgIndex];
    if (msg.pending_actions) {
        msg.pending_actions[actionIndex].status = 'error';
        msg.pending_actions[actionIndex].error = "User denied action";
    }
};

const sendMessage = async () => {
    if (!newMessage.value.trim() || isLoading.value || !activeConversation.value) return;

    const userContent = newMessage.value;
    messages.value.push({ role: 'user', content: userContent });
    newMessage.value = '';
    isLoading.value = true;
    
    await scrollToBottom();

    try {
        const response = await axios.post('/chat/send', {
            message: userContent,
            conversation_id: activeConversation.value.id
        });

        messages.value.push({
            role: 'assistant',
            content: response.data.message,
            pending_actions: response.data.pending_actions || []
        });
    } catch (error) {
        messages.value.push({
            role: 'assistant',
            content: 'Sorry, I encountered an error while processing your request.',
        });
    } finally {
        isLoading.value = false;
        await scrollToBottom();
    }
};

onMounted(checkOllamaStatus);
</script>

<template>
    <Head title="Chat" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-[calc(100vh-65px)] overflow-hidden">
            
            <!-- New Session Dialog -->
            <Dialog v-model:open="isNewSessionModalOpen">
                <DialogContent class="sm:max-w-[425px]">
                    <DialogHeader>
                        <DialogTitle>New Chat Session</DialogTitle>
                        <DialogDescription>
                            Give this session a theme to help Arkhein organize your digital memory.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-4 py-4">
                        <div class="grid grid-cols-4 items-center gap-4">
                            <Label for="name" class="text-right">Theme</Label>
                            <Input
                                id="name"
                                v-model="newSessionTitle"
                                placeholder="e.g. Project Arkhein Setup"
                                class="col-span-3"
                                @keydown.enter="createSession"
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" @click="isNewSessionModalOpen = false">Cancel</Button>
                        <Button type="submit" :disabled="!newSessionTitle.trim()" @click="createSession">Initialize Session</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <!-- Session Sidebar -->
            <div class="w-64 border-r bg-muted/10 flex flex-col">
                <div class="p-4 border-b">
                    <Button variant="outline" class="w-full justify-start gap-2" @click="startNewConversation">
                        <Plus class="h-4 w-4" />
                        New Session
                    </Button>
                </div>
                <ScrollArea class="flex-1">
                    <div class="p-2 space-y-1">
                        <button
                            v-for="conv in localConversations"
                            :key="conv.id"
                            @click="loadConversation(conv)"
                            class="w-full flex items-center gap-3 px-3 py-2 text-sm rounded-md text-left transition-colors"
                            :class="activeConversation?.id === conv.id ? 'bg-primary text-primary-foreground shadow-sm' : 'hover:bg-muted text-muted-foreground'"
                        >
                            <MessageSquare class="h-4 w-4 shrink-0" />
                            <span class="truncate">{{ conv.title || 'Untitled Session' }}</span>
                        </button>
                    </div>
                </ScrollArea>
            </div>

            <!-- Main Chat Area -->
            <div class="flex-1 flex flex-col min-w-0 bg-background h-full">
                <!-- Header -->
                <div class="flex items-center gap-2 px-6 py-3 border-b bg-background/50 backdrop-blur-md shrink-0">
                    <BrainCircuit class="h-5 w-5 text-primary" />
                    <h1 class="font-semibold truncate">
                        {{ activeConversation ? activeConversation.title : 'Select a Session' }}
                    </h1>
                    <div class="ml-auto flex items-center gap-2">
                        <span v-if="isOllamaOnline" class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-[10px] font-medium text-green-800 dark:bg-green-900/30 dark:text-green-400">
                            Ollama Online
                        </span>
                        <span v-else class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-[10px] font-medium text-red-800 dark:bg-red-900/30 dark:text-red-400">
                            Ollama Offline
                        </span>
                    </div>
                </div>

                <!-- Chat Body -->
                <div v-if="!activeConversation" class="flex-1 flex flex-col items-center justify-center text-center p-8 opacity-50">
                    <div class="h-12 w-12 rounded-full bg-muted flex items-center justify-center mb-4">
                        <History class="h-6 w-6" />
                    </div>
                    <h2 class="text-lg font-medium">No Session Active</h2>
                    <p class="text-sm max-w-xs mt-1">Select a previous session from the sidebar or start a new themed project.</p>
                    <Button variant="secondary" class="mt-6" @click="startNewConversation">Start First Session</Button>
                </div>

                <template v-else>
                    <!-- Standard Scrollable Div -->
                    <div ref="scrollAreaRef" class="flex-1 w-full overflow-y-auto overflow-x-hidden">
                        <div class="max-w-3xl mx-auto p-6 space-y-6 pb-4">
                            <div
                                v-for="(msg, index) in messages"
                                :key="index"
                                class="flex w-full flex-col gap-2"
                                :class="msg.role === 'user' ? 'items-end' : 'items-start'"
                            >
                                <div
                                    class="flex max-w-[85%] items-start gap-4 rounded-2xl p-4 text-sm transition-all"
                                    :class="msg.role === 'user' 
                                        ? 'bg-primary text-primary-foreground shadow-sm rounded-tr-none' 
                                        : 'bg-muted/50 border rounded-tl-none'"
                                >
                                    <div class="mt-1 shrink-0">
                                        <User v-if="msg.role === 'user'" class="h-4 w-4" />
                                        <Bot v-else class="h-4 w-4" />
                                    </div>
                                    <div class="flex flex-col gap-3 flex-1 overflow-hidden">
                                        <div class="whitespace-pre-wrap leading-relaxed">
                                            <Markdown v-if="msg.role === 'assistant'" :content="msg.content" />
                                            <template v-else>{{ msg.content }}</template>
                                        </div>
                                        
                                        <!-- Actions -->
                                        <div v-if="msg.pending_actions && msg.pending_actions.length > 0" class="flex flex-col gap-2 mt-2">
                                            <div v-for="(action, actionIdx) in msg.pending_actions" :key="actionIdx" 
                                                 class="rounded-xl border border-border/50 p-4 bg-background/50 shadow-sm text-foreground">
                                                <div class="flex items-center gap-2 mb-3 text-xs font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400">
                                                    <ShieldAlert class="h-3.5 w-3.5" />
                                                    <span>{{ action.type.replace('_', ' ') }} Requested</span>
                                                </div>
                                                <div class="text-[11px] font-mono bg-muted/30 p-3 rounded-lg border mb-4 overflow-x-auto">
                                                    <div class="opacity-70 whitespace-pre">{{ JSON.stringify(action.params, null, 2) }}</div>
                                                </div>

                                                <div v-if="!action.status || action.status === 'pending'" class="flex gap-2">
                                                    <Button size="sm" class="h-9 rounded-lg bg-green-600 hover:bg-green-700 text-white" @click="approveAction(index, actionIdx)">
                                                        <Check class="mr-2 h-4 w-4" /> Approve Execution
                                                    </Button>
                                                    <Button size="sm" variant="ghost" class="h-9 rounded-lg" @click="denyAction(index, actionIdx)">
                                                        <X class="mr-2 h-4 w-4" /> Deny
                                                    </Button>
                                                </div>
                                                
                                                <div v-else-if="action.status === 'executing'" class="flex items-center gap-2 text-xs font-medium animate-pulse">
                                                    <Loader2 class="h-4 w-4 animate-spin text-primary" />
                                                    Executing system command...
                                                </div>

                                                <div v-else-if="action.status === 'success'" class="flex items-center gap-2 text-xs text-green-600 font-bold bg-green-50 dark:bg-green-900/10 p-2 rounded-lg">
                                                    <Check class="h-4 w-4" /> Action executed successfully
                                                </div>

                                                <div v-else-if="action.status === 'error'" class="flex items-center gap-2 text-xs text-red-600 font-bold bg-red-50 dark:bg-red-900/10 p-2 rounded-lg">
                                                    <X class="h-4 w-4" /> {{ action.error || 'Execution failed' }}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div v-if="isLoading && messages.length > 0" class="flex items-center gap-3 animate-pulse px-4">
                                <Bot class="h-4 w-4 opacity-50" />
                                <div class="flex gap-1">
                                    <span class="h-1.5 w-1.5 rounded-full bg-foreground/20 animate-bounce"></span>
                                    <span class="h-1.5 w-1.5 rounded-full bg-foreground/20 animate-bounce [animation-delay:0.2s]"></span>
                                    <span class="h-1.5 w-1.5 rounded-full bg-foreground/20 animate-bounce [animation-delay:0.4s]"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Footer / Input -->
                    <div class="p-6 border-t bg-muted/5 shrink-0">
                        <div class="max-w-3xl mx-auto flex items-center gap-3">
                            <CommandInput
                                v-model="newMessage"
                                @submit="sendMessage"
                                @command="handleCommand"
                                placeholder="Message Arkhein in this session..."
                                :disabled="isLoading"
                                class="flex-1"
                            />
                            <Button @click="sendMessage" :disabled="isLoading || !newMessage.trim()" size="icon" class="h-12 w-12 rounded-xl shadow-lg transition-transform active:scale-95">
                                <Send v-if="!isLoading" class="h-5 w-5" />
                                <Loader2 v-else class="h-5 w-5 animate-spin" />
                            </Button>
                        </div>
                        <p class="text-[10px] text-center text-muted-foreground mt-3 uppercase tracking-tighter opacity-50 font-bold">
                            Arkhein Sovereign Agent • Local Inference Active
                        </p>
                    </div>
                </template>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.rounded-tr-none { border-top-right-radius: 4px; }
.rounded-tl-none { border-top-left-radius: 4px; }
</style>
