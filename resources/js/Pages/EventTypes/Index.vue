<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    eventTypes: {
        type: Array,
        default: () => [],
    },
});

function destroy(eventType) {
    if (confirm(`Delete "${eventType.name}"?`)) {
        router.delete(route('event-types.destroy', eventType.id));
    }
}
</script>

<template>
    <Head title="Event Types" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Event Types
                </h2>
                <Link
                    :href="route('event-types.create')"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                >
                    New Event Type
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div v-if="eventTypes.length === 0" class="p-6 text-gray-500">
                        No event types yet.
                        <Link
                            :href="route('event-types.create')"
                            class="text-indigo-600 underline"
                        >
                            Create your first.
                        </Link>
                    </div>

                    <table v-else class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Name
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Duration
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Status
                                </th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            <tr v-for="et in eventTypes" :key="et.id">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="inline-block h-3 w-3 rounded-full"
                                            :style="{ backgroundColor: et.color }"
                                        />
                                        <span class="font-medium text-gray-900">{{ et.name }}</span>
                                    </div>
                                    <div class="text-sm text-gray-500">/{{ et.slug }}</div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-700">
                                    {{ et.duration_minutes }} min
                                </td>
                                <td class="px-6 py-4">
                                    <span
                                        class="inline-flex rounded-full px-2 text-xs font-semibold"
                                        :class="et.is_active
                                            ? 'bg-green-100 text-green-800'
                                            : 'bg-gray-100 text-gray-600'"
                                    >
                                        {{ et.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right text-sm">
                                    <Link
                                        :href="route('event-types.edit', et.id)"
                                        class="mr-3 text-indigo-600 hover:text-indigo-900"
                                    >
                                        Edit
                                    </Link>
                                    <button
                                        type="button"
                                        class="text-red-600 hover:text-red-900"
                                        @click="destroy(et)"
                                    >
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
