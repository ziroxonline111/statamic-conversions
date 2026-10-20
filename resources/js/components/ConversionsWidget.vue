<script setup>
import { computed } from 'vue';
import { Widget } from '@statamic/cms/ui';
import { Link } from '@statamic/cms/inertia';

const props = defineProps({
    title: String,
    days: { type: Number, default: 30 },
    total: { type: Number, default: 0 },
    perDay: { type: Array, default: () => [] },
    perSource: { type: Object, default: () => ({}) },
    failed: { type: Number, default: 0 },
    url: String,
});

const max = computed(() => Math.max(1, ...props.perDay));
</script>

<template>
    <Widget :title="title" icon="target-aim-bulleye" :href="url">
        <div class="px-4 py-3">
            <div class="flex items-baseline justify-between">
                <span class="text-sm text-gray-600 dark:text-gray-400">{{ __('Last :days days', { days }) }}</span>
                <span class="text-2xl font-semibold tabular-nums">{{ total }}</span>
            </div>
            <div class="flex items-end gap-[2px] h-12 mt-2" aria-hidden="true">
                <div
                    v-for="(n, i) in perDay"
                    :key="i"
                    class="flex-1 rounded-t bg-blue-500/80 dark:bg-blue-400/80 min-h-[2px]"
                    :style="{ height: `${Math.max(4, (n / max) * 100)}%` }"
                />
            </div>
            <ul v-if="Object.keys(perSource).length" class="mt-3 space-y-1 text-sm">
                <li v-for="(n, source) in perSource" :key="source" class="flex justify-between gap-3">
                    <span class="truncate">{{ source }}</span>
                    <span class="tabular-nums text-gray-600 dark:text-gray-400">{{ n }}</span>
                </li>
            </ul>
            <p v-else class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('No conversions yet.') }}</p>
            <p v-if="failed" class="mt-2 text-sm text-red-700 dark:text-red-400">
                {{ __(':count failed to send', { count: failed }) }}
            </p>
        </div>
        <template #footer>
            <Link :href="url" class="text-sm text-blue-600 dark:text-blue-400 hover:underline px-4 py-2 block">
                {{ __('Open Conversions') }}
            </Link>
        </template>
    </Widget>
</template>
