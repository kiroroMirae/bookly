<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    calendarFeedUrl: {
        type: String,
        required: true,
    },
});

const copied = ref(false);
let copiedTimeout = null;

const copyFeedUrl = async () => {
    await navigator.clipboard.writeText(props.calendarFeedUrl);
    copied.value = true;
    clearTimeout(copiedTimeout);
    copiedTimeout = setTimeout(() => (copied.value = false), 2000);
};

const form = useForm({});

const regenerate = () => {
    if (
        !window.confirm(
            'Regenerate your calendar feed URL? Calendars subscribed to the current URL will stop syncing until you re-subscribe them.',
        )
    ) {
        return;
    }

    form.post(route('calendar-feed.regenerate'), { preserveScroll: true });
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">Calendar Feed</h2>

            <p class="mt-1 text-sm text-gray-600">
                Subscribe to this private URL from Google Calendar, Apple
                Calendar, or Outlook to keep your external calendar in sync
                with your Bookly bookings. Anyone with this URL can see your
                bookings, so treat it like a password.
            </p>
        </header>

        <div class="mt-6 space-y-6">
            <div class="flex items-center gap-2">
                <TextInput
                    :model-value="calendarFeedUrl"
                    type="text"
                    class="block w-full text-sm"
                    readonly
                    @focus="$event.target.select()"
                />

                <PrimaryButton type="button" @click="copyFeedUrl">
                    Copy
                </PrimaryButton>
            </div>

            <div class="flex items-center gap-4">
                <SecondaryButton
                    type="button"
                    :disabled="form.processing"
                    @click="regenerate"
                >
                    Regenerate URL
                </SecondaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="copied" class="text-sm text-gray-600">Copied.</p>
                    <p
                        v-else-if="form.recentlySuccessful"
                        class="text-sm text-gray-600"
                    >
                        New URL generated.
                    </p>
                </Transition>
            </div>
        </div>
    </section>
</template>
