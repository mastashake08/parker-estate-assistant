<script setup lang="ts">
import { ChevronRight, Wrench } from '@lucide/vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import type { ToolUse } from '@/types';

defineProps<{
    tool: ToolUse;
}>();
</script>

<template>
    <Collapsible class="text-xs text-muted-foreground">
        <CollapsibleTrigger
            class="group flex items-center gap-1 hover:text-foreground"
        >
            <ChevronRight
                class="h-3 w-3 transition-transform group-data-[state=open]:rotate-90"
            />
            <Wrench class="h-3 w-3" />
            <span :class="{ 'text-destructive': tool.failed }">
                {{ tool.failed ? 'Tried' : 'Used' }}
                <span class="font-mono">{{ tool.name }}</span>
            </span>
        </CollapsibleTrigger>
        <CollapsibleContent class="mt-1 space-y-1 pl-4">
            <pre
                class="overflow-x-auto rounded bg-background p-2 font-mono whitespace-pre-wrap"
                >{{ JSON.stringify(tool.arguments, null, 2) }}</pre>
            <pre
                class="max-h-64 overflow-y-auto rounded bg-background p-2 font-mono whitespace-pre-wrap"
                >{{ tool.result }}</pre>
        </CollapsibleContent>
    </Collapsible>
</template>
