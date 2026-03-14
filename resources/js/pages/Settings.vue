<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Settings as SettingsIcon, Save, Sparkles, BrainCircuit, Ruler, AlertTriangle, FolderPlus, Trash2, ShieldCheck } from 'lucide-vue-next';
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

const addFolder = () => {
    useForm({}).post('/settings/folders', {
        preserveScroll: true,
    });
};

const removeFolder = (id: number) => {
    useForm({}).delete(`/settings/folders/${id}`, {
        preserveScroll: true,
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
                    <div v-if="folders.length === 0" class="text-sm text-muted-foreground italic py-4 text-center border-2 border-dashed rounded-lg">
                        No folders authorized. Add one to begin indexing your local archive.
                    </div>
                    <div v-else class="space-y-3">
                        <div v-for="folder in folders" :key="folder.id" class="flex items-center justify-between p-3 rounded-md bg-muted/50 border">
                            <div class="flex flex-col gap-0.5 overflow-hidden">
                                <span class="text-sm font-medium truncate">{{ folder.name }}</span>
                                <span class="text-[10px] text-muted-foreground truncate">{{ folder.path }}</span>
                            </div>
                            <Button variant="ghost" size="icon" class="text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-900/20" @click="removeFolder(folder.id)">
                                <Trash2 class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>

                    <div class="pt-2">
                        <Button variant="outline" class="w-full" @click="addFolder">
                            <FolderPlus class="mr-2 h-4 w-4" />
                            Authorize Folder
                        </Button>
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
