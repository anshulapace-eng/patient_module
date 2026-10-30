<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ob_start();

require 'config.php';
require 'ace-iii-assessment-schema.php';

if (!isset($_SESSION['user_login']) || (int) ($_SESSION['role'] ?? 0) !== 1) {
    header('Location: login.php');
    exit;
}

$page_title = "ACE-III Assessment - Indian English";
$sections = [
    'attention' => ['title' => 'Attention', 'max' => 18],
    'memory' => ['title' => 'Memory', 'max' => 26],
    'fluency' => ['title' => 'Fluency', 'max' => 14],
    'language' => ['title' => 'Language', 'max' => 26],
    'visuospatial' => ['title' => 'Visuospatial', 'max' => 16],
];
$score_fields = [
    'attention' => [
        'orientation_day' => 1,
        'orientation_date' => 1,
        'orientation_month' => 1,
        'orientation_year' => 1,
        'orientation_season' => 1,
        'orientation_floor' => 1,
        'orientation_street' => 1,
        'orientation_city' => 1,
        'orientation_state' => 1,
        'orientation_country' => 1,
        'registration' => 3,
        'subtraction' => 5,
    ],
    'memory' => [
        'word_recall' => 3,
        'address_learning' => 7,
        'address_recall' => 7,
        'address_recognition' => 5,
        'memory_chief_minister' => 1,
        'memory_prime_minister' => 1,
        'memory_actor' => 1,
        'memory_father_nation' => 1,
    ],
    'fluency' => ['letter_fluency' => 7, 'animal_fluency' => 7],
    'language' => [
        'command_1' => 1,
        'command_2' => 1,
        'command_3' => 1,
        'writing' => 2,
        'multisyllabic_repetition' => 2,
        'proverb_1' => 1,
        'proverb_2' => 1,
        'naming_1' => 1,
        'naming_2' => 1,
        'naming_3' => 1,
        'naming_4' => 1,
        'naming_5' => 1,
        'naming_6' => 1,
        'naming_7' => 1,
        'naming_8' => 1,
        'naming_9' => 1,
        'naming_10' => 1,
        'naming_11' => 1,
        'naming_12' => 1,
        'comprehension_1' => 1,
        'comprehension_2' => 1,
        'comprehension_3' => 1,
        'comprehension_4' => 1,
        'reading' => 1,
    ],
    'visuospatial' => [
        'infinity' => 1,
        'cube' => 2,
        'clock' => 5,
        'dots_1' => 1,
        'dots_2' => 1,
        'dots_3' => 1,
        'dots_4' => 1,
        'letters_1' => 1,
        'letters_2' => 1,
        'letters_3' => 1,
        'letters_4' => 1,
    ],
];

$patients = [];
$error_msg = '';
$success_msg = '';
$submitted_scores = (isset($_POST['scores']) && is_array($_POST['scores'])) ? $_POST['scores'] : [];
$raw_patient_id = isset($_POST['patient_id']) ? $_POST['patient_id'] : '';
$patient_id = filter_var($raw_patient_id, FILTER_VALIDATE_INT);
$patient_id = ($patient_id === false) ? 0 : (int) $patient_id;
$date_input = isset($_POST['assessment_date']) ? $_POST['assessment_date'] : date('Y-m-d');
$form_date = is_string($date_input) ? $date_input : date('Y-m-d');
$details = (isset($_POST['patient_details']) && is_array($_POST['patient_details'])) ? $_POST['patient_details'] : [];
$answers = (isset($_POST['answers']) && is_array($_POST['answers'])) ? $_POST['answers'] : [];
$notes = (isset($_POST['clinical_notes']) && is_string($_POST['clinical_notes'])) ? trim($_POST['clinical_notes']) : '';

if (!isset($_SESSION['ace_iii_csrf_token'])) {
    $_SESSION['ace_iii_csrf_token'] = bin2hex(random_bytes(32));
}

if (!$ace_iii_schema_ready) {
    $error_msg = 'The assessment table could not be initialized. Please contact the system administrator.';
} else {
    $patients_result = $conn->query(
        'SELECT id, first_name, last_name, email
         FROM users
         WHERE role_id = 2 AND patient_id = ' . PATIENT_SCOPE_ID . '
         ORDER BY first_name, last_name'
    );
    if ($patients_result) {
        while ($patient = $patients_result->fetch_assoc()) {
            $patients[] = $patient;
        }
    } else {
        error_log('ACE-III patient list query failed: ' . $conn->error);
        $error_msg = 'The patient list could not be loaded. Please contact the system administrator.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ace_iii']) && $ace_iii_schema_ready) {
    $posted_token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!is_string($posted_token) || !hash_equals($_SESSION['ace_iii_csrf_token'], $posted_token)) {
        $error_msg = 'Your form session expired. Reload the page and submit the assessment again.';
    } elseif ($patient_id < 1) {
        $error_msg = 'Please select a valid patient.';
    }

    if ($error_msg === '') {
        if (!is_string($date_input) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_input)) {
            $error_msg = 'Please enter a valid assessment date.';
        } else {
            [$year, $month, $day] = array_map('intval', explode('-', $date_input));
            if (!checkdate($month, $day, $year)) {
                $error_msg = 'Please enter a valid assessment date.';
            }
        }
    }
    if ($error_msg === '' && strlen($notes) > 5000) {
        $error_msg = 'Clinical notes must be 5,000 characters or fewer.';
    }

    $scores = [];
    $section_totals = [];
    foreach ($score_fields as $section => $fields) {
        $section_totals[$section] = 0;
        foreach ($fields as $field => $maximum) {
            $raw_score = isset($submitted_scores[$field]) ? $submitted_scores[$field] : null;
            $score = (is_string($raw_score) || is_int($raw_score))
                ? filter_var($raw_score, FILTER_VALIDATE_INT)
                : false;
            if ($score === false || $score < 0 || $score > $maximum) {
                $error_msg = 'Enter a valid score for every item (within its displayed range).';
                continue;
            }
            $scores[$field] = $score;
            $section_totals[$section] += $score;
        }
    }
    if (count($scores) !== array_sum(array_map('count', $score_fields)) && $error_msg === '') {
        $error_msg = 'Please complete every score field before saving.';
    }

    foreach ([$details, $answers] as $posted_data) {
        foreach ($posted_data as $key => $value) {
            $max_length = strpos((string) $key, 'drawing_') === 0 ? 500000 : 5000;
            $is_drawing = strpos((string) $key, 'drawing_') === 0;
            if (
                !is_string($value) || strlen($value) > $max_length ||
                ($is_drawing && $value !== '' && !preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $value))
            ) {
                $error_msg = 'Please enter valid responses (up to 5,000 characters each).';
                break 2;
            }
        }
    }

    if ($error_msg === '') {
        $patient_stmt = $conn->prepare(
            'SELECT id FROM users WHERE id = ? AND role_id = 2 AND patient_id = ' . PATIENT_SCOPE_ID . ' LIMIT 1'
        );
        if (!$patient_stmt) {
            error_log('ACE-III patient validation prepare failed: ' . $conn->error);
            $error_msg = 'The selected patient could not be validated. Please try again.';
        } else {
            $patient_stmt->bind_param('i', $patient_id);
            $patient_stmt->execute();
            $patient_stmt->store_result();
            $patient_exists = $patient_stmt->num_rows === 1;
            $patient_stmt->close();
            if (!$patient_exists) {
                $error_msg = 'The selected patient could not be found.';
            }
        }
    }

    if ($error_msg === '') {
        $total_score = array_sum($section_totals);
        $patient_details_json = json_encode($details, JSON_UNESCAPED_UNICODE);
        $response_data_json = json_encode(
            ['answers' => $answers, 'scores' => $scores],
            JSON_UNESCAPED_UNICODE
        );
        if ($patient_details_json === false || $response_data_json === false) {
            $error_msg = 'The assessment responses could not be prepared for saving.';
        } else {
            $clinician_id = (int) ($_SESSION['user_id'] ?? 0);
            $insert = $conn->prepare(
                'INSERT INTO ace_iii_assessments
                    (patient_id, clinician_id, assessment_date, total_score, attention_score,
                     memory_score, fluency_score, language_score, visuospatial_score,
                     patient_details, response_data, clinical_notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$insert) {
                error_log('ACE-III insert prepare failed: ' . $conn->error);
                $error_msg = 'The assessment could not be saved. Please try again.';
            } else {
                $insert->bind_param(
                    'iisiiiiiisss',
                    $patient_id,
                    $clinician_id,
                    $form_date,
                    $total_score,
                    $section_totals['attention'],
                    $section_totals['memory'],
                    $section_totals['fluency'],
                    $section_totals['language'],
                    $section_totals['visuospatial'],
                    $patient_details_json,
                    $response_data_json,
                    $notes
                );
                if ($insert->execute()) {
                    $insert->close();
                    $_SESSION['ace_iii_csrf_token'] = bin2hex(random_bytes(32));
                    $_SESSION['ace_iii_saved'] = 'ACE-III assessment saved successfully.';
                    header('Location: ace-iii-assessment-records.php');
                    exit;
                }
                error_log('ACE-III insert failed: ' . $insert->error);
                $insert->close();
                $error_msg = 'The assessment could not be saved. Please try again.';
            }
        }
    }
}

if (isset($_SESSION['ace_iii_saved'])) {
    $success_msg = (string) $_SESSION['ace_iii_saved'];
    unset($_SESSION['ace_iii_saved']);
}

function ace_iii_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ace_iii_score_input($field, $maximum, $submitted_scores, $domain_label = 'Score')
{
    $value = isset($submitted_scores[$field]) ? $submitted_scores[$field] : '';
    return '<div class="ace-score-box">' .
        '<label class="ace-score-label" for="score_' . ace_iii_escape($field) . '">' .
        '<span class="ace-score-title">' . ace_iii_escape($domain_label) . '</span>' .
        '<span class="ace-score-range">[Score 0–' . (int) $maximum . ']</span>' .
        '</label>' .
        '<input class="form-control ace-score" type="number" id="score_' . ace_iii_escape($field) . '" ' .
        'name="scores[' . ace_iii_escape($field) . ']" min="0" max="' . (int) $maximum . '" step="1" ' .
        'value="' . ace_iii_escape($value) . '" placeholder="0" required ' .
        'aria-label="Score for ' . ace_iii_escape(str_replace('_', ' ', $field)) . '">' .
        '</div>';
}

function ace_iii_get_picture_svg($index)
{
    // Agar aapke paas real image file (assets/ace-iii/pic_1.png ... pic_12.png) ho, to wo automatically dikhegi:
    foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
        $relative_path = 'assets/ace-iii/pic_' . (int) $index . '.' . $ext;
        if (file_exists(__DIR__ . '/' . $relative_path)) {
            return '<img src="' . ace_iii_escape($relative_path) . '" alt="ACE-III Picture ' . (int) $index . '" class="ace-real-img">';
        }
    }

    // High-Detail Realistic Clinical Illustrations (Exact ACE-III Indian English Paper Sheet Match)
    $svgs = [
        // 1. Thick Hardcover Book with Pages & Spine
        1 => '<svg viewBox="0 0 160 120" class="ace-detailed-svg">
            <path d="M38 22 L114 15 L122 21 L48 28 Z" fill="#f1f5f9"/>
            <path d="M38 22 L114 15 M42 25 L118 18 M48 28 L122 21" stroke-width="1.3"/>
            <path d="M38 22 C33 24 33 28 38 30 L38 98 C33 96 33 92 38 90" fill="#e2e8f0"/>
            <polygon points="48,28 122,21 122,92 48,100" fill="#ffffff"/>
            <path d="M38 22 L38 94 C38 98 43 100 48 100 L48 28 C43 28 38 25 38 22 Z" fill="#cbd5e1"/>
            <line x1="56" y1="27" x2="56" y2="99" stroke-width="1.5"/>
            <line x1="114" y1="15" x2="114" y2="21"/>
        </svg>',

        // 2. Realistic Metallic Spoon (3D Bowl & Contoured Handle)
        2 => '<svg viewBox="0 0 160 120" class="ace-detailed-svg">
            <path d="M102 22 C118 14 136 18 134 30 C132 42 112 48 96 42 C90 40 86 38 80 42 L32 82 C26 87 20 82 25 76 L75 36 C82 31 92 27 102 22 Z" fill="#f8fafc"/>
            <ellipse cx="114" cy="31" rx="16" ry="9" transform="rotate(-22 114 31)" stroke-width="1.2" fill="#e2e8f0"/>
            <path d="M98 28 C108 22 122 22 126 27" stroke-width="1" opacity="0.6"/>
        </svg>',

        // 3. Detailed Indian Goat (Horns, Drooping Ears, Beard, Hooves)
        3 => '<svg viewBox="0 0 160 120" class="ace-detailed-svg">
            <path d="M38 56 C52 52 78 52 94 55 L108 32 L118 32 L126 42 L116 46 L112 44 L104 62 C106 70 102 76 96 78 L98 102 L93 102 L90 78 C76 80 58 78 48 75 L42 102 L37 102 L41 74 L33 100 L28 100 L34 68 C32 62 34 58 38 56 Z" fill="#ffffff"/>
            <path d="M96 78 L103 101 L98 102" fill="none"/>
            <path d="M38 56 C32 50 28 46 26 42 C32 44 36 48 40 55" fill="#e2e8f0"/>
            <path d="M108 32 C102 22 92 20 86 24 C94 23 100 26 105 34" fill="#cbd5e1"/>
            <path d="M111 32 C106 23 98 21 92 24" fill="none"/>
            <path d="M110 38 C108 46 110 52 113 54 C115 50 114 43 112 38" fill="#e2e8f0"/>
            <path d="M120 45 L122 53 L118 51" fill="none"/>
            <circle cx="116" cy="37" r="1.5" fill="#0f172a"/>
        </svg>',

        // 4. Lit Wax Candle with Melted Rim & Layered Flame
        4 => '<svg viewBox="0 0 160 120" class="ace-detailed-svg">
            <path d="M62 46 L62 100 C62 104 98 104 98 100 L98 46" fill="#ffffff"/>
            <ellipse cx="80" cy="46" rx="18" ry="5" fill="#f1f5f9"/>
            <path d="M62 46 C65 54 70 52 72 49 C75 56 81 56 83 50 C86 54 94 53 98 46" fill="#e2e8f0"/>
            <line x1="80" y1="45" x2="80" y2="38" stroke-width="2.2"/>
            <path d="M80 12 C71 23 70 34 80 38 C90 34 89 23 80 12 Z" fill="#fef08a"/>
            <path d="M80 20 C76 26 76 33 80 36 C84 33 84 26 80 20 Z" fill="#fde047" stroke-width="1"/>
        </svg>',

        // 5. Indian Flag on Pole with 24-Spoke Ashoka Chakra & Waving Fold
        5 => '<svg viewBox="0 0 160 120" class="ace-detailed-svg">
            <rect x="34" y="14" width="4" height="94" rx="1.5" fill="#cbd5e1"/>
            <circle cx="36" cy="13" r="3" fill="#94a3b8"/>
            <path d="M38 20 Q68 17 96 22 L96 68 Q68 63 38 66 Z" fill="#ffffff"/>
            <path d="M38 35 Q68 32 96 37 M38 51 Q68 48 96 53" stroke-width="1.6"/>
            <path d="M96 24 L118 27 L118 72 L96 68" fill="#f8fafc"/>
            <path d="M96 39 L118 42 M96 54 L118 57" stroke-width="1.4"/>
            <circle cx="67" cy="43" r="6.5" stroke-width="1.4"/>
            <circle cx="67" cy="43" r="1.5" fill="#0f172a"/>
            <line x1="67" y1="36.5" x2="67" y2="49.5" stroke-width="1"/>
            <line x1="60.5" y1="43" x2="73.5" y2="43" stroke-width="1"/>
            <line x1="62.5" y1="38.5" x2="71.5" y2="47.5" stroke-width="1"/>
            <line x1="71.5" y1="38.5" x2="62.5" y2="47.5" stroke-width="1"/>
        </svg>',

        // 6. Realistic Dromedary Camel (Hump, Long Curved Neck, Tail, Jointed Legs)
        6 => '<svg viewBox="0 0 160 120" class="ace-detailed-svg">
            <path d="M38 58 C42 30 68 28 78 48 C84 54 90 54 96 42 C100 32 102 22 108 20 L120 20 C123 22 122 26 116 27 L110 28 C106 38 104 52 98 62 C96 68 94 72 90 74 L90 102 L85 102 L84 76 L78 102 L73 102 L76 74 C64 76 54 74 48 72 L44 102 L39 102 L42 74 L34 102 L29 102 L36 68 C35 64 36 60 38 58 Z" fill="#ffffff"/>
            <path d="M38 58 L30 74 L33 76 L37 64" fill="#e2e8f0"/>
            <circle cx="113" cy="23" r="1.4" fill="#0f172a"/>
            <path d="M107 20 L105 16 L109 18" fill="none"/>
        </svg>',

        // 7. Curved Agricultural Sickle (Hasia) with Wooden Handle & Ferrule
        7 => '<svg viewBox="0 0 160 120" class="ace-detailed-svg">
            <path d="M26 88 L56 66 C60 63 64 67 60 71 L32 95 C27 98 22 92 26 88 Z" fill="#e2e8f0"/>
            <path d="M55 65 L62 60 L66 65 L59 70 Z" fill="#94a3b8"/>
            <path d="M64 61 C82 48 118 48 114 22 C112 12 100 10 92 14 C104 16 106 26 98 36 C90 46 74 48 61 59 Z" fill="#f8fafc"/>
        </svg>',

        // 8. Spotted Giraffe (Long Patterned Neck, Ossicones/Horns, Hooves)
        8 => '<svg viewBox="0 0 160 120" class="ace-detailed-svg">
            <path d="M44 64 L72 54 L98 14 L108 14 L114 19 L106 22 L102 21 L84 58 C86 64 84 70 80 73 L82 104 L77 104 L75 74 L70 104 L65 104 L68 74 C58 75 52 74 48 72 L44 104 L39 104 L42 74 L35 104 L30 104 L38 68 C38 66 40 65 44 64 Z" fill="#ffffff"/>
            <line x1="101" y1="14" x2="100" y2="9"/><circle cx="100" cy="8" r="1.5" fill="#0f172a"/>
            <line x1="105" y1="14" x2="105" y2="9"/><circle cx="105" cy="8" r="1.5" fill="#0f172a"/>
            <path d="M38 68 L31 84" stroke-width="1.5"/>
            <!-- Giraffe Polygon Spots -->
            <polygon points="88,28 93,26 92,32 87,33" fill="#94a3b8" stroke="none"/>
            <polygon points="82,38 87,36 86,43 80,42" fill="#94a3b8" stroke="none"/>
            <polygon points="75,49 81,48 80,55 74,54" fill="#94a3b8" stroke="none"/>
            <polygon points="62,59 69,57 70,64 62,65" fill="#94a3b8" stroke="none"/>
            <polygon points="72,62 79,60 79,68 72,68" fill="#94a3b8" stroke="none"/>
            <polygon points="49,64 57,62 57,69 49,69" fill="#94a3b8" stroke="none"/>
            <polygon points="58,68 65,67 64,72 57,72" fill="#94a3b8" stroke="none"/>
        </svg>',

        // 9. Traditional Kettle Drum (Nagada) with Zigzag Lacing & 2 Drumsticks
        9 => '<svg viewBox="0 0 160 120" class="ace-detailed-svg">
            <path d="M36 48 C38 86 58 98 80 98 C102 98 122 86 124 48" fill="#f8fafc"/>
            <ellipse cx="80" cy="48" rx="44" ry="12" fill="#ffffff"/>
            <ellipse cx="80" cy="48" rx="37" ry="9" stroke-width="1.2" fill="#f1f5f9"/>
            <path d="M38 53 L48 84 L58 57 L68 93 L80 59 L92 93 L102 57 L112 84 L122 53" stroke-width="1.6"/>
            <path d="M43 74 Q80 84 117 74" stroke-width="1.5"/>
            <!-- Drumsticks -->
            <line x1="62" y1="36" x2="106" y2="20" stroke-width="2.8"/>
            <circle cx="61" cy="36" r="3" fill="#0f172a"/>
            <line x1="72" y1="43" x2="116" y2="27" stroke-width="2.8"/>
            <circle cx="71" cy="43" r="3" fill="#0f172a"/>
        </svg>',

        // 10. Open Ribbed Umbrella with Curved J-Handle
        10 => '<svg viewBox="0 0 160 120" class="ace-detailed-svg">
            <path d="M26 58 C32 20 96 12 118 46 Z" fill="#f8fafc"/>
            <path d="M26 58 Q41 50 56 54 Q72 46 88 51 Q103 43 118 46" fill="#ffffff"/>
            <path d="M68 23 Q52 36 56 54 M68 23 Q80 34 88 51 M68 23 Q38 36 26 58 M68 23 Q98 32 118 46" stroke-width="1.3"/>
            <line x1="66" y1="16" x2="68" y2="23" stroke-width="2.5"/>
            <path d="M72 49 L92 92 C95 99 85 104 81 97 L79 93" stroke-width="2.6"/>
        </svg>',

        // 11. Realistic Pig (Snout, Floppy Ears, Curly Tail, Trotters)
        11 => '<svg viewBox="0 0 160 120" class="ace-detailed-svg">
            <path d="M44 52 C54 36 94 34 114 46 C124 52 124 68 112 74 L110 96 L104 96 L104 76 C92 80 72 80 60 76 L56 96 L50 96 L52 74 L44 94 L38 94 L44 70 C36 68 32 64 26 64 L24 56 L34 54 C36 50 40 48 44 52 Z" fill="#ffffff"/>
            <ellipse cx="25" cy="60" rx="2.5" ry="4" fill="#e2e8f0"/>
            <path d="M48 48 C42 42 36 44 40 52" fill="#e2e8f0"/>
            <circle cx="38" cy="55" r="1.6" fill="#0f172a"/>
            <path d="M121 54 C130 48 134 56 126 58 C132 60 132 66 125 65" fill="none" stroke-width="1.8"/>
            <line x1="96" y1="78" x2="94" y2="94" stroke-width="2"/>
        </svg>',

        // 12. Textured Crocodile with Open Toothed Jaws, Ridged Back & Scaled Tail
        12 => '<svg viewBox="0 0 160 120" class="ace-detailed-svg">
            <path d="M12 66 C22 76 42 74 62 66 L112 62 L146 52 L124 63 L146 65 L114 71 L64 76 C40 82 20 80 12 66 Z" fill="#f8fafc"/>
            <!-- Teeth -->
            <polyline points="126,58 128,60 131,57 133,59 136,56 139,58 142,55" stroke-width="1.1"/>
            <polyline points="126,65 129,63 132,65 135,63 138,65 141,63" stroke-width="1.1"/>
            <!-- Legs -->
            <path d="M68 75 L62 88 L70 88 L74 75" fill="#e2e8f0"/>
            <path d="M102 71 L98 86 L106 86 L108 71" fill="#e2e8f0"/>
            <!-- Dorsal Ridges & Scales -->
            <path d="M24 69 L28 65 L32 68 L36 64 L40 67 L45 63 L50 66 L55 62 L60 66" stroke-width="1.3"/>
            <line x1="65" y1="68" x2="108" y2="65" stroke-dasharray="4,3" stroke-width="1.3"/>
            <circle cx="116" cy="59" r="1.5" fill="#0f172a"/>
        </svg>',
    ];
    return isset($svgs[$index]) ? $svgs[$index] : '';
}

function ace_iii_get_fragmented_letter_svg($index)
{
    $letters = [
        // 1. Fragmented K
        1 => '<svg viewBox="0 0 120 120" class="ace-frag-svg"><g fill="#0f172a">
            <rect x="28" y="18" width="8" height="14"/><rect x="28" y="42" width="8" height="16"/><rect x="28" y="68" width="8" height="12"/><rect x="28" y="90" width="8" height="12"/>
            <rect x="78" y="20" width="9" height="9" transform="rotate(25 78 20)"/><rect x="62" y="36" width="10" height="8" transform="rotate(-35 62 36)"/><rect x="46" y="50" width="9" height="8"/>
            <rect x="54" y="64" width="10" height="8" transform="rotate(35 54 64)"/><rect x="68" y="78" width="10" height="8" transform="rotate(35 68 78)"/><rect x="82" y="92" width="10" height="9"/>
        </g></svg>',
        // 2. Fragmented M
        2 => '<svg viewBox="0 0 120 120" class="ace-frag-svg"><g fill="#0f172a">
            <rect x="20" y="20" width="8" height="14"/><rect x="20" y="46" width="8" height="14"/><rect x="20" y="72" width="8" height="12"/><rect x="20" y="92" width="8" height="10"/>
            <rect x="32" y="28" width="8" height="12" transform="rotate(-28 32 28)"/><rect x="44" y="48" width="8" height="12" transform="rotate(-25 44 48)"/><rect x="56" y="66" width="8" height="10"/>
            <rect x="68" y="48" width="8" height="12" transform="rotate(25 68 48)"/><rect x="78" y="28" width="8" height="12" transform="rotate(28 78 28)"/>
            <rect x="92" y="20" width="8" height="14"/><rect x="92" y="48" width="8" height="14"/><rect x="92" y="74" width="8" height="12"/><rect x="92" y="92" width="8" height="10"/>
        </g></svg>',
        // 3. Fragmented A
        3 => '<svg viewBox="0 0 120 120" class="ace-frag-svg"><g fill="#0f172a">
            <rect x="56" y="18" width="9" height="10"/><rect x="46" y="36" width="8" height="14" transform="rotate(20 46 36)"/><rect x="36" y="60" width="8" height="14" transform="rotate(20 36 60)"/><rect x="26" y="86" width="8" height="14" transform="rotate(20 26 86)"/>
            <rect x="68" y="36" width="8" height="14" transform="rotate(-20 68 36)"/><rect x="78" y="60" width="8" height="14" transform="rotate(-20 78 60)"/><rect x="88" y="86" width="8" height="14" transform="rotate(-20 88 86)"/>
            <rect x="44" y="66" width="12" height="7"/><rect x="64" y="66" width="12" height="7"/>
        </g></svg>',
        // 4. Fragmented T
        4 => '<svg viewBox="0 0 120 120" class="ace-frag-svg"><g fill="#0f172a">
            <rect x="22" y="20" width="14" height="8"/><rect x="46" y="20" width="12" height="8"/><rect x="68" y="20" width="12" height="8"/><rect x="88" y="20" width="12" height="8"/>
            <rect x="56" y="34" width="8" height="14"/><rect x="56" y="58" width="8" height="14"/><rect x="56" y="80" width="8" height="10"/><rect x="56" y="96" width="8" height="8"/>
        </g></svg>',
    ];
    return isset($letters[$index]) ? $letters[$index] : '';
}

require 'layout/base.php';
?>

<style>
    :root {
        --ace-border: #e2e8f0;
        --ace-soft-border: #f1f5f9;
        --ace-bg-head: #f8fafc;
        --ace-primary: #0f766e;
        --ace-primary-soft: #f0fdfa;
        --ace-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06), 0 2px 6px -1px rgba(15, 23, 42, 0.04);
        --ace-shadow-sm: 0 2px 8px rgba(15, 23, 42, 0.04);
    }

    .ace-page {
        max-width: 1120px;
        margin: 0 auto;
        padding: 20px 14px 40px;
        color: #1e293b;
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }

    /* Soft Shadow Header Banner */
    .ace-sheet-banner {
        background: #fff;
        border: 1px solid var(--ace-border);
        border-top: 4px solid var(--ace-primary);
        border-radius: 14px;
        padding: 20px 24px;
        margin-bottom: 22px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        box-shadow: var(--ace-shadow);
    }
    .ace-sheet-banner h1 {
        margin: 0;
        font-size: 20px;
        font-weight: 800;
        letter-spacing: 0.2px;
        color: #0f172a;
        text-transform: uppercase;
    }
    .ace-sheet-banner h2 {
        margin: 4px 0 0;
        font-size: 14px;
        font-weight: 600;
        color: #64748b;
    }
    .ace-nav a {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 16px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        background: #fff;
        color: #334155;
        font-size: 12.5px;
        font-weight: 700;
        text-decoration: none;
        box-shadow: var(--ace-shadow-sm);
        transition: all 0.15s ease;
    }
    .ace-nav a:hover {
        background: var(--ace-primary);
        border-color: var(--ace-primary);
        color: #fff;
    }

    /* Section Card with Soft Shadow Instead of Dark Borders */
    .ace-paper-box {
        background: #fff;
        border: 1px solid rgba(226, 232, 240, 0.85);
        border-radius: 14px;
        margin-bottom: 24px;
        box-shadow: var(--ace-shadow);
        overflow: hidden;
    }
    .ace-domain-bar {
        background: linear-gradient(to right, #f8fafc, #f1f5f9);
        border-bottom: 1px solid var(--ace-border);
        border-left: 4px solid var(--ace-primary);
        padding: 11px 18px;
        font-size: 13px;
        font-weight: 800;
        letter-spacing: 0.5px;
        color: #0f172a;
        text-transform: uppercase;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .ace-domain-bar span.ace-live-pill {
        background: var(--ace-primary-soft);
        color: var(--ace-primary);
        border: 1px solid #99f6e4;
        padding: 4px 12px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 700;
    }

    /* Demographics 2-Column Grid */
    .ace-demo-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }
    .ace-demo-col {
        padding: 18px 20px;
    }
    .ace-demo-col:first-child {
        border-right: 1px solid var(--ace-soft-border);
    }
    .ace-demo-row {
        display: grid;
        grid-template-columns: 190px 1fr;
        align-items: center;
        gap: 10px;
        margin-bottom: 12px;
    }
    .ace-demo-row:last-child {
        margin-bottom: 0;
    }
    .ace-demo-row label {
        font-size: 12.5px;
        font-weight: 600;
        color: #334155;
        margin: 0;
    }

    /* Split Task Row: Soft Divider + Shadow Score Area */
    .ace-split-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 160px;
        border-bottom: 1px solid var(--ace-soft-border);
    }
    .ace-split-row:last-child {
        border-bottom: none;
    }
    .ace-split-left {
        padding: 18px 20px;
        border-right: 1px solid var(--ace-soft-border);
    }
    .ace-split-right {
        padding: 16px 12px;
        background: #fafaf9;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: flex-start;
        gap: 10px;
    }

    /* Score Box Styling (Elevated Pill Card) */
    .ace-score-box {
        width: 100%;
        text-align: center;
        background: #fff;
        border: 1px solid var(--ace-border);
        border-radius: 10px;
        padding: 10px 8px;
        box-shadow: var(--ace-shadow-sm);
    }
    .ace-score-label {
        display: block;
        margin-bottom: 6px;
        line-height: 1.25;
        cursor: pointer;
    }
    .ace-score-title {
        display: block;
        font-size: 11px;
        font-weight: 800;
        color: var(--ace-primary);
        text-transform: uppercase;
    }
    .ace-score-range {
        display: block;
        font-size: 11px;
        font-weight: 500;
        color: #64748b;
    }
    .ace-score {
        width: 72px !important;
        height: 36px !important;
        margin: 0 auto;
        text-align: center;
        font-weight: 800 !important;
        font-size: 15px !important;
        border: 1px solid #cbd5e1 !important;
        border-radius: 7px !important;
        background: #f8fafc !important;
        transition: all 0.15s ease;
    }
    .ace-score:focus {
        background: #fff !important;
        border-color: var(--ace-primary) !important;
        box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.14) !important;
    }

    /* Bullet Prompts */
    .ace-bullet-list {
        margin: 0 0 12px;
        padding-left: 18px;
        font-size: 13px;
        line-height: 1.55;
        color: #334155;
    }
    .ace-bullet-list li {
        margin-bottom: 5px;
    }

    /* Orientation 5-Box Row Table */
    .ace-orient-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        margin-top: 8px;
        border: 1px solid var(--ace-border);
        border-radius: 10px;
        overflow: hidden;
        box-shadow: var(--ace-shadow-sm);
    }
    .ace-orient-table th,
    .ace-orient-table td {
        border-right: 1px solid var(--ace-border);
        border-bottom: 1px solid var(--ace-border);
        padding: 10px;
        vertical-align: top;
    }
    .ace-orient-table th:last-child,
    .ace-orient-table td:last-child {
        border-right: none;
    }
    .ace-orient-table tbody tr:last-child td {
        border-bottom: none;
    }
    .ace-orient-table th {
        background: #f8fafc;
        font-size: 12px;
        font-weight: 700;
        color: #475569;
    }
    .ace-inline-score {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 6px;
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px dashed #e2e8f0;
        font-size: 11px;
        font-weight: 600;
        color: #64748b;
    }
    .ace-inline-score input {
        width: 54px !important;
        height: 30px !important;
        padding: 2px 4px !important;
        text-align: center;
        font-weight: 800;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
    }

    /* Fluency Scaling Mini Table */
    .ace-scale-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 11px;
        margin-top: 6px;
        background: #fff;
        border-radius: 6px;
        overflow: hidden;
        box-shadow: var(--ace-shadow-sm);
    }
    .ace-scale-table td, .ace-scale-table th {
        border: 1px solid var(--ace-border);
        padding: 3px 6px;
        text-align: center;
        color: #475569;
    }

    /* Address Learning 4-Column Table */
    .ace-address-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        margin-top: 10px;
        border: 1px solid var(--ace-border);
        border-radius: 10px;
        overflow: hidden;
        box-shadow: var(--ace-shadow-sm);
    }
    .ace-address-table th, .ace-address-table td {
        border-right: 1px solid var(--ace-border);
        border-bottom: 1px solid var(--ace-border);
        padding: 10px 12px;
        vertical-align: top;
        font-size: 13px;
    }
    .ace-address-table th:last-child, .ace-address-table td:last-child {
        border-right: none;
    }
    .ace-address-table tbody tr:last-child td {
        border-bottom: none;
    }
    .ace-address-table th {
        background: #f8fafc;
        font-weight: 700;
        color: #334155;
    }

    /* Picture Naming 3x4 Grid */
    .ace-pic-grid-12 {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
        margin-top: 12px;
    }
    .ace-pic-cell {
        border: 1px solid var(--ace-border);
        border-radius: 10px;
        padding: 12px;
        background: #fff;
        box-shadow: var(--ace-shadow-sm);
        display: flex;
        flex-direction: column;
        gap: 10px;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .ace-pic-cell:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 14px rgba(15, 23, 42, 0.06);
    }
    .ace-pic-top-bar {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .ace-pic-svg-wrap {
        height: 92px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f8fafc;
        border: 1px solid var(--ace-soft-border);
        border-radius: 8px;
    }
    .ace-pic-svg-wrap svg {
        width: 110px;
        height: 82px;
        fill: none;
        stroke: #1e293b;
        stroke-width: 2.2;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    /* Visuospatial Models & Canvases */
    .ace-visuo-grid {
        display: grid;
        grid-template-columns: 270px minmax(0, 1fr);
        gap: 16px;
        align-items: stretch;
        margin-top: 10px;
    }
    .ace-stimulus-card {
        border: 1px solid var(--ace-border);
        border-radius: 10px;
        background: #f8fafc;
        padding: 14px;
        box-shadow: var(--ace-shadow-sm);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
    }
    .ace-stimulus-card span {
        font-size: 11px;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        margin-bottom: 8px;
    }
    .ace-model-svg {
        width: 100%;
        max-width: 230px;
        height: 120px;
    }
    .ace-model-svg path, .ace-model-svg line, .ace-model-svg circle, .ace-model-svg rect {
        fill: none;
        stroke: #1e293b;
        stroke-width: 3;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    .ace-canvas-card {
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        overflow: hidden;
        background: #fff;
        box-shadow: var(--ace-shadow-sm);
    }
    .ace-canvas-bar {
        background: #f8fafc;
        border-bottom: 1px solid var(--ace-border);
        padding: 7px 14px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 12px;
        font-weight: 600;
        color: #475569;
    }
    .ace-canvas {
        display: block;
        width: 100%;
        height: 190px;
        cursor: crosshair;
        touch-action: none;
        background: #fff;
    }

    /* 2x2 Dot Counting & Fragmented Letters Grid */
    .ace-quad-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
        margin-top: 10px;
    }
    .ace-quad-box {
        border: 1px solid var(--ace-border);
        border-radius: 10px;
        padding: 14px;
        background: #fff;
        box-shadow: var(--ace-shadow-sm);
    }
    .ace-dot-frame {
        height: 125px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        position: relative;
        background: #f8fafc;
        margin-bottom: 10px;
    }
    .ace-dot-frame i {
        position: absolute;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: #1e293b;
    }
    .ace-frag-frame {
        height: 135px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f8fafc;
        margin-bottom: 10px;
    }
    .ace-frag-svg {
        width: 115px;
        height: 115px;
    }

    /* Recognition Table (Page 6) */
    .ace-recog-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        margin-top: 10px;
        font-size: 12.5px;
        border: 1px solid var(--ace-border);
        border-radius: 10px;
        overflow: hidden;
        box-shadow: var(--ace-shadow-sm);
    }
    .ace-recog-table th, .ace-recog-table td {
        border-right: 1px solid var(--ace-border);
        border-bottom: 1px solid var(--ace-border);
        padding: 8px 12px;
    }
    .ace-recog-table td:last-child {
        border-right: none;
    }
    .ace-recog-table tbody tr:last-child td {
        border-bottom: none;
    }
    .ace-recog-table td.ace-recog-target {
        font-weight: 700;
        color: var(--ace-primary);
        background: var(--ace-primary-soft);
    }
    .ace-recog-table td.ace-recog-shaded {
        background: #f1f5f9;
        color: #475569;
        font-weight: 600;
        width: 130px;
    }

    /* Sticky Bottom Bar INSIDE Container */
    .ace-sticky-footer {
        position: sticky;
        bottom: 12px;
        z-index: 50;
        background: rgba(15, 23, 42, 0.95);
        backdrop-filter: blur(8px);
        color: #fff;
        border-radius: 12px;
        padding: 12px 20px;
        box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.25);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
        margin-top: 24px;
    }
    .ace-sticky-scores {
        display: flex;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
        font-size: 12.5px;
    }
    .ace-sticky-scores span {
        color: #94a3b8;
    }
    .ace-sticky-scores strong {
        color: #5eead4;
        font-weight: 800;
        margin-left: 3px;
    }
    .ace-total-pill {
        background: var(--ace-primary);
        color: #fff;
        padding: 6px 14px;
        border-radius: 8px;
        font-weight: 800;
        font-size: 14px;
    }

    .ace-toast {
        padding: 12px 16px;
        border-radius: 10px;
        margin-bottom: 16px;
        font-size: 13.5px;
        font-weight: 600;
        box-shadow: var(--ace-shadow-sm);
    }
    .ace-toast-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    .ace-toast-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }

    @media (max-width: 960px) {
        .ace-demo-grid { grid-template-columns: 1fr; }
        .ace-demo-col:first-child { border-right: none; border-bottom: 1px solid var(--ace-soft-border); }
        .ace-split-row { grid-template-columns: 1fr; }
        .ace-split-left { border-right: none; border-bottom: 1px solid var(--ace-soft-border); }
        .ace-pic-grid-12 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .ace-visuo-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
        .ace-demo-row { grid-template-columns: 1fr; gap: 4px; }
        .ace-pic-grid-12, .ace-quad-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="ace-page">
    <header class="ace-sheet-banner">
        <div>
            <h1>ADDENBROOKE'S COGNITIVE EXAMINATION - ACE-III</h1>
            <h2>Indian English</h2>
        </div>
        <nav class="ace-nav" aria-label="ACE-III navigation">
            <a href="ace-iii-assessment-records.php">View Saved Records</a>
        </nav>
    </header>

    <?php if ($error_msg !== ''): ?>
        <div class="ace-toast ace-toast-error" role="alert"><?php echo ace_iii_escape($error_msg); ?></div>
    <?php endif; ?>
    <?php if ($success_msg !== ''): ?>
        <div class="ace-toast ace-toast-success" role="status"><?php echo ace_iii_escape($success_msg); ?></div>
    <?php endif; ?>

    <form method="post" id="aceForm">
        <input type="hidden" name="csrf_token" value="<?php echo ace_iii_escape($_SESSION['ace_iii_csrf_token']); ?>">

        <!-- TOP DEMOGRAPHICS TABLE (PAGE 1 HEADER) -->
        <section class="ace-paper-box">
            <div class="ace-demo-grid">
                <div class="ace-demo-col">
                    <div class="ace-demo-row">
                        <label for="patient_id">Name (Patient): *</label>
                        <select class="form-select form-select-sm" id="patient_id" name="patient_id" required>
                            <option value="">-- Select Patient --</option>
                            <?php foreach ($patients as $patient): ?>
                                <option value="<?php echo (int) $patient['id']; ?>" <?php echo $patient_id === (int) $patient['id'] ? 'selected' : ''; ?>>
                                    <?php echo ace_iii_escape(trim($patient['first_name'] . ' ' . $patient['last_name']) ?: $patient['email']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ace-demo-row">
                        <label for="detail_date_of_birth">Date of Birth:</label>
                        <input class="form-control form-control-sm" id="detail_date_of_birth" name="patient_details[date_of_birth]" value="<?php echo ace_iii_escape($details['date_of_birth'] ?? ''); ?>">
                    </div>
                    <div class="ace-demo-row">
                        <label for="detail_hospital_address">Hospital No. or Address:</label>
                        <input class="form-control form-control-sm" id="detail_hospital_address" name="patient_details[hospital_address]" value="<?php echo ace_iii_escape($details['hospital_address'] ?? ''); ?>">
                    </div>
                    <div class="ace-demo-row">
                        <label for="detail_languages_known">Languages Known:</label>
                        <input class="form-control form-control-sm" id="detail_languages_known" name="patient_details[languages_known]" value="<?php echo ace_iii_escape($details['languages_known'] ?? ''); ?>">
                    </div>
                    <div class="ace-demo-row">
                        <label for="detail_native_language">Native Language:</label>
                        <input class="form-control form-control-sm" id="detail_native_language" name="patient_details[native_language]" value="<?php echo ace_iii_escape($details['native_language'] ?? ''); ?>">
                    </div>
                </div>
                <div class="ace-demo-col">
                    <div class="ace-demo-row">
                        <label for="assessment_date">Date of testing: *</label>
                        <input class="form-control form-control-sm" type="date" id="assessment_date" name="assessment_date" value="<?php echo ace_iii_escape($form_date); ?>" required>
                    </div>
                    <div class="ace-demo-row">
                        <label for="detail_tester_name">Tester's name:</label>
                        <input class="form-control form-control-sm" id="detail_tester_name" name="patient_details[tester_name]" value="<?php echo ace_iii_escape($details['tester_name'] ?? ''); ?>">
                    </div>
                    <div class="ace-demo-row">
                        <label for="detail_education_age">Age at leaving full-time education:</label>
                        <input class="form-control form-control-sm" id="detail_education_age" name="patient_details[education_age]" value="<?php echo ace_iii_escape($details['education_age'] ?? ''); ?>">
                    </div>
                    <div class="ace-demo-row">
                        <label for="detail_occupation">Occupation:</label>
                        <input class="form-control form-control-sm" id="detail_occupation" name="patient_details[occupation]" value="<?php echo ace_iii_escape($details['occupation'] ?? ''); ?>">
                    </div>
                    <div class="ace-demo-row">
                        <label for="detail_handedness">Handedness:</label>
                        <input class="form-control form-control-sm" id="detail_handedness" name="patient_details[handedness]" value="<?php echo ace_iii_escape($details['handedness'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </section>

        <!-- PAGE 1: ATTENTION -->
        <section class="ace-paper-box">
            <div class="ace-domain-bar">
                <span>ATTENTION</span>
                <span class="ace-live-pill">Subtotal: <strong id="badge_attention">0</strong> / 18</span>
            </div>

            <!-- Orientation Row 1 & 2 -->
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <div class="fw-bold mb-1" style="font-size:13px;">&#10148; Ask: What is the</div>
                    <div class="table-responsive">
                        <table class="ace-orient-table">
                            <thead>
                                <tr>
                                    <th>Day</th>
                                    <th>Date</th>
                                    <th>Month</th>
                                    <th>Year</th>
                                    <th>Season</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <?php foreach (['orientation_day' => 'Day', 'orientation_date' => 'Date', 'orientation_month' => 'Month', 'orientation_year' => 'Year', 'orientation_season' => 'Season'] as $field => $label): ?>
                                        <td>
                                            <input class="form-control form-control-sm" name="answers[<?php echo $field; ?>]" value="<?php echo ace_iii_escape($answers[$field] ?? ''); ?>" placeholder="<?php echo $label; ?>">
                                            <div class="ace-inline-score">
                                                <span>Score (0–1)</span>
                                                <input class="form-control ace-score" type="number" name="scores[<?php echo $field; ?>]" min="0" max="1" step="1" value="<?php echo ace_iii_escape($submitted_scores[$field] ?? ''); ?>" required>
                                            </div>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="fw-bold mt-3 mb-1" style="font-size:13px;">&#10148; Ask: Which</div>
                    <div class="table-responsive">
                        <table class="ace-orient-table">
                            <thead>
                                <tr>
                                    <th>No./Floor</th>
                                    <th>Street/Hospital</th>
                                    <th>City</th>
                                    <th>State</th>
                                    <th>Country</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <?php foreach (['orientation_floor' => 'No./Floor', 'orientation_street' => 'Street/Hospital', 'orientation_city' => 'City', 'orientation_state' => 'State', 'orientation_country' => 'Country'] as $field => $label): ?>
                                        <td>
                                            <input class="form-control form-control-sm" name="answers[<?php echo $field; ?>]" value="<?php echo ace_iii_escape($answers[$field] ?? ''); ?>" placeholder="<?php echo $label; ?>">
                                            <div class="ace-inline-score">
                                                <span>Score (0–1)</span>
                                                <input class="form-control ace-score" type="number" name="scores[<?php echo $field; ?>]" min="0" max="1" step="1" value="<?php echo ace_iii_escape($submitted_scores[$field] ?? ''); ?>" required>
                                            </div>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="ace-split-right">
                    <div class="ace-score-box">
                        <span class="ace-score-title">Attention</span>
                        <span class="ace-score-range">[Score 0–5]</span>
                        <div class="fw-bold mt-1 fs-5" id="orient_time_sub">0</div>
                    </div>
                    <div class="ace-score-box">
                        <span class="ace-score-title">Attention</span>
                        <span class="ace-score-range">[Score 0–5]</span>
                        <div class="fw-bold mt-1 fs-5" id="orient_place_sub">0</div>
                    </div>
                </div>
            </div>

            <!-- Word Registration -->
            <div class="ace-domain-bar"><span>ATTENTION</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <ul class="ace-bullet-list">
                        <li><strong>Tell:</strong> "I'm going to give you three words and I'd like you to repeat them after me: <strong>lemon, key and ball</strong>." After subject repeats, say "Try to remember them because I'm going to ask you later".</li>
                        <li>Score <u>only</u> the first trial (repeat 3 times if necessary).</li>
                        <li>Register number of trials:</li>
                    </ul>
                    <input class="form-control form-control-sm" name="answers[registration_trials]" value="<?php echo ace_iii_escape($answers['registration_trials'] ?? ''); ?>" placeholder="Record subject's response &amp; number of trials">
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('registration', 3, $submitted_scores, 'Attention'); ?>
                </div>
            </div>

            <!-- Serial 7 Subtraction -->
            <div class="ace-domain-bar"><span>ATTENTION</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <ul class="ace-bullet-list">
                        <li>Ask the subject: "Could you take 7 away from 100? I'd like you to keep taking 7 away from each new number until I tell you to stop."</li>
                        <li>If subject makes a mistake, do not stop them. Let the subject carry on and check subsequent answers (e.g., 93, 84, 77, 70, 63 - score 4).</li>
                        <li>Stop after five subtractions (93, 86, 79, 72, 65):</li>
                    </ul>
                    <div class="row g-2">
                        <?php $sub_hints = [93, 86, 79, 72, 65];
                        for ($i = 1; $i <= 5; $i++): ?>
                            <div class="col">
                                <input class="form-control form-control-sm text-center" name="answers[subtraction_<?php echo $i; ?>]" value="<?php echo ace_iii_escape($answers['subtraction_' . $i] ?? ''); ?>" placeholder="Step <?php echo $i; ?> (<?php echo $sub_hints[$i - 1]; ?>)">
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('subtraction', 5, $submitted_scores, 'Attention'); ?>
                </div>
            </div>
        </section>

        <!-- PAGE 1: MEMORY (3 WORDS RECALL) -->
        <section class="ace-paper-box">
            <div class="ace-domain-bar">
                <span>MEMORY</span>
                <span class="ace-live-pill">Memory Total: <strong id="badge_memory">0</strong> / 26</span>
            </div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Ask: 'Which 3 words did I ask you to repeat and remember?'</p>
                    <input class="form-control form-control-sm" name="answers[word_recall_response]" value="<?php echo ace_iii_escape($answers['word_recall_response'] ?? ''); ?>" placeholder="Record recalled words (lemon, key, ball)">
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('word_recall', 3, $submitted_scores, 'Memory'); ?>
                </div>
            </div>
        </section>

        <!-- PAGE 1: FLUENCY -->
        <section class="ace-paper-box">
            <div class="ace-domain-bar">
                <span>FLUENCY</span>
                <span class="ace-live-pill">Subtotal: <strong id="badge_fluency">0</strong> / 14</span>
            </div>

            <!-- Letters (P) -->
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <div class="fw-bold mb-1" style="font-size:13px;">&#10148; Letters</div>
                    <p class="mb-2" style="font-size:12.5px;"><strong>Say:</strong> "I'm going to give you a letter of the alphabet and I'd like you to generate as many words as you can beginning with that letter, but not names of people or places. For example, if I give you the letter "C", you could give me words like "cat, cry, clock" and so on. But, you can't give me words like Catherine or Canada. Do you understand? Are you ready? You have one minute. The letter I want you to use is the letter "P".</p>
                    <textarea class="form-control form-control-sm" rows="3" name="answers[letter_fluency_response]" placeholder="Record words generated across 0-15 sec, 16-30 sec, 31-45 sec, 46-60 sec..."><?php echo ace_iii_escape($answers['letter_fluency_response'] ?? ''); ?></textarea>
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('letter_fluency', 7, $submitted_scores, 'Fluency'); ?>
                    <table class="ace-scale-table">
                        <tr>
                            <td>&gt;14</td>
                            <td>7</td>
                        </tr>
                        <tr>
                            <td>11-14</td>
                            <td>6</td>
                        </tr>
                        <tr>
                            <td>8-10</td>
                            <td>5</td>
                        </tr>
                        <tr>
                            <td>6-7</td>
                            <td>4</td>
                        </tr>
                        <tr>
                            <td>3-5</td>
                            <td>3</td>
                        </tr>
                        <tr>
                            <td>2</td>
                            <td>2</td>
                        </tr>
                        <tr>
                            <td>1</td>
                            <td>1</td>
                        </tr>
                        <tr>
                            <td>0</td>
                            <td>0</td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Animals -->
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <div class="fw-bold mb-1" style="font-size:13px;">&#10148; Animals</div>
                    <p class="mb-2" style="font-size:12.5px;"><strong>Say:</strong> "Now can you name as many animals as possible. It can begin with any letter."</p>
                    <textarea class="form-control form-control-sm" rows="3" name="answers[animal_fluency_response]" placeholder="Record animals named across 0-15 sec, 16-30 sec, 31-45 sec, 46-60 sec..."><?php echo ace_iii_escape($answers['animal_fluency_response'] ?? ''); ?></textarea>
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('animal_fluency', 7, $submitted_scores, 'Fluency'); ?>
                    <table class="ace-scale-table">
                        <tr>
                            <td>&gt;16</td>
                            <td>7</td>
                        </tr>
                        <tr>
                            <td>14-16</td>
                            <td>6</td>
                        </tr>
                        <tr>
                            <td>11-13</td>
                            <td>5</td>
                        </tr>
                        <tr>
                            <td>8-10</td>
                            <td>4</td>
                        </tr>
                        <tr>
                            <td>6-7</td>
                            <td>3</td>
                        </tr>
                        <tr>
                            <td>3-5</td>
                            <td>2</td>
                        </tr>
                        <tr>
                            <td>1-2</td>
                            <td>1</td>
                        </tr>
                        <tr>
                            <td>0</td>
                            <td>0</td>
                        </tr>
                    </table>
                </div>
            </div>
        </section>

        <!-- PAGE 2: MEMORY (ADDRESS LEARNING & GENERAL MEMORY) -->
        <section class="ace-paper-box">
            <div class="ace-domain-bar"><span>MEMORY</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-1" style="font-size:12.5px;">&#10148; <strong>Tell:</strong> "I'm going to give you a name and address and I'd like you to repeat the name and address after me. So you have a chance to learn, we'll be doing that 3 times. I'll ask you the name and address later."</p>
                    <p class="mb-2 fw-bold" style="font-size:12.5px;">Score only the third trial.</p>
                    <div class="table-responsive">
                        <table class="ace-address-table">
                            <thead>
                                <tr>
                                    <th style="width:28%;">Target Name &amp; Address</th>
                                    <th>1<sup>st</sup> Trial</th>
                                    <th>2<sup>nd</sup> Trial</th>
                                    <th>3<sup>rd</sup> Trial</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="fw-bold">
                                        Sunil Kumar Singh<br>
                                        52, Station Road,<br>
                                        Gandhinagar,<br>
                                        Allahabad.
                                    </td>
                                    <?php for ($trial = 1; $trial <= 3; $trial++): ?>
                                        <td>
                                            <textarea class="form-control form-control-sm" rows="4" name="answers[address_trial_<?php echo $trial; ?>]" placeholder="Record Trial <?php echo $trial; ?>"><?php echo ace_iii_escape($answers['address_trial_' . $trial] ?? ''); ?></textarea>
                                        </td>
                                    <?php endfor; ?>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('address_learning', 7, $submitted_scores, 'Memory'); ?>
                </div>
            </div>

            <div class="ace-domain-bar"><span>MEMORY</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <?php
                    $memory_questions = [
                        'memory_chief_minister' => 'Name the current Chief Minister',
                        'memory_prime_minister' => 'Name the Prime Minister of India',
                        'memory_actor' => "Name the actor who was hero in the film 'Mera Naam Joker'",
                        'memory_father_nation' => 'Name the Father of our Nation',
                    ];
                    foreach ($memory_questions as $field => $question):
                    ?>
                        <div class="row align-items-center g-2 mb-2">
                            <div class="col-md-6">
                                <label class="fw-bold" style="font-size:13px;" for="<?php echo $field; ?>">&#10148; <?php echo ace_iii_escape($question); ?>:</label>
                            </div>
                            <div class="col-md-4">
                                <input class="form-control form-control-sm" id="<?php echo $field; ?>" name="answers[<?php echo $field; ?>]" value="<?php echo ace_iii_escape($answers[$field] ?? ''); ?>">
                            </div>
                            <div class="col-md-2">
                                <div class="ace-inline-score mt-0 pt-0 border-0">
                                    <span>0–1</span>
                                    <input class="form-control ace-score" type="number" name="scores[<?php echo $field; ?>]" min="0" max="1" step="1" value="<?php echo ace_iii_escape($submitted_scores[$field] ?? ''); ?>" required>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="ace-split-right">
                    <div class="ace-score-box">
                        <span class="ace-score-title">Memory</span>
                        <span class="ace-score-range">[Score 0–4]</span>
                        <div class="fw-bold mt-1 fs-5" id="retro_mem_sub">0</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- PAGE 2, 3, 4: LANGUAGE -->
        <section class="ace-paper-box">
            <div class="ace-domain-bar">
                <span>LANGUAGE</span>
                <span class="ace-live-pill">Subtotal: <strong id="badge_language">0</strong> / 26</span>
            </div>

            <!-- Commands -->
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2" style="font-size:12.5px;">&#10148; Place a pencil and a piece of paper in front of the subject. As a practice trial, ask the subject to <strong>"Pick up the pencil and then the paper."</strong> If incorrect, score 0 and do not continue further.</p>
                    <p class="mb-2" style="font-size:12.5px;">&#10148; If the subject is correct on the practice trial, continue with the following three commands below.</p>
                    <?php
                    $commands = [
                        'command_1' => 'Ask the subject to "Place the paper on top of the pencil"',
                        'command_2' => 'Ask the subject to "Pick up the pencil but not the paper"',
                        'command_3' => 'Ask the subject to "Pass me the pencil after touching the paper"',
                    ];
                    foreach ($commands as $field => $command):
                    ?>
                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                            <span style="font-size:13px;">&bull; <?php echo ace_iii_escape($command); ?></span>
                            <div class="ace-inline-score mt-0 pt-0 border-0">
                                <span>Score (0–1)</span>
                                <input class="form-control ace-score" type="number" name="scores[<?php echo $field; ?>]" min="0" max="1" step="1" value="<?php echo ace_iii_escape($submitted_scores[$field] ?? ''); ?>" required>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <p class="mt-2 mb-0 text-muted" style="font-size:12px;">Note: Place the pencil and paper in front of the subject before each command.</p>
                </div>
                <div class="ace-split-right">
                    <div class="ace-score-box">
                        <span class="ace-score-title">Language</span>
                        <span class="ace-score-range">[Score 0–3]</span>
                        <div class="fw-bold mt-1 fs-5" id="lang_cmd_sub">0</div>
                    </div>
                </div>
            </div>

            <!-- Writing -->
            <div class="ace-domain-bar"><span>LANGUAGE</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2" style="font-size:12.5px;">&#10148; Ask the subject to write two (or more) complete sentences about his/her last holiday/weekend/festival. Write in complete sentences and do not use abbreviations.<br>
                        Give 1 point if there are two (or more) complete sentences about the one topic; and give another 1 point if grammar and spelling are correct.</p>
                    <textarea class="form-control form-control-sm" rows="4" name="answers[writing_response]" placeholder="Write or record the subject's sentences here..."><?php echo ace_iii_escape($answers['writing_response'] ?? ''); ?></textarea>
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('writing', 2, $submitted_scores, 'Language'); ?>
                </div>
            </div>

            <!-- Word Repetition -->
            <div class="ace-domain-bar"><span>LANGUAGE</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2" style="font-size:13px;">&#10148; Ask the subject to repeat: <strong>'caterpillar'; 'eccentricity'; 'unintelligible'; 'statistician'</strong><br>
                        Score 2 if all are correct; score 1 if 3 are correct; and score 0 if 2 or less are correct.</p>
                    <input class="form-control form-control-sm" name="answers[multisyllabic_response]" value="<?php echo ace_iii_escape($answers['multisyllabic_response'] ?? ''); ?>" placeholder="Record response">
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('multisyllabic_repetition', 2, $submitted_scores, 'Language'); ?>
                </div>
            </div>

            <!-- Proverb 1 -->
            <div class="ace-domain-bar"><span>LANGUAGE</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Ask the subject to repeat: 'All that glitters is not gold'</p>
                    <input class="form-control form-control-sm" name="answers[proverb_1_response]" value="<?php echo ace_iii_escape($answers['proverb_1_response'] ?? ''); ?>" placeholder="Record repetition">
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('proverb_1', 1, $submitted_scores, 'Language'); ?>
                </div>
            </div>

            <!-- Proverb 2 -->
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Ask the subject to repeat: 'A stitch in time saves nine'</p>
                    <input class="form-control form-control-sm" name="answers[proverb_2_response]" value="<?php echo ace_iii_escape($answers['proverb_2_response'] ?? ''); ?>" placeholder="Record repetition">
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('proverb_2', 1, $submitted_scores, 'Language'); ?>
                </div>
            </div>

            <!-- Picture Naming (Page 3 - 12 SVG Line Drawings) -->
            <div class="ace-domain-bar"><span>LANGUAGE</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Ask the subject to name the following pictures:</p>
                    <div class="ace-pic-grid-12">
                        <?php for ($i = 1; $i <= 12; $i++): $field = 'naming_' . $i; ?>
                            <div class="ace-pic-cell">
                                <div class="ace-pic-top-bar">
                                    <input class="form-control form-control-sm" name="answers[<?php echo $field; ?>_response]" value="<?php echo ace_iii_escape($answers[$field . '_response'] ?? ''); ?>" placeholder="Response <?php echo $i; ?>">
                                    <input class="form-control ace-score" style="width:52px !important;height:30px !important;" type="number" name="scores[<?php echo $field; ?>]" min="0" max="1" step="1" value="<?php echo ace_iii_escape($submitted_scores[$field] ?? ''); ?>" title="Score 0-1" required>
                                </div>
                                <div class="ace-pic-svg-wrap" aria-label="Picture <?php echo $i; ?>">
                                    <?php echo ace_iii_get_picture_svg($i); ?>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="ace-split-right">
                    <div class="ace-score-box">
                        <span class="ace-score-title">Language</span>
                        <span class="ace-score-range">[Score 0–12]</span>
                        <div class="fw-bold mt-1 fs-5" id="lang_naming_sub">0</div>
                    </div>
                </div>
            </div>

            <!-- Pointing Comprehension (Page 3 Bottom) -->
            <div class="ace-domain-bar"><span>LANGUAGE</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Using the pictures above, ask the subject to:</p>
                    <?php
                    $comprehension_questions = [
                        'comprehension_1' => 'Point to the one which is used in rain',
                        'comprehension_2' => 'Point to the one which emits light',
                        'comprehension_3' => 'Point to the one which is associated with farming',
                        'comprehension_4' => 'Point to the one which is found in desserts',
                    ];
                    foreach ($comprehension_questions as $field => $question):
                    ?>
                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                            <span style="font-size:13px;">&bull; <?php echo ace_iii_escape($question); ?></span>
                            <div class="ace-inline-score mt-0 pt-0 border-0">
                                <span>Score (0–1)</span>
                                <input class="form-control ace-score" type="number" name="scores[<?php echo $field; ?>]" min="0" max="1" step="1" value="<?php echo ace_iii_escape($submitted_scores[$field] ?? ''); ?>" required>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="ace-split-right">
                    <div class="ace-score-box">
                        <span class="ace-score-title">Language</span>
                        <span class="ace-score-range">[Score 0–4]</span>
                        <div class="fw-bold mt-1 fs-5" id="lang_comp_sub">0</div>
                    </div>
                </div>
            </div>

            <!-- Reading Words (Page 4 Top) -->
            <div class="ace-domain-bar"><span>LANGUAGE</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2" style="font-size:13px;">&#10148; Ask the subject to read the following words: (Score 1 only if all correct)</p>
                    <div class="text-center fw-bold my-2" style="font-size:18px;line-height:1.45;">
                        sew<br>pint<br>soot<br>dough<br>height
                    </div>
                    <input class="form-control form-control-sm" name="answers[reading_response]" value="<?php echo ace_iii_escape($answers['reading_response'] ?? ''); ?>" placeholder="Record response">
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('reading', 1, $submitted_scores, 'Language'); ?>
                </div>
            </div>
        </section>

        <!-- PAGE 4, 5, 6: VISUOSPATIAL ABILITIES -->
        <section class="ace-paper-box">
            <div class="ace-domain-bar">
                <span>VISUOSPATIAL ABILITIES</span>
                <span class="ace-live-pill">Subtotal: <strong id="badge_visuospatial">0</strong> / 16</span>
            </div>

            <!-- Infinity Diagram -->
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Infinity Diagram: Ask the subject to copy this diagram.</p>
                    <div class="ace-visuo-grid">
                        <div class="ace-stimulus-card">
                            <span>Infinity Diagram</span>
                            <svg class="ace-model-svg" viewBox="0 0 360 110" role="img" aria-label="Infinity diagram to copy">
                                <path d="M12 55 C12 10 68 10 112 55 C156 100 212 100 212 55 C212 10 156 10 112 55 C68 100 12 100 12 55 C12 10 68 10 112 55 C156 100 212 100 212 55 C212 10 268 10 312 55 C356 100 356 10 312 55" />
                            </svg>
                        </div>
                        <div class="ace-canvas-card">
                            <div class="ace-canvas-bar">
                                <span>Copy Infinity Diagram Here</span>
                                <button class="btn btn-sm btn-outline-secondary py-0 px-2 ace-clear" type="button">Clear</button>
                            </div>
                            <canvas class="ace-canvas" data-drawing="infinity" aria-label="Drawing area for infinity diagram"></canvas>
                            <input type="hidden" name="answers[drawing_infinity]" value="<?php echo ace_iii_escape($answers['drawing_infinity'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('infinity', 1, $submitted_scores, 'Visuospatial'); ?>
                </div>
            </div>

            <!-- Wire Cube -->
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Wire cube: Ask the subject to copy this drawing (for scoring, see instructions guide).</p>
                    <div class="ace-visuo-grid">
                        <div class="ace-stimulus-card">
                            <span>Wire Cube</span>
                            <svg class="ace-model-svg" viewBox="0 0 220 130" role="img" aria-label="Wire cube to copy">
                                <rect x="30" y="36" width="95" height="72" />
                                <rect x="75" y="14" width="95" height="72" />
                                <line x1="30" y1="36" x2="75" y2="14" />
                                <line x1="125" y1="36" x2="170" y2="14" />
                                <line x1="30" y1="108" x2="75" y2="86" />
                                <line x1="125" y1="108" x2="170" y2="86" />
                            </svg>
                        </div>
                        <div class="ace-canvas-card">
                            <div class="ace-canvas-bar">
                                <span>Copy Wire Cube Here</span>
                                <button class="btn btn-sm btn-outline-secondary py-0 px-2 ace-clear" type="button">Clear</button>
                            </div>
                            <canvas class="ace-canvas" data-drawing="cube" aria-label="Drawing area for wire cube"></canvas>
                            <input type="hidden" name="answers[drawing_cube]" value="<?php echo ace_iii_escape($answers['drawing_cube'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('cube', 2, $submitted_scores, 'Visuospatial'); ?>
                </div>
            </div>

            <!-- Clock Drawing (With Clock Reference Diagram Added!) -->
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Clock: Ask the subject to draw a clock face with numbers and the hands at ten past five. (For scoring see instruction guide: circle = 1, numbers = 2, hands = 2 if all correct).</p>
                    <div class="ace-visuo-grid">
                        <div class="ace-stimulus-card">
                            <span>Reference Clock (10 Past 5)</span>
                            <svg class="ace-model-svg" viewBox="0 0 140 140" role="img" aria-label="Reference clock diagram at ten past five">
                                <circle cx="70" cy="70" r="56" />
                                <circle cx="70" cy="70" r="3" fill="#0f172a" />
                                <text x="70" y="27" text-anchor="middle" font-size="11" font-weight="bold" fill="#0f172a">12</text>
                                <text x="93" y="33" text-anchor="middle" font-size="10" font-weight="bold" fill="#0f172a">1</text>
                                <text x="109" y="49" text-anchor="middle" font-size="10" font-weight="bold" fill="#0f172a">2</text>
                                <text x="116" y="74" text-anchor="middle" font-size="11" font-weight="bold" fill="#0f172a">3</text>
                                <text x="109" y="96" text-anchor="middle" font-size="10" font-weight="bold" fill="#0f172a">4</text>
                                <text x="93" y="113" text-anchor="middle" font-size="10" font-weight="bold" fill="#0f172a">5</text>
                                <text x="70" y="120" text-anchor="middle" font-size="11" font-weight="bold" fill="#0f172a">6</text>
                                <text x="47" y="113" text-anchor="middle" font-size="10" font-weight="bold" fill="#0f172a">7</text>
                                <text x="31" y="96" text-anchor="middle" font-size="10" font-weight="bold" fill="#0f172a">8</text>
                                <text x="24" y="74" text-anchor="middle" font-size="11" font-weight="bold" fill="#0f172a">9</text>
                                <text x="31" y="49" text-anchor="middle" font-size="10" font-weight="bold" fill="#0f172a">10</text>
                                <text x="47" y="33" text-anchor="middle" font-size="10" font-weight="bold" fill="#0f172a">11</text>
                                <!-- Hour hand pointing to 5 -->
                                <line x1="70" y1="70" x2="87" y2="98" stroke-width="4" />
                                <!-- Minute hand pointing to 2 (10 minutes) -->
                                <line x1="70" y1="70" x2="104" y2="50" stroke-width="2.5" />
                            </svg>
                        </div>
                        <div class="ace-canvas-card">
                            <div class="ace-canvas-bar">
                                <span>Patient Clock Drawing Surface</span>
                                <button class="btn btn-sm btn-outline-secondary py-0 px-2 ace-clear" type="button">Clear</button>
                            </div>
                            <canvas class="ace-canvas" data-drawing="clock" aria-label="Drawing area for clock"></canvas>
                            <input type="hidden" name="answers[drawing_clock]" value="<?php echo ace_iii_escape($answers['drawing_clock'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('clock', 5, $submitted_scores, 'Visuospatial'); ?>
                </div>
            </div>

            <!-- Page 5: Dot Counting -->
            <div class="ace-domain-bar"><span>VISUOSPATIAL ABILITIES</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Ask the subject to count the dots without pointing to them</p>
                    <?php
                    $dot_positions = [
                        // Box 1: 8 dots
                        [[14, 14], [18, 44], [34, 52], [48, 38], [62, 58], [52, 82], [80, 32], [78, 56]],
                        // Box 2: 10 dots
                        [[22, 42], [28, 30], [48, 40], [38, 60], [52, 72], [58, 82], [68, 68], [68, 24], [82, 22], [86, 86]],
                        // Box 3: 7 dots
                        [[24, 18], [72, 20], [28, 52], [44, 46], [42, 68], [14, 84], [78, 80]],
                        // Box 4: 9 dots
                        [[20, 14], [18, 44], [26, 68], [46, 36], [48, 72], [68, 22], [74, 44], [78, 56], [86, 86]],
                    ];
                    ?>
                    <div class="ace-quad-grid">
                        <?php foreach ($dot_positions as $index => $positions):
                            $field = 'dots_' . ($index + 1);
                        ?>
                            <div class="ace-quad-box">
                                <div class="ace-dot-frame" aria-label="Dot counting box <?php echo $index + 1; ?>">
                                    <?php foreach ($positions as $pos): ?>
                                        <i style="left:<?php echo $pos[0]; ?>%;top:<?php echo $pos[1]; ?>%"></i>
                                    <?php endforeach; ?>
                                </div>
                                <div class="d-flex justify-content-between align-items-center gap-2">
                                    <input class="form-control form-control-sm" name="answers[<?php echo $field; ?>_response]" value="<?php echo ace_iii_escape($answers[$field . '_response'] ?? ''); ?>" placeholder="Subject's count">
                                    <div class="ace-inline-score mt-0 pt-0 border-0">
                                        <span>Score (0–1)</span>
                                        <input class="form-control ace-score" type="number" name="scores[<?php echo $field; ?>]" min="0" max="1" step="1" value="<?php echo ace_iii_escape($submitted_scores[$field] ?? ''); ?>" required>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="ace-split-right">
                    <div class="ace-score-box">
                        <span class="ace-score-title">Visuospatial</span>
                        <span class="ace-score-range">[Score 0–4]</span>
                        <div class="fw-bold mt-1 fs-5" id="visuo_dots_sub">0</div>
                    </div>
                </div>
            </div>

            <!-- Page 6: Identify the Fragmented Letters (K, M, A, T) -->
            <div class="ace-domain-bar"><span>VISUOSPATIAL ABILITIES</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Ask the subject to identify the letters</p>
                    <div class="ace-quad-grid">
                        <?php for ($i = 1; $i <= 4; $i++): $field = 'letters_' . $i; ?>
                            <div class="ace-quad-box">
                                <div class="ace-frag-frame" aria-label="Fragmented letter <?php echo $i; ?>">
                                    <?php echo ace_iii_get_fragmented_letter_svg($i); ?>
                                </div>
                                <div class="d-flex justify-content-between align-items-center gap-2">
                                    <input class="form-control form-control-sm" name="answers[letter_fragment_<?php echo $i; ?>_response]" value="<?php echo ace_iii_escape($answers['letter_fragment_' . $i . '_response'] ?? ''); ?>" placeholder="Identified letter">
                                    <div class="ace-inline-score mt-0 pt-0 border-0">
                                        <span>Score (0–1)</span>
                                        <input class="form-control ace-score" type="number" name="scores[<?php echo $field; ?>]" min="0" max="1" step="1" value="<?php echo ace_iii_escape($submitted_scores[$field] ?? ''); ?>" required>
                                    </div>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="ace-split-right">
                    <div class="ace-score-box">
                        <span class="ace-score-title">Visuospatial</span>
                        <span class="ace-score-range">[Score 0–4]</span>
                        <div class="fw-bold mt-1 fs-5" id="visuo_letters_sub">0</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- PAGE 6: MEMORY (DELAYED ADDRESS RECALL & RECOGNITION) -->
        <section class="ace-paper-box">
            <div class="ace-domain-bar"><span>MEMORY</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2 fw-bold" style="font-size:13px;">&#10148; Ask "Now tell me what you remember about that name and address we were repeating at the beginning"</p>
                    <div class="row g-3 align-items-center">
                        <div class="col-md-4 fw-bold" style="font-size:13px;">
                            Sunil Kumar Singh<br>
                            52, Station Road,<br>
                            Gandhinagar,<br>
                            Allahabad.
                        </div>
                        <div class="col-md-8">
                            <textarea class="form-control form-control-sm" name="answers[address_recall_response]" rows="3" placeholder="Record recalled items..."><?php echo ace_iii_escape($answers['address_recall_response'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('address_recall', 7, $submitted_scores, 'Memory'); ?>
                </div>
            </div>

            <div class="ace-domain-bar"><span>MEMORY</span></div>
            <div class="ace-split-row">
                <div class="ace-split-left">
                    <p class="mb-2" style="font-size:12.5px;">&#10148; This test should be done if the subject failed to recall one or more items above. If all items were recalled, skip the test and score 5. If only part was recalled start by ticking items recalled in the shadowed column on the right hand side; and then test not recalled items by telling the subject "ok, I'll give you some hints: was the name X, Y or Z?" and so on. Each recognised item scores one point, which is added to the point gained by recalling.</p>
                    <div class="table-responsive">
                        <table class="ace-recog-table">
                            <tbody>
                                <tr>
                                    <td>Sunil Kumar Sharma</td>
                                    <td class="ace-recog-target">Sunil kumar Singh</td>
                                    <td>Rakesh Yadav</td>
                                    <td class="ace-recog-shaded">recalled</td>
                                </tr>
                                <tr>
                                    <td>25</td>
                                    <td class="ace-recog-target">52</td>
                                    <td>37</td>
                                    <td class="ace-recog-shaded">recalled</td>
                                </tr>
                                <tr>
                                    <td>Market Road</td>
                                    <td>Sastri Marg</td>
                                    <td class="ace-recog-target">Station Road</td>
                                    <td class="ace-recog-shaded">recalled</td>
                                </tr>
                                <tr>
                                    <td>Prakash Nagar</td>
                                    <td class="ace-recog-target">Gandhi Nagar</td>
                                    <td>Patel Nagar</td>
                                    <td class="ace-recog-shaded">recalled</td>
                                </tr>
                                <tr>
                                    <td class="ace-recog-target">Allahabad</td>
                                    <td>Gwalior</td>
                                    <td>Indore</td>
                                    <td class="ace-recog-shaded">recalled</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <input class="form-control form-control-sm mt-2" name="answers[address_recognition_response]" value="<?php echo ace_iii_escape($answers['address_recognition_response'] ?? ''); ?>" placeholder="Record recognised items / notes">
                </div>
                <div class="ace-split-right">
                    <?php echo ace_iii_score_input('address_recognition', 5, $submitted_scores, 'Memory'); ?>
                </div>
            </div>
        </section>

        <!-- PAGE 6: SCORES SUMMARY TABLE -->
        <section class="ace-paper-box">
            <div class="ace-domain-bar"><span>SCORES</span></div>
            <div class="p-3">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-3" style="border-color:#334155;">
                        <tbody>
                            <tr class="table-light fw-bold">
                                <td>TOTAL ACE-III SCORE</td>
                                <td class="text-end" style="width:160px;"><span id="total_all" class="fs-5 fw-bold text-success">0</span> / 100</td>
                            </tr>
                            <tr>
                                <td>Attention</td>
                                <td class="text-end fw-bold"><span id="total_attention">0</span> / 18</td>
                            </tr>
                            <tr>
                                <td>Memory</td>
                                <td class="text-end fw-bold"><span id="total_memory">0</span> / 26</td>
                            </tr>
                            <tr>
                                <td>Fluency</td>
                                <td class="text-end fw-bold"><span id="total_fluency">0</span> / 14</td>
                            </tr>
                            <tr>
                                <td>Language</td>
                                <td class="text-end fw-bold"><span id="total_language">0</span> / 26</td>
                            </tr>
                            <tr>
                                <td>Visuospatial</td>
                                <td class="text-end fw-bold"><span id="total_visuospatial">0</span> / 16</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mb-3">
                    <label class="fw-bold mb-1" style="font-size:13px;" for="clinical_notes">Clinical Observations / Examiner Notes (Optional):</label>
                    <textarea class="form-control form-control-sm" id="clinical_notes" name="clinical_notes" rows="2" maxlength="5000"><?php echo ace_iii_escape($notes); ?></textarea>
                </div>

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <a class="btn btn-outline-secondary btn-sm" href="ace-iii-assessment-records.php">View Saved Records</a>
                    <button type="submit" class="btn btn-success fw-bold px-4" name="submit_ace_iii" value="1" <?php echo empty($patients) || !$ace_iii_schema_ready ? 'disabled' : ''; ?>>
                        Save ACE-III Assessment
                    </button>
                </div>
            </div>
        </section>

        <!-- STICKY FOOTER BAR (CONTAINED INSIDE .ace-page - WILL NEVER OVERLAP SIDEBAR) -->
        <div class="ace-sticky-footer" aria-live="polite">
            <div class="ace-sticky-scores">
                <div><span>Attention:</span><strong id="sticky_attention">0</strong>/18</div>
                <div><span>Memory:</span><strong id="sticky_memory">0</strong>/26</div>
                <div><span>Fluency:</span><strong id="sticky_fluency">0</strong>/14</div>
                <div><span>Language:</span><strong id="sticky_language">0</strong>/26</div>
                <div><span>Visuospatial:</span><strong id="sticky_visuospatial">0</strong>/16</div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="ace-total-pill">Total Score: <span id="sticky_total">0</span> / 100</div>
                <button type="submit" class="btn btn-sm btn-light fw-bold" name="submit_ace_iii" value="1" <?php echo empty($patients) || !$ace_iii_schema_ready ? 'disabled' : ''; ?>>
                    Save Record
                </button>
            </div>
        </div>
    </form>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var scores = document.querySelectorAll('.ace-score');

        function updateTotals() {
            var totals = {
                attention: 0,
                memory: 0,
                fluency: 0,
                language: 0,
                visuospatial: 0
            };
            var subGroups = {
                orient_time: 0,
                orient_place: 0,
                retro_mem: 0,
                lang_cmd: 0,
                lang_naming: 0,
                lang_comp: 0,
                visuo_dots: 0,
                visuo_letters: 0
            };

            scores.forEach(function(input) {
                var value = Number(input.value);
                var maximum = Number(input.max);
                if (input.value !== '' && Number.isFinite(value) && value >= 0 && value <= maximum) {
                    var name = input.name.match(/^scores\[([^\]]+)\]$/);
                    if (!name) return;
                    var field = name[1];

                    var section = field.indexOf('orientation_') === 0 || field === 'registration' || field === 'subtraction' ? 'attention' :
                        field === 'word_recall' || field.indexOf('address_') === 0 || field.indexOf('memory_') === 0 ? 'memory' :
                        field.indexOf('letter_fluency') === 0 || field.indexOf('animal_fluency') === 0 ? 'fluency' :
                        field.indexOf('dots_') === 0 || field.indexOf('letters_') === 0 || ['infinity', 'cube', 'clock'].indexOf(field) !== -1 ? 'visuospatial' :
                        'language';
                    totals[section] += value;

                    if (['orientation_day', 'orientation_date', 'orientation_month', 'orientation_year', 'orientation_season'].indexOf(field) !== -1) subGroups.orient_time += value;
                    if (['orientation_floor', 'orientation_street', 'orientation_city', 'orientation_state', 'orientation_country'].indexOf(field) !== -1) subGroups.orient_place += value;
                    if (field.indexOf('memory_') === 0) subGroups.retro_mem += value;
                    if (field.indexOf('command_') === 0) subGroups.lang_cmd += value;
                    if (field.indexOf('naming_') === 0) subGroups.lang_naming += value;
                    if (field.indexOf('comprehension_') === 0) subGroups.lang_comp += value;
                    if (field.indexOf('dots_') === 0) subGroups.visuo_dots += value;
                    if (field.indexOf('letters_') === 0) subGroups.visuo_letters += value;
                }
            });

            var grand = 0;
            Object.keys(totals).forEach(function(section) {
                var valStr = String(totals[section]);
                var totalEl = document.getElementById('total_' + section);
                var badgeEl = document.getElementById('badge_' + section);
                var stickyEl = document.getElementById('sticky_' + section);
                if (totalEl) totalEl.textContent = valStr;
                if (badgeEl) badgeEl.textContent = valStr;
                if (stickyEl) stickyEl.textContent = valStr;
                grand += totals[section];
            });

            var subMap = {
                orient_time_sub: subGroups.orient_time,
                orient_place_sub: subGroups.orient_place,
                retro_mem_sub: subGroups.retro_mem,
                lang_cmd_sub: subGroups.lang_cmd,
                lang_naming_sub: subGroups.lang_naming,
                lang_comp_sub: subGroups.lang_comp,
                visuo_dots_sub: subGroups.visuo_dots,
                visuo_letters_sub: subGroups.visuo_letters
            };
            Object.keys(subMap).forEach(function(id) {
                var el = document.getElementById(id);
                if (el) el.textContent = String(subMap[id]);
            });

            var grandStr = String(grand);
            document.getElementById('total_all').textContent = grandStr;
            document.getElementById('sticky_total').textContent = grandStr;
        }

        scores.forEach(function(input) {
            input.addEventListener('input', updateTotals);
        });
        updateTotals();

        document.querySelectorAll('.ace-canvas').forEach(function(canvas) {
            var context = canvas.getContext('2d');
            var hidden = document.querySelector('input[name="answers[drawing_' + canvas.dataset.drawing + ']"]');
            var clearBtn = canvas.closest('.ace-canvas-card').querySelector('.ace-clear');

            function fitCanvas() {
                var ratio = window.devicePixelRatio || 1;
                var width = canvas.clientWidth || 380;
                var height = 190;
                var previous = hidden.value;
                canvas.width = width * ratio;
                canvas.height = height * ratio;
                context.setTransform(1, 0, 0, 1, 0, 0);
                context.scale(ratio, ratio);
                context.lineWidth = 3;
                context.lineCap = 'round';
                context.strokeStyle = '#0f172a';
                if (previous) {
                    var image = new Image();
                    image.onload = function() {
                        context.drawImage(image, 0, 0, width, height);
                    };
                    image.src = previous;
                }
            }
            fitCanvas();

            var drawing = false;

            function point(event) {
                var rect = canvas.getBoundingClientRect();
                return {
                    x: event.clientX - rect.left,
                    y: event.clientY - rect.top
                };
            }
            canvas.addEventListener('pointerdown', function(event) {
                drawing = true;
                canvas.setPointerCapture(event.pointerId);
                var p = point(event);
                context.beginPath();
                context.moveTo(p.x, p.y);
            });
            canvas.addEventListener('pointermove', function(event) {
                if (!drawing) return;
                var p = point(event);
                context.lineTo(p.x, p.y);
                context.stroke();
            });

            function finishDrawing() {
                if (!drawing) return;
                drawing = false;
                hidden.value = canvas.toDataURL('image/png');
            }
            canvas.addEventListener('pointerup', finishDrawing);
            canvas.addEventListener('pointercancel', finishDrawing);

            if (clearBtn) {
                clearBtn.addEventListener('click', function() {
                    context.clearRect(0, 0, canvas.width, canvas.height);
                    hidden.value = '';
                });
            }
        });
    });
</script>
<?php require 'layout/footer.php'; ?>