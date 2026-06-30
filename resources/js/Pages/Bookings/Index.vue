<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    upcoming: { type: Array, required: true },
    past: { type: Array, required: true },
});

const cancelForm = useForm({ cancellation_reason: '' });

const cancel = (bookingId) => {
    cancelForm.patch(route('bookings.cancel', bookingId), {
        onSuccess: () => { cancelForm.reset(); },
    });
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
                        <li v-for="booking in upcoming" :key="booking.id" class="flex items-center justify-between px-6 py-4">
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
                        <li v-for="booking in past" :key="booking.id" class="px-6 py-4">
                            <p class="text-sm font-medium text-gray-900">{{ booking.guest_name }}</p>
                            <p class="text-xs text-gray-500">{{ booking.event_type?.name }} · {{ booking.starts_at }}</p>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
