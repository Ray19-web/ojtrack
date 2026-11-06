<?php
$person_name_prefix = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($person_name_prefix ?? 'person')) ?: 'person';
$person_name_fields = $person_name_fields ?? ['first' => 'first_name', 'middle' => 'middle_name', 'last' => 'last_name'];
$person_name_values = $person_name_values ?? [];
$person_name_required = $person_name_required ?? false;
?>
<div class="form-row person-name-parts" data-person-name-parts>
  <div class="form-group">
    <label class="form-label" for="<?= e($person_name_prefix) ?>FirstName">First Name<?= $person_name_required ? ' <span style="color:red">*</span>' : '' ?></label>
    <input type="text" id="<?= e($person_name_prefix) ?>FirstName" name="<?= e($person_name_fields['first']) ?>" class="form-control" maxlength="60" value="<?= e($person_name_values['first'] ?? '') ?>" autocomplete="given-name" data-uppercase-name<?= $person_name_required ? ' required' : '' ?>>
  </div>
  <div class="form-group">
    <label class="form-label" for="<?= e($person_name_prefix) ?>MiddleName">Middle Name</label>
    <input type="text" id="<?= e($person_name_prefix) ?>MiddleName" name="<?= e($person_name_fields['middle']) ?>" class="form-control" maxlength="60" value="<?= e($person_name_values['middle'] ?? '') ?>" autocomplete="additional-name" data-uppercase-name>
  </div>
  <div class="form-group">
    <label class="form-label" for="<?= e($person_name_prefix) ?>LastName">Last Name<?= $person_name_required ? ' <span style="color:red">*</span>' : '' ?></label>
    <input type="text" id="<?= e($person_name_prefix) ?>LastName" name="<?= e($person_name_fields['last']) ?>" class="form-control" maxlength="60" value="<?= e($person_name_values['last'] ?? '') ?>" autocomplete="family-name" data-uppercase-name<?= $person_name_required ? ' required' : '' ?>>
  </div>
</div>
