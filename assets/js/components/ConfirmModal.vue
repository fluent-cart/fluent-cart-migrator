<template>
    <div class="fct-modal-overlay" @click.self="$emit('cancel')">
        <div class="fct-modal">
            <h3>{{ title }}</h3>
            <p>{{ message }}</p>
            <ul v-if="items && items.length" class="fct-modal-list">
                <li v-for="item in items" :key="item">{{ item }}</li>
            </ul>
            <p v-if="destructive" class="fct-modal-warning">This action cannot be undone.</p>
            <div class="fct-modal-actions">
                <button @click="$emit('cancel')" class="fct-btn fct-btn--secondary">Cancel</button>
                <button @click="$emit('confirm')" :class="destructive ? 'fct-btn fct-btn--danger' : 'fct-btn fct-btn--primary'">{{ confirmText }}</button>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: 'ConfirmModal',
    props: {
        title: { type: String, required: true },
        message: { type: String, required: true },
        items: { type: Array, default: function () { return []; } },
        confirmText: { type: String, default: 'Confirm' },
        destructive: { type: Boolean, default: true }
    },
    emits: ['confirm', 'cancel']
};
</script>
