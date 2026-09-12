<?php
// ==========================================
// CONFIGURATION & PERSISTENCE PATHS
// ==========================================
define('STUDENTS_FILE', __DIR__ . '/students_db.json');
define('CARDS_FILE', __DIR__ . '/cards_db.json');
define('UPLOADS_DIR', __DIR__ . '/uploads/');

// Ensure required files and directories exist
if (!is_dir(UPLOADS_DIR)) {
    mkdir(UPLOADS_DIR, 0777, true);
}
if (!file_exists(STUDENTS_FILE)) {
    file_put_contents(STUDENTS_FILE, json_encode([], JSON_PRETTY_PRINT));
}
if (!file_exists(CARDS_FILE)) {
    file_put_contents(CARDS_FILE, json_encode([], JSON_PRETTY_PRINT));
}

// Utility functions
function readDb($file) {
    $raw = @file_get_contents($file);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function writeDb($file, $data) {
    return file_put_contents($file, json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ==========================================
// REST API / AJAX ENDPOINT ROUTING
// ==========================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];

    // 1. qr-.php ingests processed QR/Image data & saves to students_db.json
    if ($action === 'ingest_qr_data' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['gr_no'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid data payload. GR No. is required.']);
            exit;
        }
        $students = readDb(STUDENTS_FILE);
        // Deduplicate or update by GR No.
        $found = false;
        foreach ($students as &$s) {
            if ($s['gr_no'] === $input['gr_no']) {
                $s = array_merge($s, $input);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $students[] = [
                'name'      => $input['name'] ?? '',
                'class'     => $input['class'] ?? 'Class 6th',
                'gr_no'     => $input['gr_no'],
                'cnic'      => $input['cnic'] ?? '',
                'father'    => $input['father'] ?? '',
                'created_at'=> date('Y-m-d H:i:s')
            ];
        }
        writeDb(STUDENTS_FILE, $students);
        echo json_encode(['status' => 'success', 'message' => 'Processed QR data saved successfully.']);
        exit;
    }

    // 2. Fetch list of unique classes
    if ($action === 'get_classes') {
        $students = readDb(STUDENTS_FILE);
        $classes = [];
        foreach ($students as $s) {
            if (!empty($s['class']) && !in_array($s['class'], $classes)) {
                $classes[] = $s['class'];
            }
        }
        sort($classes);
        echo json_encode(['status' => 'success', 'classes' => $classes]);
        exit;
    }

    // 3. Fetch students of a specific class
    if ($action === 'get_students_by_class') {
        $class = $_GET['class'] ?? '';
        $students = readDb(STUDENTS_FILE);
        $filtered = array_filter($students, function($s) use ($class) {
            return $s['class'] === $class;
        });
        echo json_encode(['status' => 'success', 'students' => array_values($filtered)]);
        exit;
    }

    // 4. Save Finalized Student ID Card (Create or Update)
    if ($action === 'save_card' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $cards = readDb(CARDS_FILE);
        $cardId = $_POST['card_id'] ?: ('card_' . time() . '_' . rand(100, 999));
        $photoPath = $_POST['existing_photo'] ?? '';

        // Handle uploaded photo or Base64 web camera snapshot
        if (!empty($_FILES['photo_file']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['photo_file']['name'], PATHINFO_EXTENSION)) ?: 'jpg';
            $fileName = 'photo_' . time() . '_' . rand(100, 999) . '.' . $ext;
            if (move_uploaded_file($_FILES['photo_file']['tmp_name'], UPLOADS_DIR . $fileName)) {
                $photoPath = 'uploads/' . $fileName;
            }
        } elseif (!empty($_POST['camera_photo_data'])) {
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
            'class'           => trim($_POST['class'] ?? ''),
            'gr_no'           => trim($_POST['gr_no'] ?? ''),
            'cnic'            => trim($_POST['cnic'] ?? ''),
            'father'          => trim($_POST['father'] ?? ''),
            'admission_date'  => trim($_POST['admission_date'] ?? ''),
            'dob'             => trim($_POST['dob'] ?? ''),
            'caste'           => trim($_POST['caste'] ?? ''),
            'phone'           => trim($_POST['phone'] ?? ''),
            'photo'           => $photoPath ?: 'https://via.placeholder.com/150',
            'school_name'     => 'GBHS CHEEZAL ABAD',
            'school_address'  => 'GBHS CHEEZAL ABAD SEMIS: 417040921, Sanghar Road Nawabshah Sindh, Pakistan',
            'updated_at'      => date('Y-m-d H:i:s')
        ];

        // Upsert into cards_db.json
        $isExisting = false;
        foreach ($cards as &$c) {
            if ($c['id'] === $cardId || $c['gr_no'] === $cardRecord['gr_no']) {
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

    // 5. Get all generated cards
    if ($action === 'get_all_cards') {
        $cards = readDb(CARDS_FILE);
        echo json_encode(['status' => 'success', 'cards' => $cards]);
        exit;
    }

    // 6. Delete a card
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

    // 7. Seed Demo data if QR processing has not run yet
    if ($action === 'seed_demo') {
        $demo = [
            ['name' => 'Muhammad Ali', 'class' => 'Class 6th', 'gr_no' => '21344', 'cnic' => '45404-0809673-8', 'father' => 'Ahmad Raza'],
            ['name' => 'Bilal Ahmed', 'class' => 'Class 6th', 'gr_no' => '21345', 'cnic' => '45404-0987654-1', 'father' => 'Shabbir Ahmed'],
            ['name' => 'Zain Ul Abideen', 'class' => 'Class 9th', 'gr_no' => '30112', 'cnic' => '45404-1234567-3', 'father' => 'Ghulam Hussain'],
            ['name' => 'Kamran Khan', 'class' => 'Class 10th', 'gr_no' => '40998', 'cnic' => '45404-5432109-9', 'father' => 'Munir Khan']
        ];
        writeDb(STUDENTS_FILE, $demo);
        echo json_encode(['status' => 'success', 'message' => 'Demo student database initialized.']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student ID Card Workflow & Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Montserrat:wght@700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0d2b4d;
            --primary-dark: #071b31;
            --secondary: #fca311;
            --success: #28a745;
            --danger: #dc3545;
            --bg: #f4f7f6;
            --text-dark: #333;
        }
        * { box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { margin: 0; background: var(--bg); color: var(--text-dark); }
        header { background: var(--primary); color: #fff; padding: 20px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        header h1 { margin: 0; font-size: 22px; font-weight: 700; }
        header .nav-links a, header button { background: var(--secondary); color: #000; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; text-decoration: none; font-size: 13px; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .card-box { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .btn { padding: 10px 18px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s; font-size: 14px; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-success { background: var(--success); color: #fff; }
        .btn-