<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
    variant?: 'full' | 'icon';
};

const props = withDefaults(defineProps<Props>(), {
    variant: 'full',
});

const iconBlue = new URL('../../images/arkhein-logo-blue.svg', import.meta.url).href;
const iconWhite = new URL('../../images/arkhein-logo-white.svg', import.meta.url).href;
</script>

<template>
    <div :class="['flex items-center gap-2', props.className]" v-bind="$attrs">
        <!-- Square brand mark (matches existing nav style) -->
        <div
            class="flex aspect-square items-center justify-center rounded-md"
        >
            <img
                :src="iconBlue"
                alt="Arkhein"
                class="h-14 w-14 dark:hidden"
                draggable="false"
            />
            <img
                :src="iconWhite"
                alt="Arkhein"
                class="hidden h-14 w-14 dark:block"
                draggable="false"
            />
        </div>

        <!-- Name (HTML text), hidden when sidebar collapses to icon mode -->
        <span
            v-if="variant === 'full'"
            class="text-[20px] font-black tracking-[0.35em] text-sidebar-foreground group-data-[collapsible=icon]:hidden"
        >
            ARKHEIN
        </span>
    </div>
</template>
