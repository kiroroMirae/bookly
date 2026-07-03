<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    description: '',
    duration_minutes: 30,
    color: '#3B82F6',
    is_active: true,
    buffer_before_minutes: 0,
    buffer_after_minutes: 0,
    minimum_notice_minutes: 0,
    booking_window_days: 60,
    max_bookings_per_day: '',
});

const submit = () => {
    form.post(route('event-types.store'));
};
</script>

<template>
    <Head title="New Event Type" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                New Event Type
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <form @submit.prevent="submit" class="space-y-6">
                        <div>
                            <InputLabel for="name" value="Name" />
                            <TextInput
                                id="name"
                                v-model="form.name"
                                type="text"
                                class="mt-1 block w-full"
                                required
                                autofocus
                            />
                            <InputError :message="form.errors.name" class="mt-2" />
                        </div>

                        <div>
                            <InputLabel for="description" value="Description" />
                            <textarea
                                id="description"
                                v-model="form.description"
                                rows="3"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                            <InputError :message="form.errors.description" class="mt-2" />
                        </div>

                        <div>
                            <InputLabel for="duration_minutes" value="Duration (minutes)" />
                            <TextInput
                                id="duration_minutes"
                                v-model="form.duration_minutes"
                                type="number"
                                min="5"
                                max="480"
                                class="mt-1 block w-full"
                                required
                            />
                            <InputError :message="form.errors.duration_minutes" class="mt-2" />
                        </div>

                        <div>
                            <InputLabel for="color" value="Color" />
                            <input
                                id="color"
                                v-model="form.color"
                                type="color"
                                class="mt-1 h-10 w-20 cursor-pointer rounded-md border border-gray-300"
                            />
                            <InputError :message="form.errors.color" class="mt-2" />
                        </div>

                        <div class="flex items-center gap-2">
                            <input
                                id="is_active"
                                v-model="form.is_active"
                                type="checkbox"
                                class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                            />
                            <InputLabel for="is_active" value="Active" />
                        </div>

                        <fieldset class="space-y-4 border-t border-gray-200 pt-6">
                            <legend class="mb-2 text-sm font-medium text-gray-700">Booking policy</legend>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <InputLabel for="buffer_before_minutes" value="Buffer before (minutes)" />
                                    <TextInput
                                        id="buffer_before_minutes"
                                        v-model="form.buffer_before_minutes"
                                        type="number"
                                        min="0"
                                        max="120"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.buffer_before_minutes" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="buffer_after_minutes" value="Buffer after (minutes)" />
                                    <TextInput
                                        id="buffer_after_minutes"
                                        v-model="form.buffer_after_minutes"
                                        type="number"
                                        min="0"
                                        max="120"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.buffer_after_minutes" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="minimum_notice_minutes" value="Minimum notice (minutes)" />
                                    <TextInput
                                        id="minimum_notice_minutes"
                                        v-model="form.minimum_notice_minutes"
                                        type="number"
                                        min="0"
                                        max="10080"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.minimum_notice_minutes" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="booking_window_days" value="Booking window (days)" />
                                    <TextInput
                                        id="booking_window_days"
                                        v-model="form.booking_window_days"
                                        type="number"
                                        min="1"
                                        max="365"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.booking_window_days" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="max_bookings_per_day" value="Max bookings per day (optional)" />
                                    <TextInput
                                        id="max_bookings_per_day"
                                        v-model="form.max_bookings_per_day"
                                        type="number"
                                        min="1"
                                        max="100"
                                        placeholder="No limit"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.max_bookings_per_day" class="mt-2" />
                                </div>
                            </div>
                        </fieldset>

                        <div class="flex items-center gap-3">
                            <PrimaryButton :disabled="form.processing">
                                Create Event Type
                            </PrimaryButton>
                            <Link :href="route('event-types.index')">
                                <SecondaryButton type="button">Cancel</SecondaryButton>
                            </Link>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
