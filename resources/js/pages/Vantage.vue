<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { Plus, LayoutDashboard } from 'lucide-vue-next';
import { ref, onMounted } from 'vue';
import Button from '@/components/ui/button/Button.vue';
import VantageCard from '@/components/VantageCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
    verticals: any[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Vantage Hub',
        href: '/vantage',
    },
];

const localVerticals = ref([...props.verticals]);
const managedFolders = ref<any[]>([]);

onMounted(async () => {
    // Fetch authorized folders for new cards
    const res = await axios.get('/settings'); 
    managedFolders.value = res.data.folders || [];
});

const addNewCard = () => {
    // Add a null vertical to trigger the selection state in VantageCard
    localVerticals.value.push(null);
};

const handleCreated = (newVertical: any, index: number) => {
    localVerticals.value[index] = newVertical;
};

const handleDeleted = (id: number) => {
    localVerticals.value = localVerticals.value.filter(v => v && v.id !== id);
};
</script>

<template>
    <Head title="Vantage Hub" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6 overflow-y-auto">
            
            <!-- Header Section -->
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-xl bg-primary/10 text-primary">
                        <LayoutDashboard class="h-6 w-6" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight">Vantage Hub</h1>
                        <p class="text-sm text-muted-foreground">Manage your specialized AI analysis verticals.</p>
                    </div>
                </div>
                <Button @click="addNewCard" class="gap-2 shadow-lg shadow-primary/20">
                    <Plus class="h-4 w-4" />
                    New Vantage Card
                </Button>
            </div>

            <!-- Verticals Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <VantageCard 
                    v-for="(vertical, index) in localVerticals" 
                    :key="vertical ? vertical.id : 'new-' + index"
                    :vertical="vertical"
                    :managed-folders="managedFolders"
                    @created="(v) => handleCreated(v, index)"
                    @deleted="handleDeleted"
                />

                <!-- Empty State if no verticals -->
                <div v-if="localVerticals.length === 0" class="col-span-full h-64 border-2 border-dashed rounded-3xl flex flex-col items-center justify-center text-center p-8 opacity-50">
                    <div class="p-4 rounded-full bg-muted mb-4">
                        <LayoutDashboard class="h-8 w-8" />
                    </div>
                    <h3 class="text-lg font-semibold">No active verticals</h3>
                    <p class="text-sm max-w-xs mt-1">Create a Vantage card to analyze specific folders using Arkhein's RAG engine.</p>
                    <Button variant="outline" class="mt-6" @click="addNewCard">Deploy First Vertical</Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
