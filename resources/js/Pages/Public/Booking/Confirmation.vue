<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    booking: { type: Object, required: true },
    eventType: { type: Object, required: true },
    host: { type: Object, required: true },
});
</script>

<template>
    <Head title="Booking Confirmed" />

    <div class="min-h-screen bg-gray-50">
        <div class="mx-auto max-w-lg px-4 py-16 sm:px-6">
            <div class="rounded-lg bg-white p-8 text-center shadow-sm">
                <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-green-100">
                    <svg class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                </div>

                <h1 class="mb-2 text-xl font-semibold text-gray-900">You're booked!</h1>
                <p class="mb-6 text-sm text-gray-500">
                    A confirmation has been sent to {{ booking.guest_email }}.
                </p>

                <div class="rounded-md bg-gray-50 p-4 text-left text-sm">
                    <p class="font-medium text-gray-800">{{ eventType.name }}</p>
                    <p class="text-gray-500">with {{ host.name }}</p>
                    <p class="mt-2 text-gray-700">{{ booking.starts_at }}</p>
                </div>
            </div>
        </div>
    </div>
</template>
