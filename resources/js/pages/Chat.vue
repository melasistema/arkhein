<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, nextTick } from 'vue';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ScrollArea } from '@/components/ui/scroll-area';
import { MessageSquare, Send, Bot, User, BrainCircuit } from 'lucide-vue-next';
import { chat } from '@/routes';

interface Message {
    role: 'user' | 'assistant';
    content: string;
    memories_used?: number;
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

// Check status on mount
import { onMounted } from 'vue';
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
                            <div class="flex flex-col gap-1">
                                <div class="whitespace-pre-wrap leading-relaxed">{{ msg.content }}</div>
                                <div v-if="msg.role === 'assistant' && msg.memories_used !== undefined" class="mt-1 text-[10px] opacity-50 italic">
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
                <Input
                    v-model="newMessage"
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
