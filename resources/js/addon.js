import ConversionsIndex from './pages/ConversionsIndex.vue';
import ConversionsWidget from './components/ConversionsWidget.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('statamic-conversions::ConversionsIndex', ConversionsIndex);
    Statamic.$components.register('karelia-conversions-widget', ConversionsWidget);
});
