<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    windows: {
        type: Array,
        required: true,
    },
});

const DAYS = [
    { value: 1, label: 'Monday' },
    { value: 2, label: 'Tuesday' },
    { value: 3, label: 'Wednesday' },
    { value: 4, label: 'Thursday' },
    { value: 5, label: 'Friday' },
    { value: 6, label: 'Saturday' },
    { value: 0, label: 'Sunday' },
];

const form = useForm({
    windows: props.windows.map((w) => ({
        day_of_week: w.day_of_week,
        start_time: w.start_time.substring(0, 5),
        end_time: w.end_time.substring(0, 5),
    })),
});

const windowsForDay = (day) =>
    form.windows
        .map((w, i) => ({ ...w, _index: i }))
        .filter((w) => w.day_of_week === day);

const addWindow = (day) => {
    form.windows.push({ day_of_week: day, start_time: '09:00', end_time: '17:00' });
};

const removeWindow = (index) => {
    form.windows.splice(index, 1);
};

const submit = () => {
    form.put(route('availability.update'));
};
</script>

<template>
    <Head title="Availability" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Availability
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <p class="mb-6 text-sm text-gray-600">
                        Set the times you are available each week. Guests can only book within these hours.
                    </p>

                    <form @submit.prevent="submit" class="space-y-6">
                        <div v-for="day in DAYS" :key="day.value" class="border-b border-gray-100 pb-4 last:border-0">
                            <div class="flex items-center justify-between">
                                <span class="w-28 text-sm font-medium text-gray-700">{{ day.label }}</span>

                                <div class="flex-1 space-y-2">
                                    <div
                                        v-for="w in windowsForDay(day.value)"
                                        :key="w._index"
                                        class="flex items-center gap-2"
                                    >
                                        <input
                                            v-model="form.windows[w._index].start_time"
                                            type="time"
                                            class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        />
                                        <span class="text-gray-400">–</span>
                                        <input
                                            v-model="form.windows[w._index].end_time"
                                            type="time"
                                            class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        />
                                        <button
                                            type="button"
                                            @click="removeWindow(w._index)"
                                            class="text-gray-400 hover:text-red-500"
                                            aria-label="Remove"
                                        >
                                            ✕
                                        </button>
                                        <div>
                                            <InputError :message="form.errors[`windows.${w._index}.start_time`]" />
                                            <InputError :message="form.errors[`windows.${w._index}.end_time`]" />
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        @click="addWindow(day.value)"
                                        class="text-sm text-indigo-600 hover:text-indigo-800"
                                    >
                                        + Add hours
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-3 pt-2">
                            <PrimaryButton :disabled="form.processing">
                                Save Availability
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
