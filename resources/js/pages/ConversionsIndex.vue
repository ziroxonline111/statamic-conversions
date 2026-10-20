<script setup>
import { computed, ref } from 'vue';
import { Head } from '@statamic/cms/inertia';
import {
    Alert,
    Badge,
    Button,
    Card,
    Description,
    Header,
    Heading,
    Panel,
    PanelHeader,
    Subheading,
    Table,
    TableCell,
    TableColumn,
    TableColumns,
    TableRow,
    TableRows,
} from '@statamic/cms/ui';

const props = defineProps({
    platforms: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    events: { type: Array, default: () => [] },
    enabled: { type: Boolean, default: true },
    consentMode: { type: String, default: 'cookie' },
    formsTracked: { type: Number, default: 0 },
    canTest: Boolean,
    testUrl: String,
    settingsUrl: { type: String, default: null },
});

const testing = ref(null);
const testResults = ref({});
const filter = ref('all');

const http = () => Statamic.$app.config.globalProperties.$axios;

const anyConfigured = computed(() => props.platforms.some((p) => p.configured && p.switched_on));
const needsSetup = computed(() => !anyConfigured.value || props.formsTracked === 0);
const days = computed(() => Object.entries(props.stats.per_day || {}));
const maxDay = computed(() => Math.max(1, ...days.value.map(([, n]) => n)));
const values = computed(() => Object.entries(props.stats.value || {}));

const shown = computed(() => {
    if (filter.value === 'failed') return props.events.filter((e) => Object.values(e.results).some((r) => r.status === 'failed'));
    if (filter.value === 'ads') return props.events.filter((e) => e.from && /ads/i.test(e.from));
    return props.events;
});

function platformState(p) {
    if (!p.switched_on) return { text: __('Switched off'), color: 'default' };
    if (!p.configured) return { text: __('Not set up'), color: 'amber' };
    return { text: __('Sending'), color: 'green' };
}

function resultColor(status) {
    return { sent: 'green', failed: 'red', skipped: 'default' }[status] || 'default';
}

function platformLabel(key) {
    return props.platforms.find((p) => p.key === key)?.label || key;
}

function test(platform) {
    testing.value = platform;
    http()
        .post(props.testUrl, { platform })
        .then((r) => {
            testResults.value[platform] = { ok: true, message: r.data.message || __('Sent') };
            Statamic.$toast.success(__('Test event sent to :platform', { platform: platformLabel(platform) }));
        })
        .catch((e) => {
            const message = e.response?.data?.message || __('Something went wrong');
            testResults.value[platform] = { ok: false, message };
            Statamic.$toast.error(message);
        })
        .finally(() => (testing.value = null));
}
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="__('Conversions')" />

        <Header :title="__('Conversions')" icon="target-aim-bulleye">
            <Button v-if="settingsUrl" :href="settingsUrl" icon="cog" :text="__('Settings')" variant="ghost" />
        </Header>

        <Description
            :text="__('Leads, sign-ups and orders sent from your server to the ad platforms, so ad blockers and browser privacy settings do not hide them.')"
            class="-mt-4 mb-6"
        />

        <Alert v-if="!enabled" variant="warning" class="mb-6" :heading="__('Sending is switched off')">
            {{ __('KARELIA_CONVERSIONS_ENABLED is false, so nothing is sent.') }}
        </Alert>

        <Panel v-if="needsSetup" class="mb-6">
            <PanelHeader>
                <Heading :text="__('Get started')" />
            </PanelHeader>
            <Card>
                <ol class="list-decimal ps-5 space-y-2 text-sm">
                    <li>
                        {{ __('Put your access tokens in .env:') }}
                        <code class="text-xs">KARELIA_CONVERSIONS_META_ACCESS_TOKEN</code>,
                        <code class="text-xs">KARELIA_CONVERSIONS_GA4_API_SECRET</code>,
                        <code class="text-xs">KARELIA_CONVERSIONS_TIKTOK_ACCESS_TOKEN</code>.
                    </li>
                    <li>{{ __('Add the Pixel ID, Measurement ID or TikTok pixel code, and the forms that count, in the settings.') }}</li>
                    <li>
                        {{ __('Add') }} <code class="text-xs">&#123;&#123; conversions:script &#125;&#125;</code>
                        {{ __('to your layout, and call') }} <code class="text-xs">KareliaConversions.consent(true)</code>
                        {{ __('from your cookie banner when a visitor accepts marketing cookies.') }}
                    </li>
                    <li>{{ __('Send a test event below, then submit one of your forms.') }}</li>
                </ol>
            </Card>
        </Panel>

        <div class="grid gap-4 md:grid-cols-3 mb-6">
            <Card v-for="p in platforms" :key="p.key" class="flex flex-col gap-3">
                <div class="flex items-center justify-between gap-2">
                    <Heading :text="p.label" />
                    <Badge :text="platformState(p).text" :color="platformState(p).color" pill />
                </div>
                <Subheading v-if="p.missing.length && p.switched_on" class="text-xs">
                    {{ __('Missing:') }} {{ p.missing.join(', ') }}
                </Subheading>
                <div v-if="testResults[p.key]" class="text-xs" :class="testResults[p.key].ok ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'">
                    {{ testResults[p.key].message }}
                </div>
                <div class="mt-auto">
                    <Button
                        v-if="canTest"
                        size="sm"
                        :disabled="!p.configured || testing !== null"
                        :loading="testing === p.key"
                        :text="__('Send test event')"
                        @click="test(p.key)"
                    />
                </div>
            </Card>
        </div>

        <div class="grid gap-4 md:grid-cols-3 mb-6">
            <Card class="md:col-span-2">
                <div class="flex items-baseline justify-between mb-3">
                    <Heading :text="__('Last 30 days')" />
                    <span class="text-2xl font-semibold tabular-nums">{{ stats.total ?? 0 }}</span>
                </div>
                <div class="flex items-end gap-[3px] h-24" role="img" :aria-label="__('Conversions per day for the last 30 days')">
                    <div
                        v-for="[day, n] in days"
                        :key="day"
                        class="flex-1 rounded-t bg-blue-500/80 dark:bg-blue-400/80 min-h-[2px]"
                        :style="{ height: `${Math.max(2, (n / maxDay) * 100)}%` }"
                        :title="`${day}: ${n}`"
                    />
                </div>
                <div class="flex flex-wrap gap-x-6 gap-y-1 mt-3 text-sm text-gray-600 dark:text-gray-400">
                    <span v-for="[currency, total] in values" :key="currency">
                        {{ __('Value') }}: <strong class="tabular-nums">{{ total.toLocaleString() }} {{ currency }}</strong>
                    </span>
                    <span v-if="stats.failed">{{ __('Failed') }}: <strong class="text-red-700 dark:text-red-400">{{ stats.failed }}</strong></span>
                    <span v-if="consentMode === 'cookie'">{{ __('Not sent, no consent') }}: <strong>{{ stats.without_consent ?? 0 }}</strong></span>
                </div>
            </Card>
            <Card>
                <Heading :text="__('Where they came from')" class="mb-3" />
                <ul v-if="Object.keys(stats.per_source || {}).length" class="space-y-1.5 text-sm">
                    <li v-for="(n, source) in stats.per_source" :key="source" class="flex justify-between gap-3">
                        <span class="truncate">{{ source }}</span>
                        <span class="tabular-nums text-gray-600 dark:text-gray-400">{{ n }}</span>
                    </li>
                </ul>
                <p v-else class="text-sm text-gray-600 dark:text-gray-400">{{ __('Nothing yet.') }}</p>
                <Heading :text="__('Events')" class="mt-5 mb-3" />
                <ul v-if="Object.keys(stats.per_event || {}).length" class="space-y-1.5 text-sm">
                    <li v-for="(n, label) in stats.per_event" :key="label" class="flex justify-between gap-3">
                        <span>{{ label }}</span>
                        <span class="tabular-nums text-gray-600 dark:text-gray-400">{{ n }}</span>
                    </li>
                </ul>
            </Card>
        </div>

        <Panel>
            <PanelHeader class="flex items-center justify-between gap-3">
                <Heading :text="__('Recent conversions')" />
                <div class="flex gap-1" role="group" :aria-label="__('Filter')">
                    <Button size="sm" :variant="filter === 'all' ? 'primary' : 'ghost'" :text="__('All')" @click="filter = 'all'" />
                    <Button size="sm" :variant="filter === 'ads' ? 'primary' : 'ghost'" :text="__('From ads')" @click="filter = 'ads'" />
                    <Button size="sm" :variant="filter === 'failed' ? 'primary' : 'ghost'" :text="__('Failed')" @click="filter = 'failed'" />
                </div>
            </PanelHeader>
            <Card v-if="!shown.length" class="text-sm text-gray-600 dark:text-gray-400">
                {{ events.length ? __('Nothing matches this filter.') : __('No conversions yet. They show up here as soon as a tracked form is sent.') }}
            </Card>
            <Table v-else>
                <TableColumns>
                    <TableColumn>{{ __('When') }}</TableColumn>
                    <TableColumn>{{ __('Event') }}</TableColumn>
                    <TableColumn>{{ __('Source') }}</TableColumn>
                    <TableColumn>{{ __('Value') }}</TableColumn>
                    <TableColumn>{{ __('From') }}</TableColumn>
                    <TableColumn>{{ __('Platforms') }}</TableColumn>
                </TableColumns>
                <TableRows>
                    <TableRow v-for="e in shown" :key="e.id">
                        <TableCell><span :title="e.time">{{ e.time_human }}</span></TableCell>
                        <TableCell>{{ e.label }}</TableCell>
                        <TableCell class="max-w-[16rem] truncate">{{ e.source }}</TableCell>
                        <TableCell class="tabular-nums">{{ e.value || '–' }}</TableCell>
                        <TableCell>
                            <span>{{ e.from || __('Direct or unknown') }}</span>
                            <span v-if="e.campaign" class="block text-xs text-gray-500">{{ e.campaign }}</span>
                        </TableCell>
                        <TableCell>
                            <div class="flex flex-wrap gap-1">
                                <Badge v-if="!e.consent" :text="__('No consent')" color="default" size="sm" />
                                <Badge
                                    v-for="(r, key) in e.results"
                                    :key="key"
                                    :text="platformLabel(key)"
                                    :color="resultColor(r.status)"
                                    size="sm"
                                    :title="r.message || r.status"
                                />
                            </div>
                        </TableCell>
                    </TableRow>
                </TableRows>
            </Table>
        </Panel>
    </div>
</template>
