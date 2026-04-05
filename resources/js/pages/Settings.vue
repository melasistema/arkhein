<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import {
    Settings as SettingsIcon,
    Save,
    Sparkles,
    BrainCircuit,
    Ruler,
    AlertTriangle,
    FolderPlus,
    Trash2,
    ShieldCheck,
    RefreshCw,
    Palette,
    Lock,
    Unlock,
    Info,
    Zap,
    Database,
    Eye,
    EyeOff,
    ScanEye,
} from 'lucide-vue-next';
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
    reconcile: {
        status: string;
        progress: number;
        last_at: string;
    };
    recommended: {
        llm: string;
        vision: string;
        embedding: string;
        dimensions: number;
    };
    current: {
        llm_model: string;
        vision_model: string;
        vision_enabled: boolean;
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
const isRebuilding = ref(props.reconcile.status === 'running');
const reconcileProgress = ref(props.reconcile.progress);
const isSettingsLocked = ref(true); // Locked by default for safety
const foldersList = ref([...props.folders]);
const pollInterval = ref<any>(null);

// Helpers for flexible matching
const clean = (name: string) => name.replace(':latest', '');

// Check if models are present in Ollama's local tags
const isRecommendedEfficientInstalled = computed(() =>
    props.models.some((m) => clean(m.name) === 'mistral'),
);
const isRecommendedVisionInstalled = computed(() =>
    props.models.some((m) => clean(m.name) === 'qwen3-vl'),
);
const isRecommendedEfficientEmbeddingInstalled = computed(() =>
    props.models.some((m) => clean(m.name) === 'nomic-embed-text'),
);

const isRecommendedEliteInstalled = computed(() =>
    props.models.some((m) => clean(m.name) === 'qwen3:8b'),
);
const isRecommendedEliteEmbeddingInstalled = computed(() =>
    props.models.some((m) => clean(m.name) === 'qwen3-embedding:4b'),
);

const showOnboardingWarning = computed(() => {
    return (
        !isRecommendedEfficientInstalled.value ||
        !isRecommendedVisionInstalled.value ||
        !isRecommendedEfficientEmbeddingInstalled.value
    );
});

const isSetupComplete = computed(() => {
    if (!props.is_ollama_online) return false;

    const installed = props.models.map((m) => clean(m.name));
    return (
        installed.includes(clean(form.llm_model)) &&
        installed.includes(clean(form.vision_model)) &&
        installed.includes(clean(form.embedding_model))
    );
});

// Check if ANY folder is currently indexing
const isAnyFolderIndexing = computed(() => {
    return foldersList.value.some((f) => f.is_indexing);
});

// The master busy state
const isBusy = computed(
    () =>
        syncing.value ||
        isAnyFolderIndexing.value ||
        isRebuilding.value ||
        !isSetupComplete.value,
);

const toggleLock = () => {
    if (!isSettingsLocked.value) {
        isSettingsLocked.value = true;
    } else {
        if (
            confirm(
                "Advanced: Changing these settings can clear Arkhein's long-term memory. Continue?",
            )
        ) {
            isSettingsLocked.value = false;
        }
    }
};

const findBestMatch = (baseName: string) => {
    const installed = props.models.map(m => m.name);
    // Priority 1: Exact match
    if (installed.includes(baseName)) return baseName;
    // Priority 2: :latest variant
    if (installed.includes(`${baseName}:latest`)) return `${baseName}:latest`;
    // Priority 3: Base variant (if :latest was requested but only base exists)
    const base = baseName.replace(':latest', '');
    if (installed.includes(base)) return base;
    
    // Fallback to the target literal so the UI has a value
    return baseName;
};

const setComputeProfile = (profile: 'efficient' | 'elite') => {
    if (profile === 'efficient') {
        form.llm_model = findBestMatch('mistral:latest');
        form.vision_model = findBestMatch('qwen3-vl:latest');
        form.embedding_model = findBestMatch('nomic-embed-text:latest');
        form.embedding_dimensions = 768;
    } else {
        form.llm_model = findBestMatch('qwen3:8b:latest');
        form.vision_model = findBestMatch('qwen3-vl:latest');
        form.embedding_model = findBestMatch('qwen3-embedding:4b:latest');
        form.embedding_dimensions = 1056;
    }
    isSettingsLocked.value = false;
};

const rebuildIndex = async () => {
    if (isBusy.value) return;

    isRebuilding.value = true;
    reconcileProgress.value = 0;

    try {
        await axios.post('/settings/rebuild');
        startPolling();
    } catch (e) {
        console.error('Rebuild failed', e);
        isRebuilding.value = false;
    }
};

const pollStatus = async () => {
    try {
        const res = await axios.get('/settings');
        foldersList.value = res.data.folders;

        // Update Reconciliation State
        isRebuilding.value = res.data.reconcile.status === 'running';
        reconcileProgress.value = res.data.reconcile.progress;

        // If nothing is indexing anymore, clear the interval
        if (!isAnyFolderIndexing.value && !isRebuilding.value) {
            stopPolling();
        }
    } catch (e) {
        console.error('Polling failed', e);
        stopPolling();
    }
};

const startPolling = () => {
    if (pollInterval.value) return;
    pollInterval.value = setInterval(pollStatus, 3000);
};

const stopPolling = () => {
    if (pollInterval.value) {
        clearInterval(pollInterval.value);
        pollInterval.value = null;
    }
};

onMounted(() => {
    if (isAnyFolderIndexing.value || isRebuilding.value) {
        startPolling();
    }
});

onUnmounted(() => {
    stopPolling();
});

const form = useForm({
    llm_model: props.current.llm_model,
    vision_model: props.current.vision_model,
    vision_enabled: props.current.vision_enabled,
    embedding_model: props.current.embedding_model,
    embedding_dimensions: props.current.embedding_dimensions,
});

const isMemoryResetRequired = computed(() => {
    return (
        form.embedding_model !== props.current.embedding_model ||
        Number(form.embedding_dimensions) !==
            Number(props.current.embedding_dimensions)
    );
});

const submit = () => {
    form.post('/settings', {
        preserveScroll: true,
    });
};

const syncFolders = () => {
    if (isBusy.value) return;

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
        },
    });
};

const addFolder = () => {
    useForm({}).post('/settings/folders', {
        preserveScroll: true,
        onFinish: () => {
            axios
                .get('/settings')
                .then((res) => (foldersList.value = res.data.folders));
        },
    });
};

const removeFolder = (id: number) => {
    if (isBusy.value) return;

    useForm({}).delete(`/settings/folders/${id}`, {
        preserveScroll: true,
        onFinish: () => {
            foldersList.value = foldersList.value.filter((f) => f.id !== id);
        },
    });
};

const toggleVisual = (id: number) => {
    if (isBusy.value) return;

    const folder = foldersList.value.find((f) => f.id === id);
    if (!folder) return;

    const isAlreadyEnabled = folder.allow_visual_indexing;
    const message = isAlreadyEnabled
        ? 'Vision Intelligence is already active for this folder. Re-analyze all images? (High resource usage)'
        : 'RESOURCE KILLER: Enable Vision Intelligence for this folder? Arkhein will use VL models to describe every image. This is extremely compute-intensive, will drain battery, and may take a long time. Continue?';

    if (!confirm(message)) return;

    useForm({}).post(`/settings/folders/${id}/toggle-visual`, {
        preserveScroll: true,
        onSuccess: () => {
            if (!isAlreadyEnabled) {
                folder.allow_visual_indexing = true;
            }
            startPolling(); // Show indexing progress
        },
    });
};
</script>

<template>
    <Head title="Settings" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="mx-auto flex h-full w-full max-w-6xl flex-1 flex-col gap-8 overflow-y-auto p-8"
        >
            <div class="flex items-center gap-3">
                <div class="rounded-xl bg-primary/10 p-2 text-primary">
                    <SettingsIcon class="h-6 w-6" />
                </div>
                <div>
                    <h1 class="text-3xl font-bold tracking-tight">
                        System Settings
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Configure Arkhein's intelligence, memory, and
                        appearance.
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 items-start gap-8 lg:grid-cols-2">
                <!-- Left Column: Core Intelligence -->
                <div class="flex flex-col gap-8">
                    <Card>
                        <CardHeader>
                            <div class="flex items-center justify-between">
                                <CardTitle class="flex items-center gap-2">
                                    <Sparkles class="h-5 w-5 text-yellow-500" />
                                    AI Models
                                </CardTitle>
                                <div
                                    v-if="is_optimized"
                                    class="flex items-center gap-1.5 rounded-full border border-green-500/20 bg-green-500/10 px-2 py-1 text-[10px] font-black tracking-tighter text-green-600 uppercase"
                                >
                                    <Zap class="h-2.5 w-2.5 fill-current" />
                                    Optimized for Arkhein
                                </div>
                                <div
                                    v-else
                                    class="flex items-center gap-1.5 rounded-full border border-border bg-muted px-2 py-1 text-[10px] font-black tracking-tighter text-muted-foreground uppercase"
                                >
                                    Custom Config
                                </div>
                            </div>
                            <CardDescription>
                                Configure which local Ollama models Arkhein uses
                                for conversation and memory.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <!-- 0. Compute Profile Selection -->
                            <div
                                v-if="is_ollama_online"
                                class="mb-6 grid grid-cols-2 gap-3"
                            >
                                <button
                                    type="button"
                                    @click="setComputeProfile('efficient')"
                                    class="flex flex-col gap-2 rounded-2xl border p-4 text-left transition-all"
                                    :class="
                                        clean(form.llm_model) === 'mistral'
                                            ? 'border-primary bg-primary/5 shadow-sm'
                                            : 'border-border/50 bg-muted/30 opacity-60 hover:opacity-100'
                                    "
                                >
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <span
                                            class="text-[10px] font-black tracking-widest text-primary uppercase"
                                            >Efficient</span
                                        >
                                        <Zap
                                            v-if="
                                                clean(form.llm_model) ===
                                                'mistral'
                                            "
                                            class="h-3 w-3 fill-current text-primary"
                                        />
                                    </div>
                                    <span class="text-xs font-bold"
                                        >Mistral + Nomic</span
                                    >
                                    <span
                                        class="text-[9px] leading-tight text-muted-foreground"
                                        >Standard Mac (8GB-16GB RAM). Balanced
                                        speed and accuracy.</span
                                    >
                                </button>

                                <button
                                    type="button"
                                    @click="setComputeProfile('elite')"
                                    class="flex flex-col gap-2 rounded-2xl border p-4 text-left transition-all"
                                    :class="
                                        clean(form.llm_model) === 'qwen3:8b'
                                            ? 'border-indigo-500 bg-indigo-500/5 shadow-sm'
                                            : 'border-border/50 bg-muted/30 opacity-60 hover:opacity-100'
                                    "
                                >
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <span
                                            class="text-[10px] font-black tracking-widest text-indigo-500 uppercase"
                                            >Elite</span
                                        >
                                        <Sparkles
                                            v-if="
                                                clean(form.llm_model) ===
                                                'qwen3:8b'
                                            "
                                            class="h-3 w-3 fill-current text-indigo-500"
                                        />
                                    </div>
                                    <span class="text-xs font-bold"
                                        >Qwen3 Suite</span
                                    >
                                    <span
                                        class="text-[9px] leading-tight text-muted-foreground"
                                        >Pro/Max Specs (32GB+ RAM). Superior
                                        analytical reasoning.</span
                                    >
                                </button>
                            </div>

                            <!-- 1. Ollama Offline State -->
                            <div
                                v-if="!is_ollama_online"
                                class="flex animate-in flex-col items-center gap-6 rounded-[2rem] border border-red-500/20 bg-red-500/5 p-6 text-center duration-500 zoom-in-95 fade-in"
                            >
                                <div
                                    class="rounded-3xl bg-red-500/10 p-4 text-red-500 shadow-inner"
                                >
                                    <AlertTriangle class="h-8 w-8" />
                                </div>
                                <div class="space-y-2">
                                    <h3
                                        class="text-lg font-black tracking-tighter text-red-600 uppercase"
                                    >
                                        Inference Engine Offline
                                    </h3>
                                    <p
                                        class="max-w-sm text-xs leading-relaxed text-muted-foreground"
                                    >
                                        Arkhein cannot connect to **Ollama** on
                                        `localhost:11434`. This is required for
                                        all local intelligence and memory
                                        operations.
                                    </p>
                                </div>

                                <div class="grid w-full grid-cols-1 gap-3">
                                    <a
                                        href="https://ollama.com/download"
                                        target="_blank"
                                        class="w-full"
                                    >
                                        <Button
                                            variant="outline"
                                            class="h-11 w-full gap-2 rounded-2xl border-red-500/20 font-bold text-red-600 hover:bg-red-500/10"
                                        >
                                            <Database class="h-4 w-4" />
                                            Download Ollama for macOS
                                        </Button>
                                    </a>
                                    <Button
                                        @click="() => $inertia.reload()"
                                        class="h-11 w-full gap-2 rounded-2xl font-bold shadow-lg shadow-primary/20"
                                    >
                                        <RefreshCw class="h-4 w-4" />
                                        Retry Connection
                                    </Button>
                                </div>

                                <p
                                    class="text-[10px] font-black tracking-widest uppercase opacity-40"
                                >
                                    Sovereign Architecture • Zero-Cloud Policy
                                </p>
                            </div>

                            <!-- 2. Normal Onboarding / Configuration UI -->
                            <template v-else>
                                <!-- Incomplete Setup Warning -->
                                <div
                                    v-if="showOnboardingWarning"
                                    class="mb-6 flex animate-in flex-col items-center gap-6 rounded-[2.5rem] border border-amber-500/20 bg-amber-500/5 p-6 text-center duration-500 zoom-in-95 fade-in"
                                >
                                    <div
                                        class="rounded-3xl bg-amber-500/10 p-4 text-amber-600 shadow-inner"
                                    >
                                        <Lock class="h-8 w-8" />
                                    </div>
                                    <div class="space-y-2">
                                        <h3
                                            class="text-lg font-black tracking-tighter text-amber-600 uppercase"
                                        >
                                            Action Required: Memory Setup
                                        </h3>
                                        <p
                                            class="max-w-sm text-xs leading-relaxed text-muted-foreground"
                                        >
                                            Indexing and folder authorization
                                            are **disabled** until the
                                            recommended models are downloaded to
                                            your machine.
                                        </p>
                                    </div>

                                    <div
                                        class="w-full space-y-2 rounded-2xl border border-amber-500/20 bg-amber-500/10 p-4 text-left font-mono text-[9px] text-amber-700"
                                    >
                                        <div
                                            class="flex items-center justify-between"
                                        >
                                            <span
                                                >ollama pull
                                                mistral:latest</span
                                            >
                                            <ShieldCheck
                                                v-if="
                                                    isRecommendedEfficientInstalled
                                                "
                                                class="h-3 w-3"
                                            />
                                        </div>
                                        <div
                                            class="flex items-center justify-between"
                                        >
                                            <span
                                                >ollama pull
                                                qwen3-vl:latest</span
                                            >
                                            <ShieldCheck
                                                v-if="
                                                    isRecommendedVisionInstalled
                                                "
                                                class="h-3 w-3"
                                            />
                                        </div>
                                        <div
                                            class="flex items-center justify-between"
                                        >
                                            <span
                                                >ollama pull
                                                nomic-embed-text:latest</span
                                            >
                                            <ShieldCheck
                                                v-if="
                                                    isRecommendedEfficientEmbeddingInstalled
                                                "
                                                class="h-3 w-3"
                                            />
                                        </div>
                                    </div>

                                    <p
                                        class="animate-pulse text-[10px] font-black tracking-widest text-amber-600 uppercase opacity-60"
                                    >
                                        System Locked • Pull Models to Continue
                                    </p>
                                </div>

                                <!-- Compute Profile Guide -->
                                <div
                                    class="mb-6 flex flex-col gap-4 rounded-2xl border border-primary/10 bg-primary/5 p-4"
                                >
                                    <div
                                        class="flex items-center gap-2 text-primary"
                                    >
                                        <Database class="h-4 w-4" />
                                        <span
                                            class="text-xs font-bold tracking-wider uppercase"
                                            >Ollama Model Guide</span
                                        >
                                    </div>
                                    <div class="grid grid-cols-1 gap-6">
                                        <div class="space-y-2">
                                            <span
                                                class="text-[9px] font-black tracking-widest uppercase opacity-50"
                                                >Efficient (Standard)</span
                                            >
                                            <div
                                                class="space-y-1 rounded-xl border border-border/50 bg-background p-2.5 font-mono text-[9px]"
                                            >
                                                <div
                                                    class="flex justify-between"
                                                >
                                                    <span class="opacity-70"
                                                        >ollama pull
                                                        mistral:latest</span
                                                    >
                                                    <ShieldCheck
                                                        v-if="
                                                            isRecommendedEfficientInstalled
                                                        "
                                                        class="h-2.5 w-2.5 text-green-500"
                                                    />
                                                </div>
                                                <div
                                                    class="flex justify-between"
                                                >
                                                    <span class="opacity-70"
                                                        >ollama pull
                                                        qwen3-vl:latest</span
                                                    >
                                                    <ShieldCheck
                                                        v-if="
                                                            isRecommendedVisionInstalled
                                                        "
                                                        class="h-2.5 w-2.5 text-green-500"
                                                    />
                                                </div>
                                                <div
                                                    class="flex justify-between"
                                                >
                                                    <span class="opacity-70"
                                                        >ollama pull
                                                        nomic-embed-text:latest</span
                                                    >
                                                    <ShieldCheck
                                                        v-if="
                                                            isRecommendedEfficientEmbeddingInstalled
                                                        "
                                                        class="h-2.5 w-2.5 text-green-500"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                        <div class="space-y-2">
                                            <span
                                                class="text-[9px] font-black tracking-widest uppercase opacity-50"
                                                >Elite (High-Precision)</span
                                            >
                                            <div
                                                class="space-y-1 rounded-xl border border-border/50 bg-background p-2.5 font-mono text-[9px]"
                                            >
                                                <div
                                                    class="flex justify-between"
                                                >
                                                    <span class="opacity-70"
                                                        >ollama pull
                                                        qwen3:8b:latest</span
                                                    >
                                                    <ShieldCheck
                                                        v-if="
                                                            isRecommendedEliteInstalled
                                                        "
                                                        class="h-2.5 w-2.5 text-green-500"
                                                    />
                                                </div>
                                                <div
                                                    class="flex justify-between"
                                                >
                                                    <span class="opacity-70"
                                                        >ollama pull
                                                        qwen3-embedding:4b:latest</span
                                                    >
                                                    <ShieldCheck
                                                        v-if="
                                                            isRecommendedEliteEmbeddingInstalled
                                                        "
                                                        class="h-2.5 w-2.5 text-green-500"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <form
                                    @submit.prevent="submit"
                                    class="space-y-6"
                                >
                                    <div
                                        v-if="
                                            isMemoryResetRequired &&
                                            !isSettingsLocked
                                        "
                                        class="rounded-2xl border border-amber-500/20 bg-amber-500/5 p-4"
                                    >
                                        <div class="flex gap-3">
                                            <AlertTriangle
                                                class="h-5 w-5 shrink-0 text-amber-500"
                                            />
                                            <div class="space-y-1">
                                                <h3
                                                    class="text-xs font-bold text-amber-600 uppercase"
                                                >
                                                    Memory Reset Required
                                                </h3>
                                                <p
                                                    class="text-[11px] leading-relaxed text-muted-foreground"
                                                >
                                                    Changing these settings will
                                                    clear your current vector
                                                    index to prevent dimension
                                                    mismatch.
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div
                                        class="space-y-4"
                                        :class="{
                                            'pointer-events-none opacity-50 select-none':
                                                isSettingsLocked,
                                        }"
                                    >
                                        <div class="space-y-2">
                                            <Label for="llm_model"
                                                >Primary Assistant Model
                                                (LLM)</Label
                                            >
                                            <Select v-model="form.llm_model">
                                                <SelectTrigger
                                                    class="rounded-xl"
                                                >
                                                    <SelectValue
                                                        placeholder="Select a model"
                                                    />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem
                                                        v-for="model in models"
                                                        :key="model.name"
                                                        :value="model.name"
                                                    >
                                                        {{ model.name }}
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        <!-- Vision Capabilities -->
                                        <div class="pt-2">
                                            <div class="rounded-[2rem] border border-blue-500/20 bg-blue-500/5 p-5">
                                                <div class="flex items-center justify-between mb-4">
                                                    <div class="flex items-center gap-2 text-blue-600">
                                                        <ScanEye class="h-4 w-4" />
                                                        <span class="text-xs font-black tracking-widest uppercase">Vision Capabilities</span>
                                                    </div>
                                                    <Button 
                                                        type="button"
                                                        variant="ghost" 
                                                        size="sm"
                                                        class="h-8 rounded-xl px-3 gap-2"
                                                        :class="form.vision_enabled ? 'bg-blue-500 text-white hover:bg-blue-600' : 'bg-muted/50 text-muted-foreground'"
                                                        @click="form.vision_enabled = !form.vision_enabled"
                                                    >
                                                        <component :is="form.vision_enabled ? ScanEye : EyeOff" class="h-3.5 w-3.5" />
                                                        <span class="text-[10px] font-bold uppercase">{{ form.vision_enabled ? 'Active' : 'Disabled' }}</span>
                                                    </Button>
                                                </div>

                                                <div v-if="form.vision_enabled" class="space-y-4 animate-in fade-in slide-in-from-top-2 duration-300">
                                                    <div class="flex gap-3 p-3 rounded-2xl bg-amber-500/10 border border-amber-500/20">
                                                        <AlertTriangle class="h-4 w-4 shrink-0 text-amber-600 mt-0.5" />
                                                        <div class="space-y-1">
                                                            <span class="text-[10px] font-black text-amber-700 uppercase leading-none">Resource Killer Notice</span>
                                                            <p class="text-[10px] leading-relaxed text-amber-800/80">
                                                                Vision analysis (VL) is extremely compute-intensive. Enabling this will cause Arkhein to analyze every image in authorized silos, which may significantly drain battery and increase CPU/GPU load on your Mac.
                                                            </p>
                                                        </div>
                                                    </div>

                                                    <div class="space-y-2">
                                                        <Label for="vision_model" class="text-[11px] font-bold opacity-70">Vision Assistant Model (VL)</Label>
                                                        <Select v-model="form.vision_model">
                                                            <SelectTrigger class="rounded-xl h-9 text-xs">
                                                                <SelectValue placeholder="Select a vision model" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem v-for="model in models" :key="model.name" :value="model.name">
                                                                    {{ model.name }}
                                                                </SelectItem>
                                                            </SelectContent>
                                                        </Select>
                                                    </div>
                                                </div>
                                                <div v-else class="text-center py-2">
                                                    <p class="text-[10px] text-muted-foreground italic">Vision intelligence is globally deactivated.</p>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="space-y-2">
                                            <Label for="embedding_model"
                                                >Embedding Model</Label
                                            >
                                            <Select
                                                v-model="form.embedding_model"
                                            >
                                                <SelectTrigger
                                                    class="rounded-xl"
                                                >
                                                    <SelectValue
                                                        placeholder="Select a model"
                                                    />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem
                                                        v-for="model in models"
                                                        :key="model.name"
                                                        :value="model.name"
                                                    >
                                                        {{ model.name }}
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        <div class="space-y-2">
                                            <Label
                                                for="embedding_dimensions"
                                                class="flex items-center gap-2"
                                            >
                                                <Ruler class="h-4 w-4" />
                                                Embedding Dimensions
                                            </Label>
                                            <Input
                                                id="embedding_dimensions"
                                                type="number"
                                                v-model="
                                                    form.embedding_dimensions
                                                "
                                                class="rounded-xl"
                                            />
                                        </div>
                                    </div>

                                    <div
                                        class="flex items-center justify-between gap-4 pt-4"
                                    >
                                        <div class="flex gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                class="h-9 rounded-xl px-4"
                                                @click="toggleLock"
                                            >
                                                <component
                                                    :is="
                                                        isSettingsLocked
                                                            ? Lock
                                                            : Unlock
                                                    "
                                                    class="mr-2 h-3.5 w-3.5"
                                                />
                                                {{
                                                    isSettingsLocked
                                                        ? 'Unlock Settings'
                                                        : 'Lock for Safety'
                                                }}
                                            </Button>
                                        </div>

                                        <div
                                            v-if="!isSettingsLocked"
                                            class="flex items-center gap-3"
                                        >
                                            <div
                                                v-if="form.recentlySuccessful"
                                                class="animate-in text-[10px] font-bold text-green-600 uppercase fade-in"
                                            >
                                                Saved!
                                            </div>
                                            <Button
                                                type="submit"
                                                class="h-9 rounded-xl px-6"
                                                :disabled="form.processing"
                                            >
                                                <Save
                                                    class="mr-2 h-3.5 w-3.5"
                                                />
                                                Save Changes
                                            </Button>
                                        </div>
                                    </div>
                                </form>
                            </template>
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
                                Choose your preferred theme for the Arkhein
                                interface.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <AppearanceTabs />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle
                                class="flex items-center gap-2 text-green-600"
                            >
                                <ShieldCheck class="h-5 w-5" />
                                Permissions & Managed Folders
                            </CardTitle>
                            <CardDescription>
                                Arkhein only indexes files in folders you
                                explicitly authorize here.
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div
                                v-if="foldersList.length === 0"
                                class="rounded-[1.5rem] border-2 border-dashed bg-muted/20 py-8 text-center text-sm text-muted-foreground italic"
                            >
                                No folders authorized. Add one to begin indexing
                                your local archive.
                            </div>
                            <div v-else class="space-y-3">
                                <div
                                    v-for="folder in foldersList"
                                    :key="folder.id"
                                    class="flex flex-col gap-3 rounded-2xl border border-border/50 bg-muted/30 p-4"
                                >
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <div
                                            class="flex flex-col gap-0.5 overflow-hidden text-left"
                                        >
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <span
                                                    class="truncate text-sm font-semibold"
                                                    >{{ folder.name }}</span
                                                >
                                                <span
                                                    v-if="folder.is_indexing"
                                                    class="flex items-center gap-1 text-[9px] font-black text-primary uppercase"
                                                >
                                                    <RefreshCw
                                                        class="h-2 w-2 animate-spin"
                                                    />
                                                    Indexing...
                                                </span>
                                            </div>
                                            <span
                                                class="truncate text-[10px] text-muted-foreground"
                                                >{{ folder.path }}</span
                                            >
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="h-8 w-8 rounded-xl"
                                                :class="[
                                                    folder.allow_visual_indexing
                                                        ? 'bg-blue-500/10 text-blue-500'
                                                        : 'text-muted-foreground',
                                                    folder.is_indexing || !form.vision_enabled
                                                        ? 'cursor-not-allowed opacity-30'
                                                        : '',
                                                ]"
                                                :disabled="folder.is_indexing || !form.vision_enabled"
                                                @click="toggleVisual(folder.id)"
                                                :title="
                                                    !form.vision_enabled
                                                        ? 'Enable Vision in settings to use this feature'
                                                        : (folder.is_indexing
                                                            ? 'System busy: Finish indexing before modifying vision'
                                                            : 'Toggle Visual Intelligence')
                                                "
                                            >
                                                <component
                                                    :is="
                                                        folder.allow_visual_indexing
                                                            ? ScanEye
                                                            : EyeOff
                                                    "
                                                    class="h-4 w-4"
                                                />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="h-8 w-8 rounded-xl text-red-500 hover:bg-red-50 hover:text-red-700 dark:hover:bg-red-900/20"
                                                @click="removeFolder(folder.id)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col gap-3 pt-4">
                                <Button
                                    variant="outline"
                                    class="w-full rounded-xl"
                                    @click="addFolder"
                                >
                                    <FolderPlus class="mr-2 h-4 w-4" />
                                    Authorize New Folder
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle
                                class="flex items-center gap-2 text-blue-600"
                            >
                                <BrainCircuit class="h-5 w-5" />
                                Memory Architecture
                            </CardTitle>
                            <CardDescription>
                                Arkhein uses a dual-layer memory system for
                                sovereignty and speed.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-6">
                                <!-- Progress Bar for Reconciliation -->
                                <div
                                    v-if="isRebuilding"
                                    class="space-y-3 rounded-2xl border border-blue-500/20 bg-blue-500/5 p-4"
                                >
                                    <div
                                        class="flex items-center justify-between text-[10px] font-black tracking-widest text-blue-600 uppercase"
                                    >
                                        <span
                                            >System Reconciliation in
                                            Progress</span
                                        >
                                        <span>{{ reconcileProgress }}%</span>
                                    </div>
                                    <div
                                        class="h-2 w-full overflow-hidden rounded-full bg-blue-500/10"
                                    >
                                        <div
                                            class="h-full bg-blue-500 shadow-[0_0_10px_rgba(59,130,246,0.5)] transition-all duration-500 ease-out"
                                            :style="{
                                                width: reconcileProgress + '%',
                                            }"
                                        ></div>
                                    </div>
                                    <p
                                        class="text-[9px] text-muted-foreground italic"
                                    >
                                        Batched shadow rebuild: Preparing fresh
                                        high-speed binary indices without
                                        downtime.
                                    </p>
                                </div>

                                <!-- SQLite Layer (SSOT) -->
                                <div
                                    class="flex flex-col gap-3 rounded-2xl border border-border/50 bg-muted/40 p-4"
                                >
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <div class="flex items-center gap-2">
                                            <div
                                                class="rounded-lg bg-blue-500/10 p-1.5 text-blue-600 dark:text-blue-400"
                                            >
                                                <Database class="h-4 w-4" />
                                            </div>
                                            <span
                                                class="text-xs font-bold tracking-wider uppercase"
                                                >Primary SSOT</span
                                            >
                                        </div>
                                        <span
                                            class="rounded-full bg-blue-500/10 px-2 py-0.5 font-mono text-[10px] text-blue-600"
                                            >SQLite</span
                                        >
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <span
                                            class="text-[10px] leading-relaxed text-muted-foreground"
                                        >
                                            The Single Source of Truth where all
                                            embeddings, text, and metadata are
                                            persisted.
                                        </span>
                                        <span
                                            class="truncate font-mono text-[9px] text-muted-foreground opacity-60"
                                            >database: nativephp.sqlite</span
                                        >
                                    </div>
                                </div>

                                <!-- Vektor Layer (Accelerator) -->
                                <div
                                    class="flex flex-col gap-3 rounded-2xl border border-border/50 bg-muted/40 p-4"
                                >
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <div class="flex items-center gap-2">
                                            <div
                                                class="rounded-lg bg-orange-500/10 p-1.5 text-orange-600 dark:text-orange-400"
                                            >
                                                <RefreshCw class="h-4 w-4" />
                                            </div>
                                            <span
                                                class="text-xs font-bold tracking-wider uppercase"
                                                >Vektor Accelerator</span
                                            >
                                        </div>
                                        <span
                                            class="rounded-full bg-orange-500/10 px-2 py-0.5 font-mono text-[10px] text-orange-600"
                                            >Binary Index</span
                                        >
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <span
                                            class="text-[10px] leading-relaxed text-muted-foreground"
                                        >
                                            A high-speed binary index rebuilt
                                            automatically from the SSOT if
                                            corrupted or missing.
                                        </span>
                                        <span
                                            class="truncate font-mono text-[9px] text-muted-foreground opacity-60"
                                            >path:
                                            storage/app/vektor/vector.bin</span
                                        >
                                    </div>
                                </div>

                                <div class="pt-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        class="h-9 w-full rounded-xl border-dashed text-xs"
                                        @click="rebuildIndex"
                                        :disabled="isBusy"
                                    >
                                        <RefreshCw
                                            class="mr-2 h-3 w-3"
                                            :class="{
                                                'animate-spin': isRebuilding,
                                            }"
                                        />
                                        {{
                                            isRebuilding
                                                ? 'Reconciling...'
                                                : 'Force Index Reconciliation'
                                        }}
                                    </Button>
                                    <p
                                        class="mt-3 px-4 text-center text-[9px] text-muted-foreground italic"
                                    >
                                        Reconciles the binary accelerator with
                                        the SQLite Source of Truth.
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
