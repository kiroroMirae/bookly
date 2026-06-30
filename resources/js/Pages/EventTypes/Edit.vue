<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    eventType: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    name: props.eventType.name,
    description: props.eventType.description ?? '',
    duration_minutes: props.eventType.duration_minutes,
    color: props.eventType.color,
    is_active: props.eventType.is_active,
});

const submit = () => {
    form.patch(route('event-types.update', props.eventType.id));
};
</script>

<template>
    <Head title="Edit Event Type" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Edit Event Type
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

                        <div class="flex items-center gap-3">
                            <PrimaryButton :disabled="form.processing">
                                Save Changes
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
