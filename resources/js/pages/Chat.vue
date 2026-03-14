<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, nextTick, onMounted } from 'vue';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import CommandInput from '@/components/CommandInput.vue';
import { ScrollArea } from '@/components/ui/scroll-area';
import { MessageSquare, Send, Bot, User, BrainCircuit, Check, X, ShieldAlert, Loader2 } from 'lucide-vue-next';
import { chat } from '@/routes';

interface Message {
    role: 'user' | 'assistant';
    content: string;
    memories_used?: number;
    pending_actions?: any[];
}

const breadcrumbs = [
    {
        title: 'Chat',
        href: chat.url(),
    },
];

const messages = ref<Message[]>([
    {
        role: 'assistant',
        content: 'Hello! I am Arkhein, your private AI assistant. How can I help you today?',
    },
]);

const newMessage = ref('');
const isLoading = ref(false);
const isOllamaOnline = ref(true);
const scrollAreaRef = ref<any>(null);

const checkOllamaStatus = async () => {
    try {
        const response = await axios.get('/ollama/status');
        isOllamaOnline.value = response.data.online;
    } catch (error) {
        isOllamaOnline.value = false;
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
        const response = await axios.post('/chat/action/execute', {
            action: action
        });

        if (response.data.success) {
            action.status = 'success';
        } else {
            action.status = 'error';
            action.error = response.data.error;
        }
    } catch (e) {
        action.status = 'error';
        action.error = "Connection error";
    }
};

const denyAction = (msgIndex: number, actionIndex: number) => {
    const msg = messages.value[msgIndex];
    if (!msg.pending_actions) return;
    msg.pending_actions[actionIndex].status = 'error';
    msg.pending_actions[actionIndex].error = "User denied action";
};

onMounted(() => {
    checkOllamaStatus();
});

const scrollToBottom = async () => {
    await nextTick();
    if (scrollAreaRef.value) {
        const viewport = scrollAreaRef.value.$el.querySelector('[data-radix-scroll-area-viewport]');
        if (viewport) {
            viewport.scrollTop = viewport.scrollHeight;
        }
    }
};

const sendMessage = async () => {
    if (!newMessage.value.trim() || isLoading.value) return;

    const userContent = newMessage.value;
    messages.value.push({ role: 'user', content: userContent });
    newMessage.value = '';
    isLoading.value = true;
    
    await scrollToBottom();

    try {
        const response = await axios.post('/chat/send', {
            message: userContent,
        });

        messages.value.push({
            role: 'assistant',
            content: response.data.message,
            memories_used: response.data.memories_used,
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
</script>

<template>
    <Head title="Chat" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-[calc(100vh-120px)] flex-col gap-4 p-4">
            <div class="flex items-center gap-2 px-2">
                <BrainCircuit class="h-6 w-6 text-primary" />
                <h1 class="text-xl font-semibold tracking-tight">Arkhein Assistant</h1>
                <div class="ml-auto flex items-center gap-2">
                    <span v-if="isOllamaOnline" class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/30 dark:text-green-400">
                        Local Ollama Active
                    </span>
                    <span v-else class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900/30 dark:text-red-400">
                        Ollama Offline
                    </span>
                    <span class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900/30 dark:text-blue-400">
                        Vektor Memory Ready
                    </span>
                </div>
            </div>

            <div v-if="!isOllamaOnline" class="rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-900/50 dark:bg-red-900/10">
                <div class="flex items-center gap-3 text-red-800 dark:text-red-400">
                    <Bot class="h-5 w-5" />
                    <div class="text-sm">
                        <p class="font-medium">Ollama is not running</p>
                        <p class="mt-1">Arkhein requires Ollama for local intelligence. Please download and start Ollama to continue.</p>
                        <a href="https://ollama.com/download" target="_blank" class="mt-2 inline-block text-xs font-semibold underline underline-offset-2">
                            Download Ollama for macOS
                        </a>
                    </div>
                    <Button variant="outline" size="sm" class="ml-auto" @click="checkOllamaStatus">
                        Retry
                    </Button>
                </div>
            </div>

            <ScrollArea ref="scrollAreaRef" class="flex-1 rounded-md border bg-muted/30 p-4">
                <div class="space-y-4">
                    <div
                        v-for="(msg, index) in messages"
                        :key="index"
                        class="flex w-full flex-col gap-2"
                        :class="msg.role === 'user' ? 'items-end' : 'items-start'"
                    >
                        <div
                            class="flex max-w-[80%] items-start gap-3 rounded-lg p-3 text-sm shadow-sm"
                            :class="msg.role === 'user' 
                                ? 'bg-primary text-primary-foreground' 
                                : 'bg-background border'"
                        >
                            <div class="mt-0.5 shrink-0">
                                <User v-if="msg.role === 'user'" class="h-4 w-4" />
                                <Bot v-else class="h-4 w-4" />
                            </div>
                            <div class="flex flex-col gap-3 flex-1 overflow-hidden">
                                <div class="whitespace-pre-wrap leading-relaxed">{{ msg.content }}</div>
                                
                                <!-- Pending Actions Confirmation UI -->
                                <div v-if="msg.pending_actions && msg.pending_actions.length > 0" class="flex flex-col gap-2 mt-2">
                                    <div v-for="(action, actionIdx) in msg.pending_actions" :key="actionIdx" 
                                         class="rounded-md border p-3 bg-muted/50 text-foreground">
                                        <div class="flex items-center gap-2 mb-2 font-medium">
                                            <ShieldAlert class="h-4 w-4 text-amber-500" />
                                            <span>System Action Requested</span>
                                        </div>
                                        <div class="text-[11px] font-mono bg-background/50 p-2 rounded border mb-3 overflow-x-auto">
                                            <div class="text-amber-600 dark:text-amber-400 font-bold mb-1">{{ action.type }}</div>
                                            <div class="opacity-70 whitespace-pre">{{ JSON.stringify(action.params, null, 2) }}</div>
                                        </div>

                                        <div v-if="action.status === 'pending'" class="flex gap-2">
                                            <Button size="sm" class="h-8 bg-green-600 hover:bg-green-700 text-white" @click="approveAction(index, actionIdx)">
                                                <Check class="mr-1 h-3 w-3" /> Approve
                                            </Button>
                                            <Button size="sm" variant="outline" class="h-8" @click="denyAction(index, actionIdx)">
                                                <X class="mr-1 h-3 w-3" /> Deny
                                            </Button>
                                        </div>
                                        
                                        <div v-else-if="action.status === 'executing'" class="flex items-center gap-2 text-xs opacity-70">
                                            <Loader2 class="h-3 w-3 animate-spin" />
                                            Executing action...
                                        </div>

                                        <div v-else-if="action.status === 'success'" class="flex items-center gap-2 text-xs text-green-600 font-bold">
                                            <Check class="h-3 w-3" /> Action executed successfully
                                        </div>

                                        <div v-else-if="action.status === 'error'" class="flex items-center gap-2 text-xs text-red-600 font-bold">
                                            <X class="h-3 w-3" /> {{ action.error || 'Execution failed' }}
                                        </div>
                                    </div>
                                </div>

                                <div v-if="msg.role === 'assistant' && msg.memories_used !== undefined" class="text-[10px] opacity-50 italic">
                                    Context: {{ msg.memories_used }} memories retrieved
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-if="isLoading" class="flex items-start gap-3 rounded-lg border bg-background p-3 text-sm shadow-sm">
                        <Bot class="mt-0.5 h-4 w-4 shrink-0 animate-pulse" />
                        <div class="flex gap-1">
                            <span class="h-2 w-2 animate-bounce rounded-full bg-muted-foreground/40" style="animation-delay: 0ms"></span>
                            <span class="h-2 w-2 animate-bounce rounded-full bg-muted-foreground/40" style="animation-delay: 150ms"></span>
                            <span class="h-2 w-2 animate-bounce rounded-full bg-muted-foreground/40" style="animation-delay: 300ms"></span>
                        </div>
                    </div>
                </div>
            </ScrollArea>

            <form @submit.prevent="sendMessage" class="flex items-center gap-2 pt-2">
                <CommandInput
                    v-model="newMessage"
                    @submit="sendMessage"
                    @command="handleCommand"
                    placeholder="Ask Arkhein anything..."
                    :disabled="isLoading"
                    class="flex-1"
                />
                <Button type="submit" :disabled="isLoading || !newMessage.trim()" size="icon">
                    <Send class="h-4 w-4" />
                    <span class="sr-only">Send message</span>
                </Button>
            </form>
        </div>
    </AppLayout>
</template>
