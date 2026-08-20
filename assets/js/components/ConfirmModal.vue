<template>
    <div class="fct-modal-overlay" @click.self="$emit('cancel')">
        <div class="fct-modal">
            <h3>{{ title }}</h3>
            <p>{{ message }}</p>
            <ul v-if="items && items.length" class="fct-modal-list">
                <li v-for="item in items" :key="item">{{ item }}</li>
            </ul>
            <p class="fct-modal-warning">{{ __('This action cannot be undone.') }}</p>
            <div class="fct-modal-actions">
                <button @click="$emit('cancel')" class="fct-btn fct-btn--secondary">{{ __('Cancel') }}</button>
                <button @click="$emit('confirm')" class="fct-btn fct-btn--danger">{{ confirmText }}</button>
            </div>
        </div>
    </div>
</template>

<script>
import { __ } from '../i18n.js';

export default {
    name: 'ConfirmModal',
    props: {
        title: { type: String, required: true },
        message: { type: String, required: true },
        items: { type: Array, default: function () { return []; } },
        confirmText: { type: String, default: function () { return __('Confirm'); } }
    },
    emits: ['confirm', 'cancel']
};
</script>
