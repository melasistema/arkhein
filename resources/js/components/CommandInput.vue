<script setup lang="ts">
import axios from 'axios';
import { 
    Folder, FileText, Terminal, HelpCircle, ChevronRight,
    PlusCircle, Move, LayoutGrid, Trash2, RefreshCw
} from 'lucide-vue-next';
import { ref, computed, watch, nextTick } from 'vue';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    modelValue: string;
    placeholder?: string;
    disabled?: boolean;
}>();

const emit = defineEmits(['update:modelValue', 'submit', 'command']);

/**
 * State Management
 */
const inputRef = ref<any>(null);
const localValue = ref(props.modelValue);
const showPopup = ref(false);
const triggerChar = ref<'@' | '/' | null>(null);
const triggerPos = ref(-1);
const searchPrefix = ref('');
const selectedIndex = ref(0);

// Data stores
const suggestions = ref<any[]>([]);
const commands = [
    { type: 'command', name: 'help', description: 'Show all magic commands', icon: HelpCircle },
    { type: 'command', name: 'create', description: 'Create file with recent context', icon: PlusCircle },
    { type: 'command', name: 'move', description: 'Move a file to a folder', icon: Move },
    { type: 'command', name: 'organize', description: 'Group files by extension', icon: LayoutGrid },
    { type: 'command', name: 'delete', description: 'Remove a file from folder', icon: Trash2 },
    { type: 'command', name: 'sync', description: 'Re-index current folder', icon: RefreshCw },
];

/**
 * Sync logic
 */
watch(() => props.modelValue, (v) => {
    if (v !== localValue.value) {
localValue.value = v;
}
});

watch(localValue, (v) => {
    emit('update:modelValue', v);
});

/**
 * Lifecycle & Data Fetching
 */
const loadSuggestions = async () => {
    if (suggestions.value.length > 0) {
return;
}

    try {
        const response = await axios.get('/chat/suggestions');
        suggestions.value = response.data.items;
    } catch (e) {
        console.error("Arkhein: Failed to load context suggestions.");
    }
};

/**
 * Intelligent Filtering Logic
 */
const filteredItems = computed(() => {
    const query = searchPrefix.value.toLowerCase();
    
    if (triggerChar.value === '/') {
        return commands.filter(c => c.name.toLowerCase().includes(query));
    }
    
    if (triggerChar.value === '@') {
        return suggestions.value
            .filter(s => s.name.toLowerCase().includes(query))
            .slice(0, 10);
    }
    
    return [];
});

/**
 * Logic detection
 */
const handleValueUpdate = (value: string) => {
    const el = inputRef.value?.$el?.querySelector('input') || inputRef.value;

    if (!el) {
return;
}

    const pos = el.selectionStart || 0;
    const charBefore = value[pos - 1];

    // Detect Triggers
    if (charBefore === '@') {
        triggerChar.value = '@';
        triggerPos.value = pos;
        searchPrefix.value = '';
        showPopup.value = true;
        loadSuggestions();
    } else if (pos === 1 && value[0] === '/') {
        triggerChar.value = '/';
        triggerPos.value = pos;
        searchPrefix.value = '';
        showPopup.value = true;
    } 
    
    // Update search prefix if we are inside a trigger
    if (showPopup.value) {
        if (pos < triggerPos.value || (value[pos - 1] === ' ' && triggerChar.value === '/')) {
            closePopup();
        } else {
            searchPrefix.value = value.slice(triggerPos.value, pos);
        }
    }
};

// Use a watcher for the trigger logic to separate from the rapid typing flow
watch(localValue, (newVal) => {
    handleValueUpdate(newVal);
});

const closePopup = () => {
    showPopup.value = false;
    triggerChar.value = null;
    triggerPos.value = -1;
    searchPrefix.value = '';
    selectedIndex.value = 0;
};

const selectItem = (item: any) => {
    const el = inputRef.value?.$el?.querySelector('input') || inputRef.value;
    const pos = el?.selectionStart || 0;
    
    const before = localValue.value.slice(0, triggerPos.value);
    const after = localValue.value.slice(pos);
    
    const replacement = `${item.name} `;
    const newValue = before + replacement + after;

    localValue.value = newValue;
    
    if (triggerChar.value === '/') {
        emit('command', item.name);
    }

    closePopup();
    nextTick(() => el?.focus());
};

/**
 * Navigation logic
 */
const handleKeydown = (e: KeyboardEvent) => {
    if (showPopup.value && filteredItems.value.length > 0) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedIndex.value = (selectedIndex.value + 1) % filteredItems.value.length;

            return;
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedIndex.value = (selectedIndex.value - 1 + filteredItems.value.length) % filteredItems.value.length;

            return;
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            e.preventDefault();
            selectItem(filteredItems.value[selectedIndex.value]);

            return;
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closePopup();

            return;
        }
    }

    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        emit('submit');
    }
};
</script>

<template>
    <div class="relative w-full group">
        <!-- Intelligent Suggestion Layer -->
        <div v-if="showPopup && filteredItems.length > 0" 
             class="absolute bottom-full left-0 mb-3 w-full max-w-[400px] bg-background/95 backdrop-blur-sm border rounded-xl shadow-2xl overflow-hidden z-50 animate-in fade-in zoom-in-95 duration-100">
            
            <div class="flex items-center gap-2 px-3 py-2 bg-muted/50 border-b">
                <Terminal v-if="triggerChar === '/'" class="h-3 w-3 opacity-50" />
                <Folder v-else class="h-3 w-3 opacity-50" />
                <span class="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">
                    {{ triggerChar === '/' ? 'System Commands' : 'Context Mentions' }}
                </span>
            </div>

            <div class="max-h-[280px] overflow-y-auto p-1.5">
                <button
                    v-for="(item, index) in filteredItems"
                    :key="index"
                    type="button"
                    @click="selectItem(item)"
                    class="w-full flex items-center gap-3 px-3 py-2.5 text-sm rounded-lg text-left transition-all duration-75 outline-none"
                    :class="index === selectedIndex ? 'bg-primary text-primary-foreground shadow-md' : 'hover:bg-muted'"
                >
                    <div class="shrink-0">
                        <component :is="item.type === 'folder' ? Folder : (item.type === 'file' ? FileText : item.icon)" 
                                   class="h-4 w-4" :class="index === selectedIndex ? 'opacity-100' : 'opacity-50'" />
                    </div>
                    
                    <div class="flex flex-col flex-1 overflow-hidden">
                        <div class="flex items-center justify-between">
                            <span class="font-medium truncate">{{ item.name }}</span>
                            <ChevronRight v-if="index === selectedIndex" class="h-3 w-3 opacity-50" />
                        </div>
                        <span class="text-[10px] truncate opacity-70" :class="index === selectedIndex ? 'text-primary-foreground/80' : 'text-muted-foreground'">
                            {{ item.path || item.description }}
                        </span>
                    </div>
                </button>
            </div>
        </div>

        <Input
            ref="inputRef"
            v-model="localValue"
            @keydown="handleKeydown"
            :placeholder="placeholder"
            :disabled="disabled"
            class="h-12 bg-background border-muted-foreground/20 focus:border-primary/50 transition-colors px-4 rounded-xl shadow-sm"
            autocomplete="off"
            autocorrect="off"
            autocapitalize="off"
            spellcheck="false"
        />
    </div>
</template>

<style scoped>
::-webkit-scrollbar {
    width: 4px;
}
::-webkit-scrollbar-thumb {
    background: rgba(0,0,0,0.1);
    border-radius: 10px;
}
</style>
