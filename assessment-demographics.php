<?php
if (!function_exists('assessment_demographics_escape')) {
    function assessment_demographics_escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('assessment_demographics_normalize')) {
    function assessment_demographics_normalize($values)
    {
        $values = is_array($values) ? $values : [];
        $keys = ['date', 'uhid', 'ip_no', 'client_name', 'consultant', 'contact_no', 'email_id', 'caregiver_name', 'filled_by'];
        $data = [];
        foreach ($keys as $key) {
            $data[$key] = is_string($values[$key] ?? null) ? trim($values[$key]) : '';
        }
        return $data;
    }
}

if (!function_exists('assessment_demographics_html')) {
    function assessment_demographics_html($fieldPrefix, $values, $options = [])
    {
        $values = assessment_demographics_normalize($values);
        $namePrefix = trim((string) $fieldPrefix, '[]');
        $dateRequired = !empty($options['date_required']);
        $clientRequired = !empty($options['client_required']);
        $hiddenFields = isset($options['hide_fields']) && is_array($options['hide_fields'])
            ? array_fill_keys($options['hide_fields'], true)
            : [];
        $emailType = 'email';
        $phoneType = 'tel';

        ob_start();
        ?>
        <section class="assessment-demographics-card" aria-label="Demographic details">
            <div class="assessment-demographics-heading">
                <h3>Demographic Details</h3>
            </div>
            <div class="assessment-demographics-grid">
                <?php if (!isset($hiddenFields['date'])): ?>
                <div class="assessment-demographics-field">
                    <label for="<?php echo assessment_demographics_escape($namePrefix); ?>_date">Date<span class="text-danger"> *</span></label>
                    <input id="<?php echo assessment_demographics_escape($namePrefix); ?>_date" class="form-control" type="date" name="<?php echo assessment_demographics_escape($namePrefix); ?>[date]" value="<?php echo assessment_demographics_escape($values['date']); ?>" <?php echo $dateRequired ? 'required' : ''; ?>>
                </div>
                <?php endif; ?>
                <?php if (!isset($hiddenFields['uhid'])): ?>
                <div class="assessment-demographics-field">
                    <label for="<?php echo assessment_demographics_escape($namePrefix); ?>_uhid">UHID</label>
                    <input id="<?php echo assessment_demographics_escape($namePrefix); ?>_uhid" class="form-control" type="text" name="<?php echo assessment_demographics_escape($namePrefix); ?>[uhid]" value="<?php echo assessment_demographics_escape($values['uhid']); ?>">
                </div>
                <?php endif; ?>
                <?php if (!isset($hiddenFields['ip_no'])): ?>
                <div class="assessment-demographics-field">
                    <label for="<?php echo assessment_demographics_escape($namePrefix); ?>_ip_no">IP No.</label>
                    <input id="<?php echo assessment_demographics_escape($namePrefix); ?>_ip_no" class="form-control" type="text" name="<?php echo assessment_demographics_escape($namePrefix); ?>[ip_no]" value="<?php echo assessment_demographics_escape($values['ip_no']); ?>">
                </div>
                <?php endif; ?>
                <?php if (!isset($hiddenFields['client_name'])): ?>
                <div class="assessment-demographics-field">
                    <label for="<?php echo assessment_demographics_escape($namePrefix); ?>_client_name">CLIENT NAME<?php if ($clientRequired): ?><span class="text-danger"> *</span><?php endif; ?></label>
                    <input id="<?php echo assessment_demographics_escape($namePrefix); ?>_client_name" class="form-control" type="text" name="<?php echo assessment_demographics_escape($namePrefix); ?>[client_name]" value="<?php echo assessment_demographics_escape($values['client_name']); ?>" <?php echo $clientRequired ? 'required' : ''; ?>>
                </div>
                <?php endif; ?>
                <?php if (!isset($hiddenFields['consultant'])): ?>
                <div class="assessment-demographics-field">
                    <label for="<?php echo assessment_demographics_escape($namePrefix); ?>_consultant">CONSULTANT</label>
                    <input id="<?php echo assessment_demographics_escape($namePrefix); ?>_consultant" class="form-control" type="text" name="<?php echo assessment_demographics_escape($namePrefix); ?>[consultant]" value="<?php echo assessment_demographics_escape($values['consultant']); ?>">
                </div>
                <?php endif; ?>
                <?php if (!isset($hiddenFields['contact_no'])): ?>
                <div class="assessment-demographics-field">
                    <label for="<?php echo assessment_demographics_escape($namePrefix); ?>_contact_no">CONTACT NO.</label>
                    <input id="<?php echo assessment_demographics_escape($namePrefix); ?>_contact_no" class="form-control" type="tel" name="<?php echo assessment_demographics_escape($namePrefix); ?>[contact_no]" value="<?php echo assessment_demographics_escape($values['contact_no']); ?>">
                </div>
                <?php endif; ?>
                <?php if (!isset($hiddenFields['email_id'])): ?>
                <div class="assessment-demographics-field">
                    <label for="<?php echo assessment_demographics_escape($namePrefix); ?>_email_id">EMAIL ID</label>
                    <input id="<?php echo assessment_demographics_escape($namePrefix); ?>_email_id" class="form-control" type="email" name="<?php echo assessment_demographics_escape($namePrefix); ?>[email_id]" value="<?php echo assessment_demographics_escape($values['email_id']); ?>">
                </div>
                <?php endif; ?>
                <?php if (!isset($hiddenFields['caregiver_name'])): ?>
                <div class="assessment-demographics-field">
                    <label for="<?php echo assessment_demographics_escape($namePrefix); ?>_caregiver_name">CAREGIVER NAME</label>
                    <input id="<?php echo assessment_demographics_escape($namePrefix); ?>_caregiver_name" class="form-control" type="text" name="<?php echo assessment_demographics_escape($namePrefix); ?>[caregiver_name]" value="<?php echo assessment_demographics_escape($values['caregiver_name']); ?>">
                </div>
                <?php endif; ?>
                <?php if (!isset($hiddenFields['filled_by'])): ?>
                <div class="assessment-demographics-field">
                    <label for="<?php echo assessment_demographics_escape($namePrefix); ?>_filled_by">FILLED BY</label>
                    <input id="<?php echo assessment_demographics_escape($namePrefix); ?>_filled_by" class="form-control" type="text" name="<?php echo assessment_demographics_escape($namePrefix); ?>[filled_by]" value="<?php echo assessment_demographics_escape($values['filled_by']); ?>">
                </div>
                <?php endif; ?>
            </div>
        </section>
        <style>
            .assessment-demographics-card { margin: 16px 0 20px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; overflow: hidden; }
            .assessment-demographics-heading { padding: 10px 14px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
            .assessment-demographics-heading h3 { margin: 0; font-size: 14px; font-weight: 800; color: #334155; }
            .assessment-demographics-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; padding: 14px; }
            .assessment-demographics-field label { display: block; margin-bottom: 5px; color: #475569; font-size: 12px; font-weight: 700; }
            .assessment-demographics-field .form-control { min-height: 38px; font-size: 13px; }
            @media (max-width: 900px) { .assessment-demographics-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
            @media (max-width: 560px) { .assessment-demographics-grid { grid-template-columns: 1fr; } }
        </style>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('assessment_demographics_ensure_column')) {
    function assessment_demographics_ensure_column($conn, $tableName, $afterColumn = 'clinical_notes')
    {
        $allowedTables = ['bprs_assessments', 'panss_assessments', 'ace_iii_assessments', 'assessment_responses'];
        if (!in_array($tableName, $allowedTables, true)) {
            return false;
        }
        $result = $conn->query("SHOW COLUMNS FROM `{$tableName}` LIKE 'demographic_data'");
        if ($result && $result->num_rows === 0) {
            $result->free();
            return (bool) $conn->query("ALTER TABLE `{$tableName}` ADD COLUMN demographic_data LONGTEXT NULL AFTER `{$afterColumn}`");
        }
        if ($result) {
            $result->free();
        }
        return true;
    }
}
