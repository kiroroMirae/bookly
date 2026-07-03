<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    windows: {
        type: Array,
        required: true,
    },
    overrides: {
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

const blockAllDay = ref(true);

const overrideForm = useForm({
    date: '',
    start_time: '13:00',
    end_time: '15:00',
});

const today = new Date().toISOString().substring(0, 10);

const submitOverride = () => {
    overrideForm
        .transform((data) => (blockAllDay.value ? { date: data.date } : data))
        .post(route('availability.overrides.store'), {
            preserveScroll: true,
            onSuccess: () => overrideForm.reset('date'),
        });
};

const deleteOverride = (override) => {
    router.delete(route('availability.overrides.destroy', override.id), {
        preserveScroll: true,
    });
};

const formatOverrideDate = (isoDate) => {
    const [year, month, day] = isoDate.substring(0, 10).split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, {
        weekday: 'short',
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
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

                <div class="mt-6 overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="text-base font-semibold text-gray-800">Date overrides</h3>
                    <p class="mb-6 mt-1 text-sm text-gray-600">
                        Block a specific date or replace your weekly hours for that day.
                        Existing bookings are not affected.
                    </p>

                    <form @submit.prevent="submitOverride" class="space-y-4">
                        <div class="flex flex-wrap items-center gap-3">
                            <input
                                v-model="overrideForm.date"
                                type="date"
                                :min="today"
                                required
                                class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />

                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input
                                    v-model="blockAllDay"
                                    type="checkbox"
                                    class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                />
                                Block entire day
                            </label>

                            <template v-if="!blockAllDay">
                                <input
                                    v-model="overrideForm.start_time"
                                    type="time"
                                    class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                />
                                <span class="text-gray-400">–</span>
                                <input
                                    v-model="overrideForm.end_time"
                                    type="time"
                                    class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                />
                            </template>

                            <PrimaryButton :disabled="overrideForm.processing">
                                Add override
                            </PrimaryButton>
                        </div>

                        <InputError :message="overrideForm.errors.date" />
                        <InputError :message="overrideForm.errors.start_time" />
                        <InputError :message="overrideForm.errors.end_time" />
                    </form>

                    <ul v-if="overrides.length" class="mt-6 divide-y divide-gray-100">
                        <li
                            v-for="override in overrides"
                            :key="override.id"
                            class="flex items-center justify-between py-3"
                        >
                            <div class="flex items-center gap-3">
                                <span class="text-sm font-medium text-gray-700">
                                    {{ formatOverrideDate(override.date) }}
                                </span>
                                <span
                                    v-if="override.start_time"
                                    class="text-sm text-gray-500"
                                >
                                    {{ override.start_time.substring(0, 5) }} – {{ override.end_time.substring(0, 5) }}
                                </span>
                                <span
                                    v-else
                                    class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-600"
                                >
                                    Unavailable
                                </span>
                            </div>
                            <button
                                type="button"
                                @click="deleteOverride(override)"
                                class="text-gray-400 hover:text-red-500"
                                aria-label="Remove override"
                            >
                                ✕
                            </button>
                        </li>
                    </ul>
                    <p v-else class="mt-6 text-sm text-gray-400">
                        No upcoming overrides.
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
