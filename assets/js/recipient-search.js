(function () {
  'use strict';
  const normalize = (value) => String(value || '')
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase().trim();

  function initSelectSearch(input) {
    const select = document.getElementById(input.dataset.recipientSelect);
    if (!select) return;
    const count = document.createElement('div');
    count.className = 'recipient-picker-summary';
    count.setAttribute('aria-live', 'polite');
    count.textContent = `${Array.from(select.options).filter((option) => option.value).length} recipients`;
    input.insertAdjacentElement('afterend', count);

    input.addEventListener('input', () => {
      const needle = normalize(input.value);
      let matches = 0;
      Array.from(select.options).forEach((option) => {
        if (!option.value) {
          option.hidden = false;
          return;
        }
        const matched = !needle || normalize(option.textContent).includes(needle);
        option.hidden = !matched && option.value !== select.value;
        if (matched) matches++;
      });
      Array.from(select.querySelectorAll('optgroup')).forEach((group) => {
        group.hidden = !Array.from(group.options).some((option) => !option.hidden);
      });
      count.textContent = `${matches} recipient${matches === 1 ? '' : 's'} found`;
    });
    select.addEventListener('change', () => {
      if (input.value) input.dispatchEvent(new Event('input'));
    });
  }

  function initCheckboxPicker(input) {
    const list = document.getElementById(input.dataset.recipientFilter);
    const picker = input.closest('[data-recipient-picker]');
    if (!list || !picker) return;
    const options = Array.from(list.querySelectorAll('[data-recipient-option]'));
    const selectedLabel = picker.querySelector('[data-recipient-selected]');
    const matchesLabel = picker.querySelector('[data-recipient-matches]');
    const empty = picker.querySelector('[data-recipient-no-results]');

    function update() {
      const needle = normalize(input.value);
      let visible = 0;
      let selected = 0;
      options.forEach((option) => {
        const checkbox = option.querySelector('input[type="checkbox"]');
        const matched = !needle || normalize(option.textContent).includes(needle);
        option.hidden = !matched;
        if (matched) visible++;
        if (checkbox && checkbox.checked) selected++;
      });
      list.querySelectorAll('[data-recipient-group-title]').forEach((title) => {
        const group = title.closest('.recipient-group');
        if (group) group.hidden = !group.querySelector('[data-recipient-option]:not([hidden])');
      });
      if (empty) empty.hidden = visible !== 0;
      if (selectedLabel) selectedLabel.textContent = `${selected} selected`;
      if (matchesLabel) matchesLabel.textContent = `${visible} shown`;
    }

    input.addEventListener('input', update);
    list.addEventListener('change', update);
    update();
  }

  function init() {
    document.querySelectorAll('input[data-recipient-select]').forEach(initSelectSearch);
    document.querySelectorAll('[data-recipient-filter]').forEach(initCheckboxPicker);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
