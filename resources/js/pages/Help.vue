<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, nextTick, onMounted } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/button/Button.vue';
import { ScrollArea } from '@/components/ui/scroll-area';
import Markdown from '@/components/Markdown.vue';
import { 
    MessageSquare, Send, Bot, User, HelpCircle,
    Loader2, Plus, History, Sparkles
} from 'lucide-vue-next';

import { 
    Dialog, DialogContent, DialogDescription, DialogFooter, 
    DialogHeader, DialogTitle
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    sessions: any[];
}>();

interface Interaction {
    role: 'user' | 'assistant' | 'system';
    content: string;
}

const breadcrumbs = [
    { title: 'System Help', href: '/help' },
];

/**
 * Session State
 */
const activeSession = ref<any>(null);
const localSessions = ref([...props.sessions]);
const interactions = ref<Interaction[]>([]);
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
const startNewSession = () => {
    newSessionTitle.value = '';
    isNewSessionModalOpen.value = true;
};

const createSession = async () => {
    if (!newSessionTitle.value.trim()) return;

    try {
        const response = await axios.post('/help/start', { title: newSessionTitle.value });
        activeSession.value = response.data;
        localSessions.value.unshift(response.data);
        interactions.value = [
            { role: 'assistant', content: `Help Session "${newSessionTitle.value}" initialized. I am the Arkhein System Guide. How can I help you understand the architecture today?` }
        ];
        isNewSessionModalOpen.value = false;
    } catch (e) {
        console.error("Failed to start session");
    }
};

const loadSession = async (session: any) => {
    activeSession.value = session;
    isLoading.value = true;
    try {
        const response = await axios.get(`/help/history/${session.id}`);
        interactions.value = response.data.interactions.map((m: any) => ({
            role: m.role,
            content: m.content
        }));
        await scrollToBottom();
    } catch (e) {
        console.error("Failed to load history");
    } finally {
        isLoading.value = false;
    }
};

const sendMessage = async () => {
    if (!newMessage.value.trim() || isLoading.value || !activeSession.value) return;

    const userContent = newMessage.value;
    interactions.value.push({ role: 'user', content: userContent });
    newMessage.value = '';
    isLoading.value = true;
    
    await scrollToBottom();

    try {
        const response = await axios.post('/help/send', {
            message: userContent,
            session_id: activeSession.value.id
        });

        interactions.value.push({
            role: 'assistant',
            content: response.data.message
        });
    } catch (error) {
        interactions.value.push({
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
    <Head title="System Help" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-[calc(100vh-65px)] overflow-hidden">
            
            <!-- New Session Dialog -->
            <Dialog v-model:open="isNewSessionModalOpen">
                <DialogContent class="sm:max-w-[425px]">
                    <DialogHeader>
                        <DialogTitle>New Help Session</DialogTitle>
                        <DialogDescription>
                            Name this session (e.g., "Understanding Vantage", "Settings Guide").
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-4 py-4">
                        <div class="grid grid-cols-4 items-center gap-4">
                            <Label for="name" class="text-right">Topic</Label>
                            <Input
                                id="name"
                                v-model="newSessionTitle"
                                placeholder="e.g. Architecture Overview"
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
                    <Button variant="outline" class="w-full justify-start gap-2" @click="startNewSession">
                        <Plus class="h-4 w-4" />
                        New Question
                    </Button>
                </div>
                <ScrollArea class="flex-1">
                    <div class="p-2 space-y-1">
                        <button
                            v-for="session in localSessions"
                            :key="session.id"
                            @click="loadSession(session)"
                            class="w-full flex items-center gap-3 px-3 py-2 text-sm rounded-md text-left transition-colors"
                            :class="activeSession?.id === session.id ? 'bg-primary text-primary-foreground shadow-sm' : 'hover:bg-muted text-muted-foreground'"
                        >
                            <MessageSquare class="h-4 w-4 shrink-0" />
                            <span class="truncate">{{ session.title || 'Untitled Session' }}</span>
                        </button>
                    </div>
                </ScrollArea>
            </div>

            <!-- Main Chat Area -->
            <div class="flex-1 flex flex-col min-w-0 bg-background h-full">
                <!-- Header -->
                <div class="flex items-center gap-2 px-6 py-3 border-b bg-background/50 backdrop-blur-md shrink-0">
                    <HelpCircle class="h-5 w-5 text-primary" />
                    <h1 class="font-semibold truncate">
                        {{ activeSession ? activeSession.title : 'System Help Guide' }}
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
                <div v-if="!activeSession" class="flex-1 flex flex-col items-center justify-center text-center p-8 opacity-50">
                    <div class="h-12 w-12 rounded-full bg-muted flex items-center justify-center mb-4">
                        <Sparkles class="h-6 w-6 text-primary" />
                    </div>
                    <h2 class="text-lg font-medium">Arkhein Documentation</h2>
                    <p class="text-sm max-w-xs mt-1">Select a previous session or start a new one to ask questions about how the Arkhein system works.</p>
                    <Button variant="secondary" class="mt-6" @click="startNewSession">Ask a Question</Button>
                </div>

                <template v-else>
                    <!-- Standard Scrollable Div -->
                    <div ref="scrollAreaRef" class="flex-1 w-full overflow-y-auto overflow-x-hidden">
                        <div class="max-w-3xl mx-auto p-6 space-y-6 pb-4">
                            <div
                                v-for="(interaction, index) in interactions"
                                :key="index"
                                class="flex w-full flex-col gap-2"
                                :class="interaction.role === 'user' ? 'items-end' : 'items-start'"
                            >
                                <div
                                    class="flex max-w-[85%] items-start gap-4 rounded-2xl p-4 text-sm transition-all"
                                    :class="interaction.role === 'user' 
                                        ? 'bg-primary text-primary-foreground shadow-sm rounded-tr-none' 
                                        : 'bg-muted/50 border rounded-tl-none'"
                                >
                                    <div class="mt-1 shrink-0">
                                        <User v-if="interaction.role === 'user'" class="h-4 w-4" />
                                        <Bot v-else class="h-4 w-4" />
                                    </div>
                                    <div class="flex flex-col gap-3 flex-1 overflow-hidden">
                                        <div class="whitespace-pre-wrap leading-relaxed">
                                            <Markdown v-if="interaction.role === 'assistant'" :content="interaction.content" />
                                            <template v-else>{{ interaction.content }}</template>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div v-if="isLoading && interactions.length > 0" class="flex items-center gap-3 animate-pulse px-4">
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
                            <Input
                                v-model="newMessage"
                                @keydown.enter="sendMessage"
                                placeholder="Ask about Arkhein's features or architecture..."
                                :disabled="isLoading"
                                class="flex-1 h-12 rounded-xl border-none shadow-sm focus-visible:ring-1 focus-visible:ring-primary/20"
                            />
                            <Button @click="sendMessage" :disabled="isLoading || !newMessage.trim()" size="icon" class="h-12 w-12 rounded-xl shadow-lg transition-transform active:scale-95">
                                <Send v-if="!isLoading" class="h-5 w-5" />
                                <Loader2 v-else class="h-5 w-5 animate-spin" />
                            </Button>
                        </div>
                        <p class="text-[10px] text-center text-muted-foreground mt-3 uppercase tracking-tighter opacity-50 font-bold">
                            Arkhein System Guide • Local Inference Active
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
