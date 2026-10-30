<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import SkillController from '@/actions/App/Http/Controllers/Assistant/SkillController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/skills';
import type { Skill } from '@/types';

defineProps<{
    skills: Skill[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Skills', href: index() }],
    },
});

const textareaClass =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

const editing = ref<Skill | null>(null);

const form = useForm({
    name: '',
    description: '',
    instructions: '',
    enabled: true,
});

const edit = (skill: Skill) => {
    editing.value = skill;
    form.clearErrors();
    form.defaults({
        name: skill.name,
        description: skill.description,
        instructions: skill.instructions,
        enabled: skill.enabled,
    });
    form.reset();
};

const cancel = () => {
    editing.value = null;
    form.defaults({
        name: '',
        description: '',
        instructions: '',
        enabled: true,
    });
    form.reset();
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: cancel };

    if (editing.value) {
        form.put(SkillController.update.url(editing.value.id), options);
    } else {
        form.post(SkillController.store.url(), options);
    }
};

const remove = (skill: Skill) => {
    if (confirm(`Delete the ${skill.name} skill?`)) {
        router.delete(SkillController.destroy.url(skill.id), {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Head title="Skills" />

    <div class="mx-auto flex max-w-3xl flex-col gap-8 p-4">
        <Heading
            title="Skills"
            description="Instructions your assistant loads when a task matches the skill's description"
        />

        <div class="space-y-3">
            <p v-if="!skills.length" class="text-sm text-muted-foreground">
                No skills yet.
            </p>

            <div
                v-for="skill in skills"
                :key="skill.id"
                class="flex items-start justify-between gap-4 rounded-lg border p-4"
            >
                <div class="min-w-0">
                    <div
                        class="flex items-center gap-2 font-mono text-sm font-medium"
                    >
                        {{ skill.name }}
                        <Badge v-if="!skill.enabled" variant="outline"
                            >disabled</Badge
                        >
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{ skill.description }}
                    </p>
                </div>
                <div class="flex shrink-0 gap-2">
                    <Button variant="outline" size="sm" @click="edit(skill)"
                        >Edit</Button
                    >
                    <Button
                        variant="destructive"
                        size="sm"
                        @click="remove(skill)"
                        >Delete</Button
                    >
                </div>
            </div>
        </div>

        <form class="space-y-4 rounded-lg border p-4" @submit.prevent="submit">
            <Heading
                variant="small"
                :title="editing ? `Edit ${editing.name}` : 'Add a skill'"
            />

            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    required
                    placeholder="property-taxes"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="description"
                    >When should the assistant use it?</Label
                >
                <Input
                    id="description"
                    v-model="form.description"
                    required
                    placeholder="Use when answering questions about property tax bills."
                />
                <InputError :message="form.errors.description" />
            </div>

            <div class="grid gap-2">
                <Label for="instructions">Instructions</Label>
                <textarea
                    id="instructions"
                    v-model="form.instructions"
                    rows="8"
                    required
                    :class="textareaClass"
                />
                <InputError :message="form.errors.instructions" />
            </div>

            <Label class="flex items-center gap-2">
                <Checkbox
                    :model-value="form.enabled"
                    @update:model-value="form.enabled = $event === true"
                />
                Enabled
            </Label>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing">
                    {{ editing ? 'Save' : 'Add skill' }}
                </Button>
                <Button
                    v-if="editing"
                    type="button"
                    variant="ghost"
                    @click="cancel"
                    >Cancel</Button
                >
            </div>
        </form>
    </div>
</template>
