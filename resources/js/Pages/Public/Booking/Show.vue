<script setup>
import { ref, onMounted } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';

const props = defineProps({
    eventType: { type: Object, required: true },
    host: { type: Object, required: true },
    slots: { type: Array, required: true },
    selectedDate: { type: String, required: true },
    guestTimezone: { type: String, required: true },
});

const selectedSlot = ref(null);

const form = useForm({
    guest_name: '',
    guest_email: '',
    guest_timezone: props.guestTimezone,
    starts_at: '',
});

const selectSlot = (slot) => {
    selectedSlot.value = slot;
    form.starts_at = slot.starts_at;
};

const timezones =
    typeof Intl.supportedValuesOf === 'function'
        ? Intl.supportedValuesOf('timeZone')
        : [props.guestTimezone];

const reload = (params) => {
    router.get(
        route('booking.show', { username: props.host.username, slug: props.eventType.slug }),
        { date: props.selectedDate, tz: props.guestTimezone, ...params },
        { preserveState: false }
    );
};

const changeDate = (date) => reload({ date });

const changeTimezone = (tz) => reload({ tz });

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    if (!params.has('tz')) {
        const detected = Intl.DateTimeFormat().resolvedOptions().timeZone;
        if (detected && detected !== props.guestTimezone) {
            reload({ tz: detected });
        }
    }
});

const submit = () => {
    form.post(route('booking.store', { username: props.host.username, slug: props.eventType.slug }));
};

const today = new Date().toISOString().split('T')[0];
</script>

<template>
    <Head :title="`Book ${eventType.name}`" />

    <div class="min-h-screen bg-gray-50">
        <div class="mx-auto max-w-2xl px-4 py-12 sm:px-6 lg:px-8">
            <div class="mb-8 rounded-lg bg-white p-6 shadow-sm">
                <div class="flex items-center gap-3">
                    <div
                        class="h-10 w-10 rounded-full"
                        :style="{ backgroundColor: eventType.color ?? '#6366f1' }"
                    />
                    <div>
                        <h1 class="text-xl font-semibold text-gray-900">{{ eventType.name }}</h1>
                        <p class="text-sm text-gray-500">with {{ host.name }} · {{ eventType.duration_minutes }} min</p>
                    </div>
                </div>
                <p v-if="eventType.description" class="mt-3 text-sm text-gray-600">{{ eventType.description }}</p>
            </div>

            <div class="mb-6 rounded-lg bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Select a date</label>
                        <input
                            type="date"
                            :value="selectedDate"
                            :min="today"
                            @change="changeDate($event.target.value)"
                            class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                    </div>
                    <div>
                        <label for="timezone" class="mb-2 block text-sm font-medium text-gray-700">Timezone</label>
                        <select
                            id="timezone"
                            :value="guestTimezone"
                            @change="changeTimezone($event.target.value)"
                            class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:w-64"
                        >
                            <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="mb-6 rounded-lg bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-sm font-medium text-gray-700">Available times</h2>

                <p v-if="slots.length === 0" class="text-sm text-gray-500">
                    No available times on this date.
                </p>

                <div v-else class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                    <button
                        v-for="slot in slots"
                        :key="slot.starts_at"
                        type="button"
                        @click="selectSlot(slot)"
                        :class="[
                            'rounded-md border px-3 py-2 text-sm transition',
                            selectedSlot?.starts_at === slot.starts_at
                                ? 'border-indigo-600 bg-indigo-600 text-white'
                                : 'border-gray-200 bg-white text-gray-700 hover:border-indigo-400',
                        ]"
                    >
                        {{ slot.display }}
                    </button>
                </div>
            </div>

            <div v-if="selectedSlot" class="rounded-lg bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-sm font-medium text-gray-700">Your details</h2>

                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label for="guest_name" class="block text-sm text-gray-600">Name</label>
                        <input
                            id="guest_name"
                            v-model="form.guest_name"
                            type="text"
                            required
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <p v-if="form.errors.guest_name" class="mt-1 text-xs text-red-600">{{ form.errors.guest_name }}</p>
                    </div>

                    <div>
                        <label for="guest_email" class="block text-sm text-gray-600">Email</label>
                        <input
                            id="guest_email"
                            v-model="form.guest_email"
                            type="email"
                            required
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <p v-if="form.errors.guest_email" class="mt-1 text-xs text-red-600">{{ form.errors.guest_email }}</p>
                    </div>

                    <p v-if="form.errors.starts_at" class="text-xs text-red-600">{{ form.errors.starts_at }}</p>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                    >
                        Confirm booking
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
