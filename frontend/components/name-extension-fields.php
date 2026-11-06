<?php
$name_extension_prefix = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($name_extension_prefix ?? 'person')) ?: 'person';
$name_extension_type_field = $name_extension_type_field ?? 'name_extension_type';
$name_extension_value_field = $name_extension_value_field ?? 'name_extension_value';
$name_extension_entries = $name_extension_entries ?? null;
if (!is_array($name_extension_entries)) {
    $raw_types = is_array($name_extension_selected ?? null) ? array_values($name_extension_selected) : [($name_extension_selected ?? '')];
    $raw_values = is_array($name_extension_value ?? null) ? array_values($name_extension_value) : [($name_extension_value ?? '')];
    $name_extension_entries = [];
    foreach (array_unique(array_merge(array_keys($raw_types), array_keys($raw_values))) as $index) {
        $name_extension_entries[] = [
            'type' => is_string($raw_types[$index] ?? null) ? $raw_types[$index] : '',
            'value' => is_string($raw_values[$index] ?? null) ? normalize_name_extension_value($raw_values[$index]) : '',
        ];
    }
}
if (!$name_extension_entries) $name_extension_entries = [['type' => '', 'value' => '']];
$name_extension_entries = array_slice($name_extension_entries, 0, 10);
$name_extension_row = static function ($type, $value) use ($name_extension_prefix, $name_extension_type_field, $name_extension_value_field) {
    $selected = normalize_name_extension_type($type);
    $extension_value = is_string($value) ? normalize_name_extension_value($value) : '';
    ?>
    <div class="form-row name-extension-row" data-name-extension-row>
      <div class="form-group">
        <div class="form-label name-extension-label">
          <span>Name Extension Type</span>
          <button type="button" class="name-extension-help" aria-label="Learn about name extension types" title="Learn about name extension types" onclick="openModal('nameExtensionHelpModal')">?</button>
        </div>
        <select class="form-control" aria-label="Name Extension Type" name="<?= e($name_extension_type_field) ?>[]">
          <option value="">None</option>
          <?php foreach (name_extension_types() as $extension_type_key => $extension_type_label): ?>
            <option value="<?= e($extension_type_key) ?>"<?= $selected === $extension_type_key ? ' selected' : '' ?>><?= e($extension_type_label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Name Extension</label>
        <input type="text" class="form-control" name="<?= e($name_extension_value_field) ?>[]" value="<?= e($extension_value) ?>" maxlength="50" placeholder="e.g. Dr., PhD, CPA, Jr." data-uppercase-name>
      </div>
      <button type="button" class="btn btn-secondary name-extension-remove" data-remove-name-extension aria-label="Remove this name extension">Remove</button>
    </div>
    <?php
};
?>
<div class="name-extension-fields" data-name-extension-fields data-name-extension-prefix="<?= e($name_extension_prefix) ?>">
  <div class="user-form-section-heading name-extension-section-heading">Name extensions</div>
  <div class="name-extension-list" data-name-extension-list>
    <?php foreach ($name_extension_entries as $entry) $name_extension_row($entry['type'] ?? '', $entry['value'] ?? ''); ?>
  </div>
  <template data-name-extension-template>
    <?php $name_extension_row('', ''); ?>
  </template>
  <button type="button" class="btn btn-secondary btn-sm" data-add-name-extension>Add another name extension</button>
</div>
<?php if (empty($GLOBALS['name_extension_help_modal_rendered'])): $GLOBALS['name_extension_help_modal_rendered'] = true; ?>
<div class="modal-overlay name-extension-help-overlay" id="nameExtensionHelpModal" onclick="if(event.target===this)closeModal('nameExtensionHelpModal')">
  <section class="modal name-extension-help-modal" role="dialog" aria-modal="true" aria-labelledby="nameExtensionHelpTitle" aria-describedby="nameExtensionHelpDescription">
    <header class="name-extension-help-header">
      <div>
        <span class="name-extension-help-kicker">NAME FORMATTING GUIDE</span>
        <h2 class="modal-title" id="nameExtensionHelpTitle">Choose the right name extension</h2>
        <p id="nameExtensionHelpDescription">Each extension has a different place in a person's displayed name. Add a separate row for each applicable extension.</p>
      </div>
      <button type="button" class="name-extension-help-close" aria-label="Close name extension guide" onclick="closeModal('nameExtensionHelpModal')">&times;</button>
    </header>
    <div class="name-extension-help-list">
      <article class="name-extension-help-item">
        <span class="name-extension-help-mark">01</span>
        <div><h3>Professional Title</h3><p>Honorific or professional title shown before the person's name.</p><span class="name-extension-help-example">DR. JUAN DELA CRUZ</span><span class="name-extension-help-values">Examples: Dr., Atty., Engr.</span></div>
      </article>
      <article class="name-extension-help-item">
        <span class="name-extension-help-mark">02</span>
        <div><h3>Academic Credential</h3><p>Degree or academic qualification shown after the person's name.</p><span class="name-extension-help-example">JUAN DELA CRUZ, PHD</span><span class="name-extension-help-values">Examples: PhD, MA, MS</span></div>
      </article>
      <article class="name-extension-help-item">
        <span class="name-extension-help-mark">03</span>
        <div><h3>Professional Credential</h3><p>Professional license or certification shown after the person's name.</p><span class="name-extension-help-example">JUAN DELA CRUZ, CPA</span><span class="name-extension-help-values">Examples: CPA, RN</span></div>
      </article>
      <article class="name-extension-help-item">
        <span class="name-extension-help-mark">04</span>
        <div><h3>Name Suffix</h3><p>Generational or sequence suffix shown after the person's name.</p><span class="name-extension-help-example">JUAN DELA CRUZ, JR.</span><span class="name-extension-help-values">Examples: Jr., Sr., III, IV</span></div>
      </article>
    </div>
    <footer class="name-extension-help-footer"><span>Leave the type as <strong>None</strong> when no extension applies.</span><button type="button" class="btn btn-primary" onclick="closeModal('nameExtensionHelpModal')">Got it</button></footer>
  </section>
</div>
<?php endif; ?>
