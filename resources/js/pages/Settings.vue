<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/button/Button.vue';
import Input from '@/components/ui/input/Input.vue';
import Label from '@/components/ui/label/Label.vue';
import Select from '@/components/ui/select/Select.vue';
import SelectContent from '@/components/ui/select/SelectContent.vue';
import SelectItem from '@/components/ui/select/SelectItem.vue';
import SelectTrigger from '@/components/ui/select/SelectTrigger.vue';
import SelectValue from '@/components/ui/select/SelectValue.vue';
import Card from '@/components/ui/card/Card.vue';
import CardContent from '@/components/ui/card/CardContent.vue';
import CardDescription from '@/components/ui/card/CardDescription.vue';
import CardHeader from '@/components/ui/card/CardHeader.vue';
import CardTitle from '@/components/ui/card/CardTitle.vue';
import { Settings as SettingsIcon, Save, Sparkles, BrainCircuit, Ruler, AlertTriangle, FolderPlus, Trash2, ShieldCheck, RefreshCw } from 'lucide-vue-next';
import { settings } from '@/routes';

const props = defineProps<{
    models: any[];
    folders: any[];
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
const foldersList = ref([...props.folders]);
const pollInterval = ref<any>(null);

// Check if ANY folder is currently indexing
const isAnyFolderIndexing = computed(() => {
    return foldersList.value.some(f => f.is_indexing);
});

// The master busy state
const isBusy = computed(() => syncing.value || isAnyFolderIndexing.value);

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
    if (isBusy.value) return;
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
        <div class="flex flex-col gap-6 p-6 max-w-2xl mx-auto">
            <div class="flex items-center gap-2 px-2">
                <SettingsIcon class="h-6 w-6 text-primary" />
                <h1 class="text-xl font-semibold tracking-tight">System Settings</h1>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Sparkles class="h-5 w-5 text-yellow-500" />
                        AI Models
                    </CardTitle>
                    <CardDescription>
                        Configure which local Ollama models Arkhein uses for conversation and memory.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="submit" class="space-y-6">
                        
                        <div v-if="isMemoryResetRequired" class="rounded-md bg-amber-50 p-4 border border-amber-200 dark:bg-amber-900/10 dark:border-amber-900/50">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <AlertTriangle class="h-5 w-5 text-amber-400" aria-hidden="true" />
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-amber-800 dark:text-amber-400">Memory Reset Required</h3>
                                    <div class="mt-2 text-sm text-amber-700 dark:text-amber-500">
                                        <p>Changing the embedding model or dimensions will clear Arkhein's current long-term memory to maintain database integrity.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label for="llm_model">Primary Assistant Model (LLM)</Label>
                            <Select v-model="form.llm_model">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a model" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="model in models" :key="model.name" :value="model.name">
                                        {{ model.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p class="text-[10px] text-muted-foreground">The model that generates chat responses.</p>
                        </div>

                        <div class="space-y-2">
                            <Label for="embedding_model">Embedding Model</Label>
                            <Select v-model="form.embedding_model">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a model" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="model in models" :key="model.name" :value="model.name">
                                        {{ model.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p class="text-[10px] text-muted-foreground">The model used to process long-term memory.</p>
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
                                min="32"
                                max="4096"
                            />
                            <p class="text-[10px] text-muted-foreground">
                                Default is 768 (nomic-embed-text). Qwen3 might require custom values.
                            </p>
                        </div>

                        <div class="pt-4 flex items-center justify-between">
                            <div v-if="form.recentlySuccessful" class="text-sm text-green-600 font-medium animate-in fade-in">
                                Settings saved!
                            </div>
                            <div v-else></div>
                            
                            <Button type="submit" :disabled="form.processing">
                                <Save class="mr-2 h-4 w-4" />
                                Save Changes
                            </Button>
                        </div>
                    </form>
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
                    <div v-if="foldersList.length === 0" class="text-sm text-muted-foreground italic py-4 text-center border-2 border-dashed rounded-lg">
                        No folders authorized. Add one to begin indexing your local archive.
                    </div>
                    <div v-else class="space-y-3">
                        <div v-for="folder in foldersList" :key="folder.id" class="flex items-center justify-between p-3 rounded-md bg-muted/50 border">
                            <div class="flex flex-col gap-0.5 overflow-hidden">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium truncate">{{ folder.name }}</span>
                                    <span v-if="folder.is_indexing" class="flex items-center gap-1 text-[9px] text-primary animate-pulse font-bold uppercase">
                                        <RefreshCw class="h-2 w-2 animate-spin" />
                                        Indexing
                                    </span>
                                </div>
                                <span class="text-[10px] text-muted-foreground truncate">{{ folder.path }}</span>
                            </div>
                            <Button variant="ghost" size="icon" class="text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-900/20" @click="removeFolder(folder.id)" :disabled="isBusy">
                                <Trash2 class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>

                    <div class="pt-2 flex flex-col gap-3">
                        <div class="flex gap-2">
                            <Button variant="outline" class="flex-1" @click="addFolder" :disabled="isBusy">
                                <FolderPlus class="mr-2 h-4 w-4" />
                                Authorize Folder
                            </Button>
                            <Button 
                                variant="secondary" 
                                class="flex-1"
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
                                {{ isBusy ? 'Syncing...' : 'Sync Archive' }}
                            </Button>
                        </div>
                        <p v-if="recentlySynced" class="text-[10px] text-green-600 font-medium text-center animate-in fade-in slide-in-from-top-1">
                            Background indexing dispatched. You will receive a notification when finished.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-blue-600">
                        <BrainCircuit class="h-5 w-5" />
                        Memory Storage
                    </CardTitle>
                    <CardDescription>
                        Status of your local vector database.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="text-sm space-y-2">
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">Storage Engine:</span>
                            <span class="font-mono font-medium">Vektor (Pure PHP)</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">Location:</span>
                            <span class="font-mono text-[10px]">storage/app/vektor/</span>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
