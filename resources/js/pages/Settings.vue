<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { Settings as SettingsIcon, Save, Sparkles, BrainCircuit, Ruler, AlertTriangle, FolderPlus, Trash2, ShieldCheck, RefreshCw, Palette, Lock, Unlock, Info, Zap, Database } from 'lucide-vue-next';
import { ref, computed, onMounted, onUnmounted } from 'vue';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Button from '@/components/ui/button/Button.vue';
import Card from '@/components/ui/card/Card.vue';
import CardContent from '@/components/ui/card/CardContent.vue';
import CardDescription from '@/components/ui/card/CardDescription.vue';
import CardHeader from '@/components/ui/card/CardHeader.vue';
import CardTitle from '@/components/ui/card/CardTitle.vue';
import Input from '@/components/ui/input/Input.vue';
import Label from '@/components/ui/label/Label.vue';
import Select from '@/components/ui/select/Select.vue';
import SelectContent from '@/components/ui/select/SelectContent.vue';
import SelectItem from '@/components/ui/select/SelectItem.vue';
import SelectTrigger from '@/components/ui/select/SelectTrigger.vue';
import SelectValue from '@/components/ui/select/SelectValue.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { settings } from '@/routes';

const props = defineProps<{
    models: any[];
    is_ollama_online: boolean;
    folders: any[];
    is_optimized: boolean;
    recommended: {
        llm: string;
        embedding: string;
        dimensions: number;
    };
    current: {
        llm_model: string;
        embedding_model: string;
        embedding_dimensions: number;
    };
}>();

const breadcrumbs = [
    {
        title: 'Settings',
        href: settings.url(),
    },
];

const syncing = ref(false);
const recentlySynced = ref(false);
const isRebuilding = ref(false);
const isSettingsLocked = ref(true); // Locked by default for safety
const foldersList = ref([...props.folders]);
const pollInterval = ref<any>(null);

// Check if models are present in Ollama's local tags
const isRecommendedEfficientInstalled = computed(() => props.models.some(m => m.name === 'mistral' || m.name === 'mistral:latest'));
const isRecommendedEfficientEmbeddingInstalled = computed(() => props.models.some(m => m.name === 'nomic-embed-text' || m.name === 'nomic-embed-text:latest'));

const isRecommendedEliteInstalled = computed(() => props.models.some(m => m.name === 'qwen3:8b' || m.name === 'qwen3:8b:latest'));
const isRecommendedEliteEmbeddingInstalled = computed(() => props.models.some(m => m.name === 'qwen3-embedding:4b' || m.name === 'qwen3-embedding:4b:latest'));

const showOnboardingWarning = computed(() => {
    return !isRecommendedEfficientInstalled.value || !isRecommendedEfficientEmbeddingInstalled.value;
});

// Check if ANY folder is currently indexing
const isAnyFolderIndexing = computed(() => {
    return foldersList.value.some(f => f.is_indexing);
});

// The master busy state
const isBusy = computed(() => syncing.value || isAnyFolderIndexing.value || isRebuilding.value);

const toggleLock = () => {
    if (!isSettingsLocked.value) {
        isSettingsLocked.value = true;
    } else {
        if (confirm("Advanced: Changing these settings can clear Arkhein's long-term memory. Continue?")) {
            isSettingsLocked.value = false;
        }
    }
};

const optimizeConfiguration = () => {
    form.llm_model = props.recommended.llm;
    form.embedding_model = props.recommended.embedding;
    form.embedding_dimensions = props.recommended.dimensions;
    isSettingsLocked.value = false;
};

const findBestMatch = (baseName: string) => {
    const installed = props.models.map(m => m.name);
    if (installed.includes(baseName)) return baseName;
    if (installed.includes(`${baseName}:latest`)) return `${baseName}:latest`;
    // Fallback to the base name if nothing found, so the select remains consistent
    return baseName;
};

const setComputeProfile = (profile: 'efficient' | 'elite') => {
    if (profile === 'efficient') {
        form.llm_model = findBestMatch('mistral');
        form.embedding_model = findBestMatch('nomic-embed-text');
        form.embedding_dimensions = 768;
    } else {
        form.llm_model = findBestMatch('qwen3:8b');
        form.embedding_model = findBestMatch('qwen3-embedding:4b');
        form.embedding_dimensions = 1056;
    }
    isSettingsLocked.value = false;
};

const rebuildIndex = async () => {
    if (isBusy.value) {
return;
}
    
    isRebuilding.value = true;

    try {
        await axios.post('/settings/rebuild');
        // We show it's done after a short delay since it's background or fast
        setTimeout(() => {
            isRebuilding.value = false;
        }, 2000);
    } catch (e) {
        console.error("Rebuild failed", e);
        isRebuilding.value = false;
    }
};

const pollStatus = async () => {
    try {
        const res = await axios.get('/settings');
        foldersList.value = res.data.folders;
        
        // If nothing is indexing anymore, clear the interval
        if (!isAnyFolderIndexing.value) {
            stopPolling();
        }
    } catch (e) {
        console.error("Polling failed", e);
        stopPolling();
    }
};

const startPolling = () => {
    if (pollInterval.value) {
return;
}

    pollInterval.value = setInterval(pollStatus, 3000);
};

const stopPolling = () => {
    if (pollInterval.value) {
        clearInterval(pollInterval.value);
        pollInterval.value = null;
    }
};

onMounted(() => {
    if (isAnyFolderIndexing.value) {
        startPolling();
    }
});

onUnmounted(() => {
    stopPolling();
});

const form = useForm({
    llm_model: props.current.llm_model,
    embedding_model: props.current.embedding_model,
    embedding_dimensions: props.current.embedding_dimensions,
});

const isMemoryResetRequired = computed(() => {
    return form.embedding_model !== props.current.embedding_model || 
           Number(form.embedding_dimensions) !== Number(props.current.embedding_dimensions);
});

const submit = () => {
    form.post('/settings', {
        preserveScroll: true,
    });
};

const syncFolders = () => {
    if (isBusy.value) {
return;
}

    syncing.value = true;
    useForm({}).post('/settings/sync', {
        preserveScroll: true,
        onSuccess: () => {
            recentlySynced.value = true;
            startPolling();
            setTimeout(() => {
                recentlySynced.value = false;
            }, 5000);
        },
        onFinish: () => {
            syncing.value = false;
        }
    });
};

const addFolder = () => {
    useForm({}).post('/settings/folders', {
        preserveScroll: true,
        onFinish: () => {
            axios.get('/settings').then(res => foldersList.value = res.data.folders);
        }
    });
};

const removeFolder = (id: number) => {
    if (isBusy.value) {
return;
}

    useForm({}).delete(`/settings/folders/${id}`, {
        preserveScroll: true,
        onFinish: () => {
            foldersList.value = foldersList.value.filter(f => f.id !== id);
        }
    });
};
</script>

<template>
    <Head title="Settings" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-8 p-8 overflow-y-auto max-w-6xl mx-auto w-full">
            
            <div class="flex items-center gap-3">
                <div class="p-2 rounded-xl bg-primary/10 text-primary">
                    <SettingsIcon class="h-6 w-6" />
                </div>
                <div>
                    <h1 class="text-3xl font-bold tracking-tight">System Settings</h1>
                    <p class="text-sm text-muted-foreground">Configure Arkhein's intelligence, memory, and appearance.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
                
                <!-- Left Column: Core Intelligence -->
                <div class="flex flex-col gap-8">
                    <Card>
                        <CardHeader>
                            <div class="flex items-center justify-between">
                                <CardTitle class="flex items-center gap-2">
                                    <Sparkles class="h-5 w-5 text-yellow-500" />
                                    AI Models
                                </CardTitle>
                                <div v-if="is_optimized" class="flex items-center gap-1.5 px-2 py-1 rounded-full bg-green-500/10 text-[10px] text-green-600 font-black uppercase tracking-tighter border border-green-500/20">
                                    <Zap class="h-2.5 w-2.5 fill-current" />
                                    Optimized for Arkhein
                                </div>
                                <div v-else @click="optimizeConfiguration" class="flex items-center gap-1.5 px-2 py-1 rounded-full bg-muted text-[10px] text-muted-foreground font-black uppercase tracking-tighter border border-border cursor-pointer hover:bg-primary/5 hover:text-primary transition-colors">
                                    Custom Config
                                </div>
                            </div>
                            <CardDescription>
                                Configure which local Ollama models Arkhein uses for conversation and memory.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <!-- 0. Compute Profile Selection -->
                            <div v-if="is_ollama_online" class="mb-6 grid grid-cols-2 gap-3">
                                <button 
                                    type="button"
                                    @click="setComputeProfile('efficient')"
                                    class="flex flex-col gap-2 p-4 rounded-2xl border transition-all text-left"
                                    :class="form.llm_model === 'mistral' ? 'bg-primary/5 border-primary shadow-sm' : 'bg-muted/30 border-border/50 opacity-60 hover:opacity-100'"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="text-[10px] font-black uppercase tracking-widest text-primary">Efficient</span>
                                        <Zap v-if="form.llm_model === 'mistral'" class="h-3 w-3 text-primary fill-current" />
                                    </div>
                                    <span class="text-xs font-bold">Mistral + Nomic</span>
                                    <span class="text-[9px] leading-tight text-muted-foreground">Standard Mac (8GB-16GB RAM). Balanced speed and accuracy.</span>
                                </button>

                                <button 
                                    type="button"
                                    @click="setComputeProfile('elite')"
                                    class="flex flex-col gap-2 p-4 rounded-2xl border transition-all text-left"
                                    :class="form.llm_model === 'qwen3:8b' ? 'bg-indigo-500/5 border-indigo-500 shadow-sm' : 'bg-muted/30 border-border/50 opacity-60 hover:opacity-100'"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="text-[10px] font-black uppercase tracking-widest text-indigo-500">Elite</span>
                                        <Sparkles v-if="form.llm_model === 'qwen3:8b'" class="h-3 w-3 text-indigo-500 fill-current" />
                                    </div>
                                    <span class="text-xs font-bold">Qwen3 Suite</span>
                                    <span class="text-[9px] leading-tight text-muted-foreground">Pro/Max Specs (32GB+ RAM). Superior analytical reasoning.</span>
                                </button>
                            </div>

                            <!-- 1. Ollama Offline State -->
                            <div v-if="!is_ollama_online" class="p-6 rounded-[2rem] bg-red-500/5 border border-red-500/20 flex flex-col items-center text-center gap-6 animate-in fade-in zoom-in-95 duration-500">
                                <div class="p-4 rounded-3xl bg-red-500/10 text-red-500 shadow-inner">
                                    <AlertTriangle class="h-8 w-8" />
                                </div>
                                <div class="space-y-2">
                                    <h3 class="text-lg font-black uppercase tracking-tighter text-red-600">Inference Engine Offline</h3>
                                    <p class="text-xs text-muted-foreground leading-relaxed max-w-sm">
                                        Arkhein cannot connect to **Ollama** on `localhost:11434`. This is required for all local intelligence and memory operations.
                                    </p>
                                </div>
                                
                                <div class="grid grid-cols-1 gap-3 w-full">
                                    <a href="https://ollama.com/download" target="_blank" class="w-full">
                                        <Button variant="outline" class="w-full h-11 rounded-2xl border-red-500/20 hover:bg-red-500/10 text-red-600 font-bold gap-2">
                                            <Database class="h-4 w-4" />
                                            Download Ollama for macOS
                                        </Button>
                                    </a>
                                    <Button @click="() => $inertia.reload()" class="w-full h-11 rounded-2xl gap-2 font-bold shadow-lg shadow-primary/20">
                                        <RefreshCw class="h-4 w-4" />
                                        Retry Connection
                                    </Button>
                                </div>

                                <p class="text-[10px] opacity-40 uppercase tracking-widest font-black">
                                    Sovereign Architecture • Zero-Cloud Policy
                                </p>
                            </div>

                            <!-- 2. Normal Onboarding / Configuration UI -->
                            <template v-else>
                                <!-- Compute Profile Guide -->
                                <div class="mb-6 p-4 rounded-2xl bg-primary/5 border border-primary/10 flex flex-col gap-4">
                                    <div class="flex items-center gap-2 text-primary">
                                        <Database class="h-4 w-4" />
                                        <span class="text-xs font-bold uppercase tracking-wider">Ollama Model Guide</span>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="space-y-2">
                                            <span class="text-[9px] font-black uppercase tracking-widest opacity-50">Efficient (Standard)</span>
                                            <div class="p-2.5 rounded-xl bg-background border border-border/50 font-mono text-[9px] space-y-1">
                                                <div class="flex justify-between">
                                                    <span class="opacity-70">ollama pull mistral</span>
                                                    <ShieldCheck v-if="isRecommendedEfficientInstalled" class="h-2.5 w-2.5 text-green-500" />
                                                </div>
                                                <div class="flex justify-between">
                                                    <span class="opacity-70">ollama pull nomic-embed-text</span>
                                                    <ShieldCheck v-if="isRecommendedEfficientEmbeddingInstalled" class="h-2.5 w-2.5 text-green-500" />
                                                </div>
                                            </div>
                                        </div>
                                        <div class="space-y-2">
                                            <span class="text-[9px] font-black uppercase tracking-widest opacity-50">Elite (High-Precision)</span>
                                            <div class="p-2.5 rounded-xl bg-background border border-border/50 font-mono text-[9px] space-y-1">
                                                <div class="flex justify-between">
                                                    <span class="opacity-70">ollama pull qwen3:8b</span>
                                                    <ShieldCheck v-if="isRecommendedEliteInstalled" class="h-2.5 w-2.5 text-green-500" />
                                                </div>
                                                <div class="flex justify-between">
                                                    <span class="opacity-70">ollama pull qwen3-embedding:4b</span>
                                                    <ShieldCheck v-if="isRecommendedEliteEmbeddingInstalled" class="h-2.5 w-2.5 text-green-500" />
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <form @submit.prevent="submit" class="space-y-6">
                                
                                <div v-if="isMemoryResetRequired && !isSettingsLocked" class="rounded-2xl bg-amber-500/5 p-4 border border-amber-500/20">
                                    <div class="flex gap-3">
                                        <AlertTriangle class="h-5 w-5 text-amber-500 shrink-0" />
                                        <div class="space-y-1">
                                            <h3 class="text-xs font-bold text-amber-600 uppercase">Memory Reset Required</h3>
                                            <p class="text-[11px] text-muted-foreground leading-relaxed">Changing these settings will clear your current vector index to prevent dimension mismatch.</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="space-y-4" :class="{ 'opacity-50 pointer-events-none select-none': isSettingsLocked }">
                                    <div class="space-y-2">
                                        <Label for="llm_model">Primary Assistant Model (LLM)</Label>
                                        <Select v-model="form.llm_model">
                                            <SelectTrigger class="rounded-xl">
                                                <SelectValue placeholder="Select a model" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem v-for="model in models" :key="model.name" :value="model.name">
                                                    {{ model.name }}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <p class="text-[10px] text-muted-foreground">Recommended: {{ recommended.llm }}</p>
                                    </div>

                                    <div class="space-y-2">
                                        <Label for="embedding_model">Embedding Model</Label>
                                        <Select v-model="form.embedding_model">
                                            <SelectTrigger class="rounded-xl">
                                                <SelectValue placeholder="Select a model" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem v-for="model in models" :key="model.name" :value="model.name">
                                                    {{ model.name }}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <p class="text-[10px] text-muted-foreground">Recommended: {{ recommended.embedding }}</p>
                                    </div>

                                    <div class="space-y-2">
                                        <Label for="embedding_dimensions" class="flex items-center gap-2">
                                            <Ruler class="h-4 w-4" />
                                            Embedding Dimensions
                                        </Label>
                                        <Input
                                            id="embedding_dimensions"
                                            type="number"
                                            v-model="form.embedding_dimensions"
                                            class="rounded-xl"
                                        />
                                        <p class="text-[10px] text-muted-foreground">
                                            Default for {{ recommended.embedding }} is {{ recommended.dimensions }}.
                                        </p>
                                    </div>
                                </div>

                                <div class="pt-4 flex items-center justify-between gap-4">
                                    <div class="flex gap-2">
                                        <Button type="button" variant="outline" size="sm" class="rounded-xl px-4 h-9" @click="toggleLock">
                                            <component :is="isSettingsLocked ? Lock : Unlock" class="mr-2 h-3.5 w-3.5" />
                                            {{ isSettingsLocked ? 'Unlock Settings' : 'Lock for Safety' }}
                                        </Button>
                                        
                                        <Button v-if="!isSettingsLocked && !is_optimized" type="button" variant="ghost" size="sm" class="rounded-xl px-4 h-9 text-[10px] font-bold uppercase opacity-70 hover:opacity-100" @click="optimizeConfiguration">
                                            Restore Defaults
                                        </Button>
                                    </div>

                                    <div v-if="!isSettingsLocked" class="flex items-center gap-3">
                                        <div v-if="form.recentlySuccessful" class="text-[10px] text-green-600 font-bold uppercase animate-in fade-in">
                                            Saved!
                                        </div>
                                        <Button type="submit" class="rounded-xl px-6 h-9" :disabled="form.processing">
                                            <Save class="mr-2 h-3.5 w-3.5" />
                                            Save Changes
                                        </Button>
                                    </div>
                                </div>
                            </form>
                            </template>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2 text-blue-600">
                                <BrainCircuit class="h-5 w-5" />
                                Memory Architecture
                            </CardTitle>
                            <CardDescription>
                                Arkhein uses a dual-layer memory system for sovereignty and speed.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-6">
                                <!-- SQLite Layer (SSOT) -->
                                <div class="p-4 rounded-2xl bg-muted/40 border border-border/50 flex flex-col gap-3">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <div class="p-1.5 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400">
                                                <Database class="h-4 w-4" />
                                            </div>
                                            <span class="text-xs font-bold uppercase tracking-wider">Primary SSOT</span>
                                        </div>
                                        <span class="text-[10px] font-mono bg-blue-500/10 text-blue-600 px-2 py-0.5 rounded-full">SQLite</span>
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <span class="text-[10px] text-muted-foreground leading-relaxed">
                                            The Single Source of Truth where all embeddings, text, and metadata are persisted.
                                        </span>
                                        <span class="text-[9px] font-mono text-muted-foreground truncate opacity-60">database: nativephp.sqlite</span>
                                    </div>
                                </div>

                                <!-- Vektor Layer (Accelerator) -->
                                <div class="p-4 rounded-2xl bg-muted/40 border border-border/50 flex flex-col gap-3">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <div class="p-1.5 rounded-lg bg-orange-500/10 text-orange-600 dark:text-orange-400">
                                                <RefreshCw class="h-4 w-4" />
                                            </div>
                                            <span class="text-xs font-bold uppercase tracking-wider">Vektor Accelerator</span>
                                        </div>
                                        <span class="text-[10px] font-mono bg-orange-500/10 text-orange-600 px-2 py-0.5 rounded-full">Binary Index</span>
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <span class="text-[10px] text-muted-foreground leading-relaxed">
                                            A high-speed binary index rebuilt automatically from the SSOT if corrupted or missing.
                                        </span>
                                        <span class="text-[9px] font-mono text-muted-foreground truncate opacity-60">path: storage/app/vektor/vector.bin</span>
                                    </div>
                                </div>

                                <div class="pt-2">
                                    <Button 
                                        variant="outline" 
                                        size="sm" 
                                        class="w-full rounded-xl text-xs h-9 border-dashed"
                                        @click="rebuildIndex"
                                        :disabled="isBusy"
                                    >
                                        <RefreshCw class="mr-2 h-3 w-3" :class="{ 'animate-spin': isRebuilding }" />
                                        {{ isRebuilding ? 'Rebuilding Index...' : 'Force Index Reconciliation' }}
                                    </Button>
                                    <p class="text-[9px] text-muted-foreground text-center mt-3 px-4 italic">
                                        Reconciles the binary accelerator with the SQLite Source of Truth.
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <!-- Right Column: Environment & UI -->
                <div class="flex flex-col gap-8">
                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <Palette class="h-5 w-5 text-indigo-500" />
                                Appearance
                            </CardTitle>
                            <CardDescription>
                                Choose your preferred theme for the Arkhein interface.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <AppearanceTabs />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <ShieldCheck class="h-5 w-5 text-green-600" />
                                Permissions & Managed Folders
                            </CardTitle>
                            <CardDescription>
                                Arkhein only indexes files in folders you explicitly authorize here.
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div v-if="foldersList.length === 0" class="text-sm text-muted-foreground italic py-8 text-center border-2 border-dashed rounded-[1.5rem] bg-muted/20">
                                No folders authorized. Add one to begin indexing your local archive.
                            </div>
                            <div v-else class="space-y-3">
                                <div v-for="folder in foldersList" :key="folder.id" class="flex flex-col gap-3 p-4 rounded-2xl bg-muted/30 border border-border/50">
                                    <div class="flex items-center justify-between">
                                        <div class="flex flex-col gap-0.5 overflow-hidden">
                                            <div class="flex items-center gap-2">
                                                <span class="text-sm font-semibold truncate">{{ folder.name }}</span>
                                                <span v-if="folder.is_indexing" class="flex items-center gap-1 text-[9px] text-primary font-black uppercase">
                                                    <RefreshCw class="h-2 w-2 animate-spin" />
                                                    Indexing {{ folder.indexing_progress }}%
                                                </span>
                                            </div>
                                            <span class="text-[10px] text-muted-foreground truncate">{{ folder.path }}</span>
                                        </div>
                                        <Button variant="ghost" size="icon" class="h-8 w-8 text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-xl" @click="removeFolder(folder.id)" :disabled="isBusy">
                                            <Trash2 class="h-4 w-4" />
                                        </Button>
                                    </div>

                                    <!-- Progress Bar -->
                                    <div v-if="folder.is_indexing" class="space-y-1.5">
                                        <div class="flex items-center justify-between text-[9px] uppercase tracking-widest font-bold opacity-50">
                                            <span class="truncate max-w-[200px] italic">Processing: {{ folder.current_indexing_file || 'Starting...' }}</span>
                                            <span>{{ folder.indexing_progress }}%</span>
                                        </div>
                                        <div class="h-1.5 w-full bg-primary/10 rounded-full overflow-hidden">
                                            <div 
                                                class="h-full bg-primary transition-all duration-500 ease-out"
                                                :style="{ width: folder.indexing_progress + '%' }"
                                            ></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="pt-4 flex flex-col gap-3">
                                <div class="flex gap-3">
                                    <Button variant="outline" class="flex-1 rounded-xl" @click="addFolder" :disabled="isBusy">
                                        <FolderPlus class="mr-2 h-4 w-4" />
                                        Authorize Folder
                                    </Button>
                                    <Button 
                                        variant="secondary" 
                                        class="flex-1 rounded-xl"
                                        :disabled="isBusy || foldersList.length === 0" 
                                        @click="syncFolders"
                                    >
                                        <RefreshCw 
                                            v-if="isBusy" 
                                            class="mr-2 h-4 w-4 animate-spin" 
                                        />
                                        <RefreshCw 
                                            v-else
                                            class="mr-2 h-4 w-4" 
                                        />
                                        {{ isBusy ? 'Syncing...' : 'Sync All' }}
                                    </Button>
                                </div>
                                <p v-if="recentlySynced" class="text-[10px] text-green-600 font-bold text-center animate-in fade-in slide-in-from-top-1">
                                    Background indexing dispatched.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

            </div>
        </div>
    </AppLayout>
</template>
