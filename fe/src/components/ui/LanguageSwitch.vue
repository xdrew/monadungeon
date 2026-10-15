<template>
  <!-- EN / RU / PL: a small iron ring with the current code, a three-item menu under it -->
  <div
    class="language-switch"
    @keydown.esc="open = false"
  >
    <button
      ref="trigger"
      type="button"
      class="language-switch__ring btn btn--iron btn--sm"
      :aria-label="t('common.language')"
      :title="t('common.language')"
      aria-haspopup="menu"
      :aria-expanded="open"
      @click="open = !open"
    >
      {{ locale.toUpperCase() }}
    </button>
    <ul
      v-if="open"
      class="language-switch__menu"
      role="menu"
    >
      <li
        v-for="code in LOCALES"
        :key="code"
        role="none"
      >
        <button
          type="button"
          role="menuitemradio"
          class="language-switch__item"
          :aria-checked="code === locale"
          :lang="code"
          @click="choose(code)"
        >
          <span class="language-switch__code">{{ code.toUpperCase() }}</span>
          {{ t(`common.languages.${code}`) }}
        </button>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { LOCALES, setLocale } from '@/i18n';

const { t, locale } = useI18n();
const open = ref(false);
const trigger = ref(null);

const choose = (code) => {
  setLocale(code);
  open.value = false;
  trigger.value?.focus();
};

// A click anywhere else closes the menu
const onDocumentClick = (event) => {
  if (open.value && !event.target.closest('.language-switch')) open.value = false;
};
onMounted(() => document.addEventListener('click', onDocumentClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick));
</script>

<style scoped>
.language-switch {
  position: relative;
}

.language-switch__ring {
  font-family: var(--font-body);
  font-weight: 600;
  font-size: 12px;
  letter-spacing: 0.08em;
}

.language-switch__menu {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  z-index: 5;
  min-width: 150px;
  margin: 0;
  padding: 4px;
  list-style: none;
  background: var(--bg-panel);
  border: 1px solid var(--iron-dark);
  box-shadow: 0 10px 24px rgba(0, 0, 0, 0.8);
}

.language-switch__item {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 8px 10px;
  border: 0;
  background: none;
  color: var(--text-primary);
  font-family: var(--font-body);
  font-size: 15px;
  text-align: left;
  cursor: pointer;
}

.language-switch__item:hover,
.language-switch__item:focus-visible {
  outline: none;
  background: rgba(var(--ember-rgb), 0.12);
}

.language-switch__item[aria-checked='true'] {
  color: var(--text-accent);
}

.language-switch__code {
  width: 22px;
  font-weight: 600;
  font-size: 12px;
  letter-spacing: 0.08em;
  color: var(--text-muted);
}
</style>
