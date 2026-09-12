<?php
// ==============================================================================
// BACKEND CONTROLLER & REST API FOR STUDENT DATA PROCESSING & ID CARD SYSTEM
// ==============================================================================

define('STUDENTS_FILE', __DIR__ . '/students_db.json');
define('CARDS_FILE', __DIR__ . '/cards_db.json');
define('UPLOADS_DIR', __DIR__ . '/uploads/');

// Automatically initialize storage environment
if (!is_dir(UPLOADS_DIR)) {
    mkdir(UPLOADS_DIR, 0777, true);
}
if (!file_exists(STUDENTS_FILE)) {
    file_put_contents(STUDENTS_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
if (!file_exists(CARDS_FILE)) {
    file_put_contents(CARDS_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Database helper functions
function readDb($file) {
    if (!file_exists($file)) return [];
    $raw = @file_get_contents($file);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function writeDb($file, $data) {
    return file_put_contents($file, json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ==============================================================================
// REST API ROUTING
// ==============================================================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];

    // 1. Ingest processed image/QR scanned student record into students_db.json
    if ($action === 'ingest_qr_data' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $input = $_POST;
        }

        if (empty($input['gr_no'])) {
            echo json_encode(['status' => 'error', 'message' => 'G.R No. is required to save student record.']);
            exit;
        }

        $students = readDb(STUDENTS_FILE);
        $found = false;
        foreach ($students as &$s) {
            if ($s['gr_no'] === trim($input['gr_no'])) {
                $s = array_merge($s, [
                    'name'   => trim($input['name'] ?? $s['name']),
                    'class'  => trim($input['class'] ?? $s['class']),
                    'cnic'   => trim($input['cnic'] ?? $s['cnic']),
                    'father' => trim($input['father'] ?? $s['father']),
                    'caste'  => trim($input['caste'] ?? ($s['caste'] ?? '')),
                    'dob'    => trim($input['dob'] ?? ($s['dob'] ?? '')),
                    'phone'  => trim($input['phone'] ?? ($s['phone'] ?? ''))
                ]);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $students[] = [
                'name'       => trim($input['name'] ?? ''),
                'class'      => trim($input['class'] ?? 'Class 6th'),
                'gr_no'      => trim($input['gr_no']),
                'cnic'       => trim($input['cnic'] ?? ''),
                'father'     => trim($input['father'] ?? ''),
                'caste'      => trim($input['caste'] ?? ''),
                'dob'        => trim($input['dob'] ?? ''),
                'phone'      => trim($input['phone'] ?? ''),
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        writeDb(STUDENTS_FILE, $students);
        echo json_encode(['status' => 'success', 'message' => 'Student record saved successfully to students_db.json.']);
        exit;
    }

    // 2. Return unique class names
    if ($action === 'get_classes') {
        $students = readDb(STUDENTS_FILE);
        $classes = [];
        foreach ($students as $s) {
            if (!empty($s['class']) && !in_array($s['class'], $classes)) {
                $classes[] = $s['class'];
            }
        }
        natsort($classes);
        echo json_encode(['status' => 'success', 'classes' => array_values($classes)]);
        exit;
    }

    // 3. Return students under a selected class
    if ($action === 'get_students_by_class') {
        $class = trim($_GET['class'] ?? '');
        $students = readDb(STUDENTS_FILE);
        $filtered = array_filter($students, function($s) use ($class) {
            return $s['class'] === $class;
        });
        echo json_encode(['status' => 'success', 'students' => array_values($filtered)]);
        exit;
    }

    // 4. Save Finalized Student ID Card (Create or Update in cards_db.json)
    if ($action === 'save_card' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $cards = readDb(CARDS_FILE);
        $cardId = !empty($_POST['card_id']) ? $_POST['card_id'] : ('card_' . time() . '_' . rand(100, 999));
        $photoPath = $_POST['existing_photo'] ?? '';

        // Handle uploaded file
        if (!empty($_FILES['photo_file']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['photo_file']['name'], PATHINFO_EXTENSION)) ?: 'jpg';
            $fileName = 'photo_' . time() . '_' . rand(100, 999) . '.' . $ext;
            if (move_uploaded_file($_FILES['photo_file']['tmp_name'], UPLOADS_DIR . $fileName)) {
                $photoPath = 'uploads/' . $fileName;
            }
        } 
        // Handle web camera snapshot (Base64)
        elseif (!empty($_POST['camera_photo_data']) && strpos($_POST['camera_photo_data'], 'data:image') === 0) {
            $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $_POST['camera_photo_data']);
            $imgData = base64_decode($base64);
            $fileName = 'photo_cam_' . time() . '_' . rand(100, 999) . '.png';
            if (file_put_contents(UPLOADS_DIR . $fileName, $imgData)) {
                $photoPath = 'uploads/' . $fileName;
            }
        }

        $cardRecord = [
            'id'              => $cardId,
            'name'            => trim($_POST['name'] ?? ''),
            'class'           => trim($_POST['class'] ?? 'Class 6th'),
            'gr_no'           => trim($_POST['gr_no'] ?? ''),
            'cnic'            => trim($_POST['cnic'] ?? ''),
            'father'          => trim($_POST['father'] ?? ''),
            'admission_date'  => trim($_POST['admission_date'] ?? ''),
            'dob'             => trim($_POST['dob'] ?? ''),
            'caste'           => trim($_POST['caste'] ?? ''),
            'phone'           => trim($_POST['phone'] ?? ''),
            'photo'           => $photoPath ?: 'https://plus.unsplash.com/premium_photo-1682089892133-556bde898f2c?q=80&w=870&auto=format&fit=crop',
            'school_name'     => 'GBHS CHEEZAL ABAD',
            'school_address'  => 'GBHS CHEEZAL ABAD SEMIS: 417040921, Sanghar Road Nawabshah Sindh, Pakistan',
            'updated_at'      => date('Y-m-d H:i:s')
        ];

        // Upsert by card ID or G.R No.
        $isExisting = false;
        foreach ($cards as &$c) {
            if ($c['id'] === $cardId || (!empty($cardRecord['gr_no']) && $c['gr_no'] === $cardRecord['gr_no'])) {
                $cardRecord['id'] = $c['id'];
                $c = $cardRecord;
                $isExisting = true;
                break;
            }
        }
        if (!$isExisting) {
            $cards[] = $cardRecord;
        }

        writeDb(CARDS_FILE, $cards);
        echo json_encode(['status' => 'success', 'message' => 'ID Card record saved securely.', 'card' => $cardRecord]);
        exit;
    }

    // 5. Fetch all saved ID cards
    if ($action === 'get_all_cards') {
        $cards = readDb(CARDS_FILE);
        echo json_encode(['status' => 'success', 'cards' => $cards]);
        exit;
    }

    // 6. Delete an ID card
    if ($action === 'delete_card' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $cardId = $input['id'] ?? '';
        $cards = readDb(CARDS_FILE);
        $cards = array_filter($cards, function($c) use ($cardId) {
            return $c['id'] !== $cardId;
        });
        writeDb(CARDS_FILE, $cards);
        echo json_encode(['status' => 'success', 'message' => 'Card deleted successfully.']);
        exit;
    }

    // 7. Seed demo students for immediate testing
    if ($action === 'seed_demo') {
        $demo = [
            ['name' => 'Muhammad Ali', 'class' => 'Class 6th', 'gr_no' => '21344', 'cnic' => '45404-0809673-8', 'father' => 'Ahmad Raza', 'caste' => 'Rajput', 'dob' => '15-08-2010', 'phone' => '+92 300 1234567'],
            ['name' => 'Bilal Ahmed', 'class' => 'Class 6th', 'gr_no' => '21345', 'cnic' => '45404-0987654-1', 'father' => 'Shabbir Ahmed', 'caste' => 'Syed', 'dob' => '20-11-2010', 'phone' => '+92 301 7654321'],
            ['name' => 'Zain Ul Abideen', 'class' => 'Class 9th', 'gr_no' => '30112', 'cnic' => '45404-1234567-3', 'father' => 'Ghulam Hussain', 'caste' => 'Baloch', 'dob' => '02-04-2007', 'phone' => '+92 302 9876543'],
            ['name' => 'Kamran Khan', 'class' => 'Class 10th', 'gr_no' => '40998', 'cnic' => '45404-5432109-9', 'father' => 'Munir Khan', 'caste' => 'Pathan', 'dob' => '10-09-2006', 'phone' => '+92 303 5554433']
        ];
        writeDb(STUDENTS_FILE, $demo);
        echo json_encode(['status' => 'success', 'message' => 'Sample student database initialized successfully.']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Processing & Backend System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background: #f4f7f6; margin: 0; padding: 25px; color: #333; }
        .box { max-width: 900px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 25px; box-shadow: 0 10px 25px rgba(0,0,0,0.06); }
        h1 { color: #0d2b4d; margin-top: 0; }
        .btn { display: inline-block; padding: 10px 18px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 13px; color: #fff; background: #0d2b4d; border: none; cursor: pointer; transition: 0.2s; }
        .btn:hover { opacity: 0.9; }
        .btn-green { background: #28a745; }
        .btn-orange { background: #e67e22; }
        .stat-badge { background: #eef2f7; border: 1px solid #dcdcdc; padding: 8px 14px; border-radius: 6px; font-weight: 600; display: inline-block; margin-right: 8px; margin-bottom: 8px; }
        .input-row { margin-bottom: 12px; }
        .input-row label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; }
        .input-row input, .input-row select { width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; }
        pre { background: #2d3436; color: #dfe6e9; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 12px; }
    </style>
</head>
<body>
    <div class="box">
        <h1>⚙️ QR Code Processor & Database System</h1>
        <p>This controller manages all scanned student records and ID card archives directly alongside <code>index.html</code>.</p>
        
        <div style="display:flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
            <a href="index.html" class="btn btn-green">➔ Open Main ID Card Maker (index.html)</a>
            <button class="btn btn-orange" onclick="seedSampleData()">🌱 Seed Demo Students</button>
        </div>

        <h3>📊 Current Database Overview</h3>
        <div>
            <div class="stat-badge">Students Scanned: <strong><?php echo count(readDb(STUDENTS_FILE)); ?></strong></div>
            <div class="stat-badge">Cards Created: <strong><?php echo count(readDb(CARDS_FILE)); ?></strong></div>
            <div class="stat-badge">Storage Folder: <code>uploads/</code></div>
        </div>

        <hr style="margin: 20px 0; border: none; border-top: 1px solid #eee;" />

        <h3>🧪 Manual Ingest & Test Scanned QR Data</h3>
        <form onsubmit="manualIngest(event)" style="background: #fafafa; padding: 15px; border-radius: 8px; border: 1px solid #eee;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                <div class="input-row">
                    <label>Full Name</label>
                    <input type="text" id="t-name" placeholder="Student Name" required />
                </div>
                <div class="input-row">
                    <label>Class</label>
                    <select id="t-class">
                        <option value="Class 1st">Class 1st</option>
                        <option value="Class 2nd">Class 2nd</option>
                        <option value="Class 3rd">Class 3rd</option>
                        <option value="Class 4th">Class 4th</option>
                        <option value="Class 5th">Class 5th</option>
                        <option value="Class 6th" selected>Class 6th</option>
                        <option value="Class 7th">Class 7th</option>
                        <option value="Class 8th">Class 8th</option>
                        <option value="Class 9th">Class 9th</option>
                        <option value="Class 10th">Class 10th</option>
                        <option value="Class 11th">Class 11th</option>
                        <option value="Class 12th">Class 12th</option>
                    </select>
                </div>
                <div class="input-row">
                    <label>G.R No.</label>
                    <input type="text" id="t-gr" placeholder="E.g. 54321" required />
                </div>
                <div class="input-row">
                    <label>CNIC / B-Form</label>
                    <input type="text" id="t-cnic" placeholder="45404-1234567-1" />
                </div>
                <div class="input-row">
                    <label>Father's Name</label>
                    <input type="text" id="t-father" placeholder="Father's Name" />
                </div>
            </div>
            <button type="submit" class="btn" style="margin-top: 5px;">📥 Save Student to Database</button>
        </form>

        <h3 style="margin-top: 25px;">API Quick Reference for Automated Scanners</h3>
        <p>Your QR/OCR scanner script can post JSON payloads to <code>qr-.php?action=ingest_qr_data</code>:</p>
        <pre>{
  "name": "Muhammad Ali",
  "class": "Class 6th",
  "gr_no": "21344",
  "cnic": "45404-0809673-8",
  "father": "Ahmad Raza"
}</pre>
    </div>

    <script>
        async function seedSampleData() {
            const res = await fetch('qr-.php?action=seed_demo');
            const data = await res.json();
            alert(data.message);
            location.reload();
        }

        async function manualIngest(e) {
            e.preventDefault();
            const payload = {
                name: document.getElementById('t-name').value,
                class: document.getElementById('t-class').value,
                gr_no: document.getElementById('t-gr').value,
                cnic: document.getElementById('t-cnic').value,
                father: document.getElementById('t-father').value
            };

            const res = await fetch('qr-.php?action=ingest_qr_data', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            alert(data.message);
            location.reload();
        }
    </script>
</body>
</html>