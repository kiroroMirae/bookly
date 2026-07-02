<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';

const props = defineProps({
    booking: { type: Object, required: true },
    eventType: { type: Object, required: true },
    host: { type: Object, required: true },
    slots: { type: Array, required: true },
    selectedDate: { type: String, required: true },
    canModify: { type: Boolean, required: true },
    cancelUrl: { type: String, required: true },
    rescheduleUrl: { type: String, required: true },
    manageUrl: { type: String, required: true },
});

const showCancelForm = ref(false);
const selectedSlot = ref(null);

const cancelForm = useForm({ cancellation_reason: '' });
const rescheduleForm = useForm({ starts_at: '' });

const currentTime = computed(() =>
    new Date(props.booking.starts_at).toLocaleString('en-US', {
        timeZone: props.booking.guest_timezone,
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    })
);

const selectSlot = (slot) => {
    selectedSlot.value = slot;
    rescheduleForm.starts_at = slot.starts_at;
};

const changeDate = (date) => {
    router.get(props.manageUrl, { date }, { preserveState: false });
};

const submitCancel = () => cancelForm.patch(props.cancelUrl);
const submitReschedule = () => rescheduleForm.patch(props.rescheduleUrl);

const today = new Date().toISOString().split('T')[0];
</script>

<template>
    <Head title="Manage booking" />

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

                <dl class="mt-4 space-y-1 text-sm">
                    <div class="flex gap-2">
                        <dt class="font-medium text-gray-700">Booked for:</dt>
                        <dd class="text-gray-600">{{ booking.guest_name }}</dd>
                    </div>
                    <div class="flex gap-2">
                        <dt class="font-medium text-gray-700">Time:</dt>
                        <dd class="text-gray-600">{{ currentTime }} ({{ booking.guest_timezone }})</dd>
                    </div>
                    <div class="flex gap-2">
                        <dt class="font-medium text-gray-700">Status:</dt>
                        <dd>
                            <span
                                :class="[
                                    'rounded-full px-2 py-0.5 text-xs font-medium',
                                    booking.status === 'confirmed'
                                        ? 'bg-green-100 text-green-800'
                                        : 'bg-gray-100 text-gray-600',
                                ]"
                            >
                                {{ booking.status }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </div>

            <div v-if="!canModify" class="rounded-lg bg-white p-6 text-sm text-gray-600 shadow-sm">
                This booking can no longer be changed.
            </div>

            <template v-else>
                <div class="mb-6 rounded-lg bg-white p-6 shadow-sm">
                    <h2 class="mb-4 text-sm font-medium text-gray-700">Reschedule</h2>

                    <label class="mb-2 block text-sm text-gray-600">Pick a new date</label>
                    <input
                        type="date"
                        :value="selectedDate"
                        :min="today"
                        @change="changeDate($event.target.value)"
                        class="mb-4 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />

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

                    <p v-if="rescheduleForm.errors.starts_at" class="mt-2 text-xs text-red-600">
                        {{ rescheduleForm.errors.starts_at }}
                    </p>

                    <button
                        v-if="selectedSlot"
                        type="button"
                        @click="submitReschedule"
                        :disabled="rescheduleForm.processing"
                        class="mt-4 w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                    >
                        Confirm new time
                    </button>
                </div>

                <div class="rounded-lg bg-white p-6 shadow-sm">
                    <h2 class="mb-4 text-sm font-medium text-gray-700">Cancel booking</h2>

                    <button
                        v-if="!showCancelForm"
                        type="button"
                        @click="showCancelForm = true"
                        class="rounded-md border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50"
                    >
                        Cancel this booking
                    </button>

                    <form v-else @submit.prevent="submitCancel" class="space-y-4">
                        <div>
                            <label for="cancellation_reason" class="block text-sm text-gray-600">
                                Reason (optional)
                            </label>
                            <textarea
                                id="cancellation_reason"
                                v-model="cancelForm.cancellation_reason"
                                rows="2"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                        </div>
                        <div class="flex gap-2">
                            <button
                                type="submit"
                                :disabled="cancelForm.processing"
                                class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
                            >
                                Confirm cancellation
                            </button>
                            <button
                                type="button"
                                @click="showCancelForm = false"
                                class="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                            >
                                Keep booking
                            </button>
                        </div>
                    </form>
                </div>
            </template>
        </div>
    </div>
</template>
