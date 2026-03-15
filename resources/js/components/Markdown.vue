<script setup lang="ts">
import { computed } from 'vue';
import MarkdownIt from 'markdown-it';

const props = defineProps<{
    content: string;
}>();

const md = new MarkdownIt({
    html: false, // Security first
    linkify: true,
    typographer: true,
});

const renderedContent = computed(() => {
    return md.render(props.content || '');
});
</script>

<template>
    <div class="markdown-body" v-html="renderedContent"></div>
</template>

<style>
@reference "../../css/app.css";

.markdown-body {
    @apply text-xs leading-relaxed;
}

.markdown-body p {
    @apply mb-2 last:mb-0;
}

.markdown-body strong {
    @apply font-bold text-foreground;
}

.markdown-body ul, .markdown-body ol {
    @apply mb-2 ml-4 list-outside;
}

.markdown-body ul {
    @apply list-disc;
}

.markdown-body ol {
    @apply list-decimal;
}

.markdown-body li {
    @apply mb-1;
}

.markdown-body code {
    @apply px-1.5 py-0.5 rounded bg-muted font-mono text-[10px] border border-border/50;
}

.markdown-body pre {
    @apply p-3 my-2 rounded-lg bg-muted/80 overflow-x-auto border border-border/50;
}

.markdown-body pre code {
    @apply p-0 bg-transparent border-none block text-[10px];
}

.markdown-body h1, .markdown-body h2, .markdown-body h3 {
    @apply font-bold mt-4 mb-2 first:mt-0;
}

.markdown-body h1 { @apply text-base; }
.markdown-body h2 { @apply text-sm; }
.markdown-body h3 { @apply text-xs; }

.markdown-body a {
    @apply text-primary hover:underline;
}

.markdown-body blockquote {
    @apply border-l-2 border-primary/30 pl-3 italic my-2 text-muted-foreground;
}
</style>
