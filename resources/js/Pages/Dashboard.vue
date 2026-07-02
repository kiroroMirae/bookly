<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    upcomingBookings: { type: Array, required: true },
    stats: { type: Object, required: true },
    eventTypeLinks: { type: Array, required: true },
    timezone: { type: String, required: true },
});

const formatTime = (isoString) =>
    new Date(isoString).toLocaleString('en-US', {
        timeZone: props.timezone,
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });

const copyLink = (url) => navigator.clipboard?.writeText(url);
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Dashboard
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="rounded-lg bg-white p-6 shadow-sm">
                        <p class="text-sm font-medium text-gray-500">Bookings this week</p>
                        <p class="mt-1 text-3xl font-semibold text-gray-900">
                            {{ stats.bookingsThisWeek }}
                        </p>
                    </div>
                    <div class="rounded-lg bg-white p-6 shadow-sm">
                        <p class="text-sm font-medium text-gray-500">Active event types</p>
                        <p class="mt-1 text-3xl font-semibold text-gray-900">
                            {{ stats.activeEventTypes }}
                        </p>
                    </div>
                </div>

                <div class="rounded-lg bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                        <h3 class="text-sm font-medium text-gray-700">Upcoming bookings</h3>
                        <Link :href="route('bookings.index')" class="text-sm text-indigo-600 hover:text-indigo-800">
                            View all
                        </Link>
                    </div>

                    <p v-if="upcomingBookings.length === 0" class="px-6 py-8 text-sm text-gray-500">
                        No upcoming bookings yet. Share one of your booking links below to get started.
                    </p>

                    <ul v-else class="divide-y divide-gray-100">
                        <li
                            v-for="booking in upcomingBookings"
                            :key="booking.id"
                            class="flex items-center gap-3 px-6 py-4"
                        >
                            <div
                                class="h-2.5 w-2.5 shrink-0 rounded-full"
                                :style="{ backgroundColor: booking.event_type_color ?? '#6366f1' }"
                            />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-gray-900">
                                    {{ booking.event_type_name }} · {{ booking.guest_name }}
                                </p>
                            </div>
                            <p class="shrink-0 text-sm text-gray-500">{{ formatTime(booking.starts_at) }}</p>
                        </li>
                    </ul>
                </div>

                <div class="rounded-lg bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                        <h3 class="text-sm font-medium text-gray-700">Your booking links</h3>
                        <Link :href="route('event-types.index')" class="text-sm text-indigo-600 hover:text-indigo-800">
                            Manage event types
                        </Link>
                    </div>

                    <p v-if="eventTypeLinks.length === 0" class="px-6 py-8 text-sm text-gray-500">
                        No active event types.
                        <Link :href="route('event-types.create')" class="text-indigo-600 hover:text-indigo-800">
                            Create one
                        </Link>
                        to start accepting bookings.
                    </p>

                    <ul v-else class="divide-y divide-gray-100">
                        <li
                            v-for="link in eventTypeLinks"
                            :key="link.url"
                            class="flex items-center justify-between gap-3 px-6 py-4"
                        >
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900">{{ link.name }}</p>
                                <a
                                    :href="link.url"
                                    target="_blank"
                                    class="truncate text-xs text-gray-500 hover:text-indigo-600"
                                >
                                    {{ link.url }}
                                </a>
                            </div>
                            <button
                                type="button"
                                @click="copyLink(link.url)"
                                class="shrink-0 rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50"
                            >
                                Copy link
                            </button>
                        </li>
                    </ul>
                </div>

                <div class="flex gap-3">
                    <Link
                        :href="route('availability.edit')"
                        class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        Edit availability
                    </Link>
                    <Link
                        :href="route('event-types.create')"
                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                    >
                        New event type
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
