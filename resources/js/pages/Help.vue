<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, nextTick, onMounted } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/button/Button.vue';
import Markdown from '@/components/Markdown.vue';
import { 
    Send, Bot, User, BrainCircuit,
    Loader2, Sparkles, Eraser
} from 'lucide-vue-next';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    interactions: any[];
}>();

interface Interaction {
    role: 'user' | 'assistant' | 'system';
    content: string;
}

const breadcrumbs = [
    { title: 'Sovereign Archivist', href: '/help' },
];

/**
 * State
 */
const localInteractions = ref<Interaction[]>(props.interactions || []);
const newMessage = ref('');
const isLoading = ref(false);
const isClearing = ref(false);
const isOllamaOnline = ref(true);
const scrollAreaRef = ref<HTMLElement | null>(null);
const statusMessage = ref('Ready');

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

const sendMessage = async () => {
    if (!newMessage.value.trim() || isLoading.value) return;

    const userContent = newMessage.value;
    localInteractions.value.push({ role: 'user', content: userContent });
    
    // Add an empty assistant message to populate as chunks arrive
    const assistantIndex = localInteractions.value.length;
    localInteractions.value.push({ role: 'assistant', content: '' });

    newMessage.value = '';
    isLoading.value = true;
    statusMessage.value = 'Searching Authorized Silos...';
    
    await scrollToBottom();

    try {
        const response = await fetch('/help/stream', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || ''
            },
            body: JSON.stringify({ message: userContent })
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
                    const data = JSON.parse(dataStr);
                    if (data.chunk) {
                        localInteractions.value[assistantIndex].content += data.chunk;
                        statusMessage.value = 'Synthesizing...';
                        scrollToBottom();
                    }
                } catch (e) {
                    // console.error("Error parsing SSE chunk", e);
                }
            }
        }
    } catch (error) {
        console.error("Stream error:", error);
        localInteractions.value[assistantIndex].content = 'Sorry, I encountered an error while processing your request.';
    } finally {
        isLoading.value = false;
        statusMessage.value = 'Ready';
        await scrollToBottom();
    }
};

const clearHistory = async () => {
    if (isClearing.value) return;
    if (!confirm("Clear all help interaction history?")) return;

    isClearing.value = true;
    try {
        await axios.post('/help/clear');
        localInteractions.value = [];
    } catch (e) {
        console.error("Failed to clear help history");
    } finally {
        isClearing.value = false;
    }
};

onMounted(() => {
    checkOllamaStatus();
    scrollToBottom();
});
</script>

<template>
    <Head title="Sovereign Archivist" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col h-[calc(100vh-65px)] bg-background max-w-4xl mx-auto w-full border-x border-border/40">
            
            <!-- Header -->
            <div class="flex items-center justify-between px-6 py-4 border-b bg-background/50 backdrop-blur-md shrink-0">
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-xl bg-primary/10 text-primary">
                        <Bot class="h-5 w-5" />
                    </div>
                    <div>
                        <h1 class="font-bold text-lg leading-none text-foreground/90">Sovereign Archivist</h1>
                        <p class="text-[10px] text-muted-foreground uppercase tracking-widest mt-1 font-black opacity-40">System Intelligence & Global RAG</p>
                    </div>
                </div>
                
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-2">
                        <span v-if="isOllamaOnline" class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-[9px] font-black uppercase text-green-800 dark:bg-green-900/30 dark:text-green-400">
                            Ollama Online
                        </span>
                        <span v-else class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-[9px] font-black uppercase text-red-800 dark:bg-red-900/30 dark:text-red-400">
                            Ollama Offline
                        </span>
                    </div>
                    <Button variant="ghost" size="icon" class="h-8 w-8 rounded-xl text-muted-foreground hover:text-destructive transition-colors" @click="clearHistory" title="Clear History">
                        <Eraser class="h-4 w-4" />
                    </Button>
                </div>
            </div>

            <!-- Chat Body -->
            <div ref="scrollAreaRef" class="flex-1 overflow-y-auto p-6 space-y-8 scroll-smooth">
                <div v-if="localInteractions.length === 0" class="h-full flex flex-col items-center justify-center text-center opacity-40 py-20">
                    <div class="p-6 rounded-[2.5rem] bg-muted/40 mb-6 border border-border/40 shadow-inner">
                        <BrainCircuit class="h-10 w-10 text-primary" />
                    </div>
                    <h2 class="text-2xl font-black uppercase tracking-tighter">Arkhein Intelligence</h2>
                    <p class="text-xs max-w-sm mt-3 leading-relaxed font-medium">I am the **Sovereign Archivist**. Ask me anything about the Vantage Hub, Memory Architecture, or search across your authorized silos.</p>
                </div>

                <div
                    v-for="(interaction, index) in localInteractions"
                    :key="index"
                    class="flex w-full flex-col gap-3"
                    :class="interaction.role === 'user' ? 'items-end' : 'items-start'"
                >
                    <div class="flex items-center gap-2 px-1">
                        <span class="text-[9px] font-black uppercase tracking-[0.2em] opacity-30">
                            {{ interaction.role === 'user' ? 'Operator' : 'Sovereign Archivist' }}
                        </span>
                    </div>
                    <div
                        class="flex max-w-[90%] items-start gap-4 rounded-[2rem] p-5 text-sm transition-all shadow-sm"
                        :class="interaction.role === 'user' 
                            ? 'bg-primary text-primary-foreground rounded-tr-none' 
                            : 'bg-muted/40 border border-border/50 rounded-tl-none'"
                    >
                        <div class="flex flex-col gap-3 flex-1 overflow-hidden leading-relaxed">
                            <Markdown v-if="interaction.role === 'assistant'" :content="interaction.content" />
                            <template v-else>{{ interaction.content }}</template>
                        </div>
                    </div>
                </div>

                <div v-if="isLoading" class="flex items-center gap-3 px-4">
                    <Loader2 class="h-3 w-3 animate-spin text-primary opacity-50" />
                    <span class="text-[9px] font-black uppercase tracking-widest opacity-30">{{ statusMessage }}</span>
                </div>
            </div>

            <!-- Footer / Input -->
            <div class="p-6 border-t bg-muted/5 shrink-0">
                <div class="max-w-3xl mx-auto flex items-center gap-3">
                    <div class="relative flex-1 group">
                        <Input
                            v-model="newMessage"
                            @keydown.enter="sendMessage"
                            placeholder="Query the sovereign archive..."
                            :disabled="isLoading"
                            class="h-14 rounded-2xl border-border/40 bg-background pl-6 pr-14 shadow-inner focus-visible:ring-primary/20 transition-all group-hover:border-primary/30"
                        />
                        <div class="absolute right-2 top-1/2 -translate-y-1/2">
                            <Button @click="sendMessage" :disabled="isLoading || !newMessage.trim()" size="icon" class="h-10 w-10 rounded-xl shadow-lg transition-all active:scale-90 bg-primary hover:bg-primary/90">
                                <Send v-if="!isLoading" class="h-4 w-4" />
                                <Loader2 v-else class="h-4 w-4 animate-spin" />
                            </Button>
                        </div>
                    </div>
                </div>
                <p class="text-[9px] text-center text-muted-foreground mt-4 uppercase tracking-[0.2em] opacity-40 font-black">
                    Sovereign Architecture • Documentation Protocol
                </p>
            </div>
        </div>
    </AppLayout>
</template>
