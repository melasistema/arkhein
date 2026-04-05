<script setup lang="ts">
import { ShieldCheck, RefreshCw, ScanEye, EyeOff, Trash2, FolderPlus } from 'lucide-vue-next';
import Button from '@/components/ui/button/Button.vue';
import Card from '@/components/ui/card/Card.vue';
import CardHeader from '@/components/ui/card/CardHeader.vue';
import CardTitle from '@/components/ui/card/CardTitle.vue';
import CardDescription from '@/components/ui/card/CardDescription.vue';
import CardContent from '@/components/ui/card/CardContent.vue';

defineProps<{
    foldersList: any[];
    visionEnabled: boolean;
    isBusy: boolean;
}>();

const emit = defineEmits(['addFolder', 'removeFolder', 'toggleVisual']);
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="flex items-center gap-2 text-green-600">
                <ShieldCheck class="h-5 w-5" />
                Permissions & Managed Folders
            </CardTitle>
            <CardDescription>
                Arkhein only indexes files in folders you explicitly authorize here.
            </CardDescription>
        </CardHeader>
        <CardContent class="space-y-4">
            <div
                v-if="foldersList.length === 0"
                class="rounded-[1.5rem] border-2 border-dashed bg-muted/20 py-8 text-center text-sm text-muted-foreground italic"
            >
                No folders authorized. Add one to begin indexing your local archive.
            </div>
            <div v-else class="space-y-3">
                <div
                    v-for="folder in foldersList"
                    :key="folder.id"
                    class="flex flex-col gap-3 rounded-2xl border border-border/50 bg-muted/30 p-4"
                >
                    <div class="flex items-center justify-between">
                        <div class="flex flex-col gap-0.5 overflow-hidden text-left">
                            <div class="flex items-center gap-2">
                                <span class="truncate text-sm font-semibold">{{ folder.name }}</span>
                                <span
                                    v-if="folder.is_indexing"
                                    class="flex items-center gap-1 text-[9px] font-black text-primary uppercase"
                                >
                                    <RefreshCw class="h-2 w-2 animate-spin" />
                                    Indexing...
                                </span>
                            </div>
                            <span class="truncate text-[10px] text-muted-foreground">{{ folder.path }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="h-8 w-8 rounded-xl"
                                :class="[
                                    folder.allow_visual_indexing ? 'bg-blue-500/10 text-blue-500' : 'text-muted-foreground',
                                    folder.is_indexing || !visionEnabled ? 'cursor-not-allowed opacity-30' : '',
                                ]"
                                :disabled="folder.is_indexing || !visionEnabled || isBusy"
                                @click="emit('toggleVisual', folder.id)"
                                :title="!visionEnabled ? 'Enable Vision in settings to use this feature' : (folder.is_indexing ? 'System busy: Finish indexing before modifying vision' : 'Toggle Visual Intelligence')"
                            >
                                <component
                                    :is="folder.allow_visual_indexing ? ScanEye : EyeOff"
                                    class="h-4 w-4"
                                />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="h-8 w-8 rounded-xl text-red-500 hover:bg-red-50 hover:text-red-700 dark:hover:bg-red-900/20"
                                :disabled="isBusy"
                                @click="emit('removeFolder', folder.id)"
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
                    :disabled="isBusy"
                    @click="emit('addFolder')"
                >
                    <FolderPlus class="mr-2 h-4 w-4" />
                    Authorize New Folder
                </Button>
            </div>
        </CardContent>
    </Card>
</template>