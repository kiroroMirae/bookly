<script setup>
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';

defineProps({
    upcoming: { type: Array, required: true },
    past: { type: Array, required: true },
});

const cancelForm = useForm({ cancellation_reason: '' });
const notesDrafts = ref({});

const cancel = (bookingId) => {
    cancelForm.patch(route('bookings.cancel', bookingId), {
        onSuccess: () => { cancelForm.reset(); },
    });
};

const markStatus = (bookingId, status) => {
    router.patch(route('bookings.update', bookingId), { status }, { preserveScroll: true });
};

const saveNotes = (booking) => {
    const notes = notesDrafts.value[booking.id] ?? booking.host_notes ?? '';
    router.patch(route('bookings.update', booking.id), { host_notes: notes }, { preserveScroll: true });
};

const EVENT_LABELS = {
    created: 'Booked',
    rescheduled: 'Rescheduled',
    cancelled: 'Cancelled',
    completed: 'Marked completed',
    no_show: 'Marked no-show',
};

const describeEvent = (event) => {
    const label = EVENT_LABELS[event.event] ?? event.event;
    const actor = event.actor === 'guest' ? 'by guest' : event.actor === 'host' ? 'by you' : '';
    return actor ? `${label} ${actor}` : label;
};

const formatTimestamp = (value) => new Date(value).toLocaleString();

const eventDetail = (event) => {
    if (event.metadata?.reason) {
        return `Reason: ${event.metadata.reason}`;
    }
    if (event.metadata?.from && event.metadata?.to) {
        return `${formatTimestamp(event.metadata.from)} → ${formatTimestamp(event.metadata.to)}`;
    }
    return null;
};
</script>

<template>
    <Head title="Bookings" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Bookings</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-8 sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h3 class="text-sm font-medium text-gray-700">Upcoming</h3>
                    </div>

                    <div v-if="upcoming.length === 0" class="px-6 py-8 text-center text-sm text-gray-400">
                        No upcoming bookings.
                    </div>

                    <ul v-else class="divide-y divide-gray-100">
                        <li v-for="booking in upcoming" :key="booking.id" class="space-y-2 px-6 py-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">{{ booking.guest_name }}</p>
                                    <p class="text-xs text-gray-500">{{ booking.guest_email }}</p>
                                    <p class="mt-1 text-xs text-gray-500">
                                        {{ booking.event_type?.name }} · {{ booking.starts_at }}
                                    </p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span
                                        class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="booking.status === 'confirmed' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'"
                                    >
                                        {{ booking.status }}
                                    </span>
                                    <button
                                        v-if="booking.status === 'confirmed'"
                                        type="button"
                                        @click="cancel(booking.id)"
                                        :disabled="cancelForm.processing"
                                        class="text-xs text-red-500 hover:text-red-700 disabled:opacity-50"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </div>

                            <RescheduleForm v-if="booking.status === 'confirmed'" :booking="booking" />

                            <NotesEditor
                                :booking="booking"
                                v-model="notesDrafts[booking.id]"
                                @save="saveNotes(booking)"
                            />

                            <p
                                v-if="booking.status === 'cancelled' && booking.cancellation_reason"
                                class="text-xs text-red-600"
                            >
                                Cancellation reason: {{ booking.cancellation_reason }}
                            </p>

                            <details v-if="booking.events?.length" class="text-xs text-gray-500">
                                <summary class="cursor-pointer select-none hover:text-gray-700">
                                    History ({{ booking.events.length }})
                                </summary>
                                <ul class="mt-2 space-y-1 border-l border-gray-200 pl-3">
                                    <li v-for="event in booking.events" :key="event.id">
                                        <span class="text-gray-700">{{ describeEvent(event) }}</span>
                                        · {{ formatTimestamp(event.created_at) }}
                                        <span v-if="eventDetail(event)" class="block text-gray-400">
                                            {{ eventDetail(event) }}
                                        </span>
                                    </li>
                                </ul>
                            </details>
                        </li>
                    </ul>
                </div>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h3 class="text-sm font-medium text-gray-700">Past</h3>
                    </div>

                    <div v-if="past.length === 0" class="px-6 py-8 text-center text-sm text-gray-400">
                        No past bookings.
                    </div>

                    <ul v-else class="divide-y divide-gray-100">
                        <li v-for="booking in past" :key="booking.id" class="space-y-2 px-6 py-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">{{ booking.guest_name }}</p>
                                    <p class="text-xs text-gray-500">{{ booking.event_type?.name }} · {{ booking.starts_at }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span
                                        class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="{
                                            'bg-blue-100 text-blue-700': booking.status === 'completed',
                                            'bg-amber-100 text-amber-700': booking.status === 'no_show',
                                            'bg-gray-100 text-gray-500': !['completed', 'no_show'].includes(booking.status),
                                        }"
                                    >
                                        {{ booking.status }}
                                    </span>
                                    <template v-if="booking.status === 'confirmed'">
                                        <button
                                            type="button"
                                            @click="markStatus(booking.id, 'completed')"
                                            class="text-xs text-indigo-600 hover:text-indigo-800"
                                        >
                                            Mark completed
                                        </button>
                                        <button
                                            type="button"
                                            @click="markStatus(booking.id, 'no_show')"
                                            class="text-xs text-amber-600 hover:text-amber-800"
                                        >
                                            Mark no-show
                                        </button>
                                    </template>
                                </div>
                            </div>

                            <NotesEditor
                                :booking="booking"
                                v-model="notesDrafts[booking.id]"
                                @save="saveNotes(booking)"
                            />

                            <p
                                v-if="booking.status === 'cancelled' && booking.cancellation_reason"
                                class="text-xs text-red-600"
                            >
                                Cancellation reason: {{ booking.cancellation_reason }}
                            </p>

                            <details v-if="booking.events?.length" class="text-xs text-gray-500">
                                <summary class="cursor-pointer select-none hover:text-gray-700">
                                    History ({{ booking.events.length }})
                                </summary>
                                <ul class="mt-2 space-y-1 border-l border-gray-200 pl-3">
                                    <li v-for="event in booking.events" :key="event.id">
                                        <span class="text-gray-700">{{ describeEvent(event) }}</span>
                                        · {{ formatTimestamp(event.created_at) }}
                                        <span v-if="eventDetail(event)" class="block text-gray-400">
                                            {{ eventDetail(event) }}
                                        </span>
                                    </li>
                                </ul>
                            </details>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
