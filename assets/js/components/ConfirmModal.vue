<template>
    <el-dialog
        :model-value="true"
        :title="title"
        width="460px"
        append-to-body
        class="fct-migrator-dialog"
        @close="$emit('cancel')"
    >
        <p class="fct-modal-message">{{ message }}</p>
        <ul v-if="items && items.length" class="fct-modal-list">
            <li v-for="item in items" :key="item">{{ item }}</li>
        </ul>
        <div class="fct-alert fct-alert-error">
            {{ __('This action cannot be undone.') }}
        </div>

        <template #footer>
            <el-button @click="$emit('cancel')">{{ __('Cancel') }}</el-button>
            <el-button type="danger" @click="$emit('confirm')">{{ confirmText }}</el-button>
        </template>
    </el-dialog>
</template>

<script>
import { __, i18nMixin } from '../i18n.js';

export default {
    mixins: [i18nMixin],
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
