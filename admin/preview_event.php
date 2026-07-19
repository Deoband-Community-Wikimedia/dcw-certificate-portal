<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
require_once '../config.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

$roleId = $_GET['role_id'] ?? null;
if (!$roleId) {
    header("Location: dashboard.php");
    exit;
}

$stmt = $pdo->prepare("
    SELECT er.*, e.name as event_name 
    FROM event_roles er 
    JOIN events e ON er.event_id = e.id 
    WHERE er.id = ?
");
$stmt->execute([$roleId]);
$role = $stmt->fetch();

if (!$role) {
    die("Role not found");
}

$defaultSettings = [
    'name' => [
        'enabled' => true,
        'pos_x' => 105, 'pos_y' => 100, 'font_size' => 40, 'box_width' => 0,
        'text_color' => '0,0,0', 'text_align' => 'C', 'font_file' => '', 'font_name' => 'alexbrush'
    ],
    'certid' => [
        'enabled' => true,
        'pos_x' => 10, 'pos_y' => 195, 'font_size' => 12, 'box_width' => 0,
        'text_color' => '0,0,0', 'text_align' => 'L', 'font_file' => '', 'font_name' => 'helvetica'
    ],
    'date' => [
        'enabled' => true,
        'pos_x' => 200, 'pos_y' => 195, 'font_size' => 12, 'box_width' => 0,
        'text_color' => '0,0,0', 'text_align' => 'R', 'font_file' => '', 'font_name' => 'helvetica',
        'date_format' => 'F j, Y'
    ],
    'qrcode' => [
        'enabled' => false,
        'pos_x' => 10, 'pos_y' => 10, 'font_size' => 30,
        'text_color' => '0,0,0', 'text_align' => 'L', 'font_file' => '', 'font_name' => ''
    ],
    'custom_text' => [
        'enabled' => false,
        'pos_x' => 100, 'pos_y' => 120, 'font_size' => 18, 'box_width' => 0,
        'text_color' => '0,0,0', 'text_align' => 'C', 'font_file' => '', 'font_name' => 'helvetica'
    ]
];

$visualSettings = !empty($role['visual_settings']) ? json_decode($role['visual_settings'], true) : $defaultSettings;
// Ensure all keys exist
foreach (['name', 'certid', 'date', 'qrcode', 'custom_text'] as $key) {
    if (!isset($visualSettings[$key])) {
        $visualSettings[$key] = $defaultSettings[$key];
    }
    // Ensure box_width exists for backwards compatibility
    if ($key !== 'qrcode' && !isset($visualSettings[$key]['box_width'])) {
        $visualSettings[$key]['box_width'] = 0;
    }
}

function getUniqueFilename($dir, $filename) {
    $filename = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $filename);
    $info = pathinfo($filename);
    $name = $info['filename'];
    $ext = isset($info['extension']) ? '.' . $info['extension'] : '';

    $counter = 1;
    $newFilename = $filename;
    while (file_exists($dir . $newFilename)) {
        $newFilename = $name . '(' . $counter . ')' . $ext;
        $counter++;
    }
    return $newFilename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode($_POST['visual_settings_payload'], true);
    
    // Handle font uploads
    $fontDir = '../uploads/fonts/';
    if (!is_dir($fontDir)) {
        mkdir($fontDir, 0777, true);
    }
    
    foreach (['name', 'certid', 'date', 'qrcode', 'custom_text'] as $element) {
        $fileInputName = 'font_file_' . $element;
        if (isset($_FILES[$fileInputName]) && $_FILES[$fileInputName]['error'] === UPLOAD_ERR_OK) {
            $fontExt = strtolower(pathinfo($_FILES[$fileInputName]['name'], PATHINFO_EXTENSION));
            if ($fontExt === 'ttf') {
                $fontFile = getUniqueFilename($fontDir, $_FILES[$fileInputName]['name']);
                move_uploaded_file($_FILES[$fileInputName]['tmp_name'], $fontDir . $fontFile);
                $payload[$element]['font_file'] = $fontFile;
                $payload[$element]['font_name'] = 'custom';
            }
        }
    }

    $jsonStr = json_encode($payload);
    $stmt = $pdo->prepare("UPDATE event_roles SET visual_settings = ?, rotation = ? WHERE id = ?");
    $stmt->execute([$jsonStr, $_POST['rotation'] ?? 0, $roleId]);

    header("Location: preview_event.php?role_id=" . $roleId);
    exit;
}

$fontDir = '../uploads/fonts/';
$ttfFiles = [];
if (is_dir($fontDir)) {
    $files = glob($fontDir . '*.ttf');
    if ($files) {
        foreach ($files as $file) {
            $ttfFiles[] = basename($file);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="https://dcwwiki.org/images/5/56/DCW_logo.png">
    <meta charset="UTF-8">
    <!-- Responsive meta tag -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visual Editor - <?= htmlspecialchars($role['role_name']) ?></title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Alex+Brush&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(8px); z-index: 99999; align-items: center; justify-content: center; }
        .modal-box { background: white; border-radius: 12px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); animation: modalFadeIn 0.2s ease-out; }
        @keyframes modalFadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        .element-box { position: absolute; white-space: nowrap; cursor: move; border: 1px dashed transparent; padding: 2px 5px; user-select: none; line-height: 1; z-index: 10; }
        .element-box.active { border-color: #007bff; background: rgba(0, 123, 255, 0.1); z-index: 12; }
        .element-box.hidden { display: none; }
        /* ===== START OF INSERTION 1: GRID & GUIDE CSS ===== */
        #grid-overlay { 
            position: absolute; top: 0; left: 0; width: 100%; height: 100%; 
            pointer-events: none; z-index: 2; display: none;
            background-image: 
                linear-gradient(rgba(200, 200, 200, 0.4) 1px, transparent 1px), 
                linear-gradient(90deg, rgba(200, 200, 200, 0.4) 1px, transparent 1px);
            background-size: 20px 20px; 
        }
        .guide-line { position: absolute; pointer-events: none; z-index: 4; }
        .guide-center-v { left: 50%; top: 0; bottom: 0; border-left: 1.5px dashed #9933ff; opacity: 0.7; display: none; }
        .guide-center-h { top: 50%; left: 0; right: 0; border-top: 1.5px dashed #9933ff; opacity: 0.7; display: none; }
        .guide-element-v { top: 0; bottom: 0; border-left: 1.5px dashed #00beff; opacity: 0.8; display: none; }
        .guide-element-h { left: 0; right: 0; border-top: 1.5px dashed #00beff; opacity: 0.8; display: none; }
        .guide-align-v { top: 0; bottom: 0; border-left: 1.5px dashed #ff5722; opacity: 0.8; display: none; }
        .guide-align-h { left: 0; right: 0; border-top: 1.5px dashed #ff5722; opacity: 0.8; display: none; }
        
        /* Figma/Canva Active Selection Frame Outline */
        #selection-frame {
            position: absolute;
            pointer-events: none;
            border: 1.5px solid #3b82f6;
            z-index: 13;
            display: none;
            box-sizing: border-box;
        }
        .selection-handle {
            position: absolute;
            width: 8px;
            height: 8px;
            background: white;
            border: 1.5px solid #3b82f6;
            border-radius: 50%;
            pointer-events: none;
            box-sizing: border-box;
        }
        .handle-tl { top: -4px; left: -4px; cursor: nwse-resize; }
        .handle-tr { top: -4px; right: -4px; cursor: nesw-resize; }
        .handle-bl { bottom: -4px; left: -4px; cursor: nesw-resize; }
        .handle-br { bottom: -4px; right: -4px; cursor: nwse-resize; }
        
        #selection-frame.locked {
            border-color: #ef4444;
        }
        #selection-frame.locked .selection-handle {
            border-color: #ef4444;
        }
        
        #selection-lock-badge {
            position: absolute;
            top: -24px;
            left: 50%;
            transform: translateX(-50%);
            background: #ef4444;
            color: white;
            padding: 2px 6px;
            font-size: 10px;
            font-weight: bold;
            border-radius: 3px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            display: none;
        }
        
        /* Floating Coordinates Tooltip */
        #drag-tooltip {
            position: absolute;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(4px);
            color: white;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 600;
            border-radius: 4px;
            pointer-events: none;
            z-index: 100;
            display: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            font-family: monospace;
            white-space: nowrap;
            transform: translateX(-50%);
        }
        
        /* Canvas Measurement Lines to Margins */
        .measure-line {
            position: absolute;
            pointer-events: none;
            z-index: 3;
            border: 1px dotted #e11d48;
            display: none;
        }
        .measure-badge {
            position: absolute;
            background: #e11d48;
            color: white;
            font-size: 9px;
            font-weight: bold;
            padding: 1px 4px;
            border-radius: 3px;
            transform: translate(-50%, -50%);
            pointer-events: none;
            white-space: nowrap;
            font-family: monospace;
            z-index: 5;
        }
        
        /* Segmented Buttons for Alignment */
        .segmented-control {
            display: flex;
            gap: 4px;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 6px;
            border: 1.5px solid #cbd5e1;
            width: max-content;
        }
        .segment-btn {
            background: none;
            border: none;
            padding: 6px 12px;
            cursor: pointer;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            color: #64748b;
        }
        .segment-btn:hover {
            color: #334155;
            background: #e2e8f0;
        }
        .segment-btn.active {
            color: #1e3a8a;
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        /* Color Swatches styling */
        .swatch {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            cursor: pointer;
            border: 1.5px solid #e2e8f0;
            transition: transform 0.1s;
        }
        .swatch:hover {
            transform: scale(1.2);
        }
        
        /* Locked layer state */
        .element-box.locked {
            cursor: not-allowed !important;
        }
        /* ===== END OF INSERTION 1 ===== */
        /* Mobile responsiveness for editor */
        @media (max-width: 900px) {
            .container { flex-direction: column; }
            .controls { width: 100%; box-sizing: border-box; }
        }
    </style>
    
    <!-- Dynamic Font Loading for Elements -->
    <style id="dynamic-fonts-style">
        <?php foreach (['name', 'certid', 'date', 'qrcode', 'custom_text'] as $key): ?>
            <?php if (!empty($visualSettings[$key]['font_file'])): ?>
                @font-face {
                    font-family: 'Font_<?= $key ?>';
                    src: url('../uploads/fonts/<?= htmlspecialchars($visualSettings[$key]['font_file']) ?>') format('truetype');
                }
                #el_<?= $key ?> { font-family: 'Font_<?= $key ?>', sans-serif !important; }
            <?php else: ?>
                #el_<?= $key ?> { font-family: <?= $key === 'name' ? "'Alex Brush', cursive" : "sans-serif" ?> !important; }
            <?php endif; ?>
        <?php endforeach; ?>
    </style>
</head>
<body>

    <div class="navbar">
        <div style="display: flex; align-items: center; gap: 15px;">
            <img src="../assets/DCW_logo.png" alt="DCW Logo" width="35" height="35" decoding="async" style="height: 35px; width: 35px; background: white; padding: 2px; border-radius: 50%;">
            <span style="font-size: 18px; font-weight: bold; letter-spacing: 0.5px; display: none; @media(min-width:600px){display:inline;}">Visual Editor - <?= htmlspecialchars($role['event_name']) ?></span>
        </div>
        <div>
            <a href="manage_roles.php?event_id=<?= $role['event_id'] ?>">Back</a>
            <a href="dashboard.php">Dashboard</a>
        </div>
    </div>

    <div class="container" style="display: flex; max-width: 1400px; gap: 20px; background: transparent; box-shadow: none; padding: 10px;">
        <div class="editor-wrapper">
            <div class="pdf-toolbar">
                <button type="button" id="tool_undo" title="Undo (Ctrl+Z)" disabled style="opacity: 0.4;"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6"></path><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"></path></svg></button>
                <button type="button" id="tool_redo" title="Redo (Ctrl+Y)" disabled style="opacity: 0.4;"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 7v6h-6"></path><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3l3 2.7"></path></svg></button>
                <span class="divider">|</span>
                <button type="button" id="tool_zoom_out" title="Zoom Out"><svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M19 13H5v-2h14v2z" /></svg></button>
                <button type="button" id="tool_zoom_in" title="Zoom In"><svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" /></svg></button>
                <button type="button" id="tool_zoom_fit" class="btn btn-sm" style="margin-left: 8px; padding: 4px 10px; font-size: 11px; height: auto;" title="Zoom to Fit">Zoom to Fit</button>
                <span class="divider">|</span>
                <span class="zoom-val" id="tool_zoom_val">100%</span>
                <span class="divider">|</span>
                <button type="button" id="tool_pdf_preview" title="Live PDF Preview" style="background: #106b9a; color: white; padding: 4px 10px; font-size: 11px; font-weight: bold; border-radius: 4px; display: flex; align-items: center; gap: 4px; height: auto; margin-right: 8px;"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>Preview</button>
                <button type="button" id="tool_help" title="Keyboard Shortcuts & Help" style="padding: 5px;"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg></button>
            </div>
            <div class="editor-container">
                <div id="pdf-container">
                    <!-- ===== START OF INSERTION 2: GRID & GUIDE HTML ELEMENTS ===== -->
                    <div id="grid-overlay"></div>
                    <div class="guide-line guide-center-v"></div>
                    <div class="guide-line guide-center-h"></div>
                    <div class="guide-line guide-element-v" id="guide_v"></div>
                    <div class="guide-line guide-element-h" id="guide_h"></div>
                    <div class="guide-line guide-align-v" id="guide_align_v"></div>
                    <div class="guide-line guide-align-h" id="guide_align_h"></div>
                    
                    <!-- Figma Bounding Selection Frame -->
                    <div id="selection-frame">
                        <div class="selection-handle handle-tl"></div>
                        <div class="selection-handle handle-tr"></div>
                        <div class="selection-handle handle-bl"></div>
                        <div class="selection-handle handle-br"></div>
                        <div id="selection-lock-badge">Locked</div>
                    </div>
                    
                    <!-- Floating Coordinates Tooltip -->
                    <div id="drag-tooltip">X: 0.0mm  Y: 0.0mm</div>
                    
                    <!-- Margin Measurement Guides -->
                    <div class="measure-line" id="measure_top"></div>
                    <div class="measure-line" id="measure_bottom"></div>
                    <div class="measure-line" id="measure_left"></div>
                    <div class="measure-line" id="measure_right"></div>
                    
                    <div class="measure-badge" id="measure_badge_top">0mm</div>
                    <div class="measure-badge" id="measure_badge_bottom">0mm</div>
                    <div class="measure-badge" id="measure_badge_left">0mm</div>
                    <div class="measure-badge" id="measure_badge_right">0mm</div>
                    <!-- ===== END OF INSERTION 2 ===== -->
                    <canvas id="pdf-canvas"></canvas>
        
                    <div id="el_name" class="element-box active" data-id="name">Participant Name</div>
                    <div id="el_certid" class="element-box" data-id="certid">CERT-1A2B3C4D</div>
                    <div id="el_date" class="element-box" data-id="date"><?= date('F j, Y') ?></div>
                    <div id="el_qrcode" class="element-box" data-id="qrcode" style="background: url('https://upload.wikimedia.org/wikipedia/commons/d/d0/QR_code_for_mobile_English_Wikipedia.svg') no-repeat center; background-size: 100% 100%;"></div>
                    <div id="el_custom_text" class="element-box hidden" data-id="custom_text">Participant's Custom Text</div>
                </div>
            </div>
        </div>

        <div class="controls">
            <!-- ===== START OF INSERTION 3: GRID TOGGLE UI ===== -->
            <div style="margin-bottom: 20px; padding: 12px; background: #eaedf1; border-radius: 6px; display: flex; flex-wrap: wrap; align-items: center; gap: 15px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" id="toggle_grid" style="width: auto; height: 18px; cursor: pointer;"> 
                    <label for="toggle_grid" style="margin-bottom: 0; font-weight: bold; cursor: pointer;">Show Grid</label>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <label for="snap_interval" style="margin-bottom: 0; font-weight: bold;">Snap:</label>
                    <select id="snap_interval" style="width: auto; padding: 4px; font-weight: bold; border-radius: 4px; background: white; border: 1.5px solid #cbd5e1; height: auto; cursor: pointer;">
                        <option value="0">None</option>
                        <option value="0.1">0.1mm</option>
                        <option value="0.5">0.5mm</option>
                        <option value="1">1mm</option>
                        <option value="2">2mm</option>
                        <option value="5" selected>5mm</option>
                        <option value="10">10mm</option>
                        <option value="custom">Custom...</option>
                    </select>
                    <input type="number" id="snap_custom_val" step="0.1" min="0.05" value="0.5" style="display: none; width: 65px; padding: 4px; border-radius: 4px; border: 1.5px solid #cbd5e1; font-weight: bold; background: white;">
                </div>
            </div>
            <!-- ===== END OF INSERTION 3 ===== -->
            <!-- ===== FIGMA LAYERS PANEL ===== -->
            <div style="margin-bottom: 20px; background: white; border: 1.5px solid #cbd5e1; border-radius: 6px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <div style="background: #f8fafc; padding: 10px 12px; font-weight: 700; font-size: 13px; color: #334155; border-bottom: 1.5px solid #cbd5e1; display: flex; justify-content: space-between; align-items: center; user-select: none;">
                    <span style="display: flex; align-items: center; gap: 6px;">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" style="color: #64748b;"><rect x="3" y="3" width="7" height="9" rx="1"></rect><rect x="14" y="3" width="7" height="5" rx="1"></rect><rect x="14" y="12" width="7" height="9" rx="1"></rect><rect x="3" y="16" width="7" height="5" rx="1"></rect></svg>
                        Layers Panel
                    </span>
                    <span style="font-size: 10px; padding: 2px 6px; background: #e2e8f0; border-radius: 4px; color: #475569; font-weight: 600;">Interactive</span>
                </div>
                <div id="layers_list" style="display: flex; flex-direction: column;">
                    <!-- Dynamically populated via JS -->
                </div>
            </div>

            <form id="settings-form" method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" id="visual_settings_payload" name="visual_settings_payload">
                <input type="hidden" id="rotation" name="rotation" value="<?= htmlspecialchars($role['rotation'] ?? 0) ?>">

                <div class="form-group" style="display: flex; align-items: center; gap: 10px;">
                    <input type="checkbox" id="field_enabled" style="width: auto; height: 18px;">
                    <label for="field_enabled" style="margin-bottom: 0;">Show this element on PDF</label>
                </div>

                <div class="form-group" id="sample_text_group" style="display: none; padding-top: 10px; border-top: 1px solid #eaedf1;">
                    <label>Preview Sample Text <span style="font-size: 11px; font-weight: normal; color: #777;">(Not saved, for testing only)</span></label>
                    <input type="text" id="sample_text_input" placeholder="Type to preview length..." style="width: 100%; padding: 10px; box-sizing: border-box; border: 1.5px solid #cbd5e1; border-radius: 6px; font-family: monospace;">
                </div>

                <!-- Coordinates Group (Horizontal Row) -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="font-weight: bold; font-size: 11px; margin-bottom: 4px; color: #475569; display: block;">X Position (mm)</label>
                        <input type="number" id="pos_x" step="0.01" style="width: 100%; padding: 8px; box-sizing: border-box; border: 1.5px solid #cbd5e1; border-radius: 6px; background: #f8fafc; font-size: 13px;">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="font-weight: bold; font-size: 11px; margin-bottom: 4px; color: #475569; display: block;">Y Position (mm)</label>
                        <input type="number" id="pos_y" step="0.01" style="width: 100%; padding: 8px; box-sizing: border-box; border: 1.5px solid #cbd5e1; border-radius: 6px; background: #f8fafc; font-size: 13px;">
                    </div>
                </div>

                <!-- Size & Box Width Group (Horizontal Row) -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="font-weight: bold; font-size: 11px; margin-bottom: 4px; color: #475569; display: block;">Size (pt / mm)</label>
                        <input type="number" id="font_size" style="width: 100%; padding: 8px; box-sizing: border-box; border: 1.5px solid #cbd5e1; border-radius: 6px; background: #f8fafc; font-size: 13px;">
                    </div>
                    <div class="form-group" id="group_box_width" style="margin-bottom: 0;">
                        <label style="font-weight: bold; font-size: 11px; margin-bottom: 4px; color: #475569; display: block;">Max Width (mm)</label>
                        <input type="number" id="box_width" step="1" style="width: 100%; padding: 8px; box-sizing: border-box; border: 1.5px solid #cbd5e1; border-radius: 6px; background: #f8fafc; font-size: 13px;">
                    </div>
                </div>

                <div class="form-group" id="group_color">
                    <label>Text Color (HEX/RGB)</label>
                    <div style="display: flex; gap: 5px;">
                        <input type="color" id="color_picker" style="width: 50px; padding: 2px; cursor: pointer; height: 35px;">
                        <input type="text" id="text_color" placeholder="e.g. 0,0,0">
                    </div>
                    <div style="display: flex; gap: 8px; margin-top: 8px; align-items: center;">
                        <span style="font-size: 11px; color: #64748b;">Presets:</span>
                        <div style="display: flex; gap: 6px;" id="color_swatches">
                            <div class="swatch" data-color="#000000" style="background: #000000;" title="Black"></div>
                            <div class="swatch" data-color="#106b9a" style="background: #106b9a;" title="DCW Blue"></div>
                            <div class="swatch" data-color="#d4af37" style="background: #d4af37;" title="Gold"></div>
                            <div class="swatch" data-color="#334155" style="background: #334155;" title="Charcoal"></div>
                            <div class="swatch" data-color="#b91c1c" style="background: #b91c1c;" title="Red"></div>
                        </div>
                    </div>
                </div>

                <div class="form-group" id="group_align">
                    <label>Text Alignment</label>
                    <div class="segmented-control">
                        <button type="button" class="segment-btn active" data-align="L" title="Align Left">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><line x1="17" y1="10" x2="3" y2="10"></line><line x1="21" y1="6" x2="3" y2="6"></line><line x1="21" y1="14" x2="3" y2="14"></line><line x1="17" y1="18" x2="3" y2="18"></line></svg>
                        </button>
                        <button type="button" class="segment-btn" data-align="C" title="Align Center">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="10" x2="6" y2="10"></line><line x1="21" y1="6" x2="3" y2="6"></line><line x1="21" y1="14" x2="3" y2="14"></line><line x1="18" y1="18" x2="6" y2="18"></line></svg>
                        </button>
                        <button type="button" class="segment-btn" data-align="R" title="Align Right">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><line x1="21" y1="10" x2="7" y2="10"></line><line x1="21" y1="6" x2="3" y2="6"></line><line x1="21" y1="14" x2="3" y2="14"></line><line x1="21" y1="18" x2="7" y2="18"></line></svg>
                        </button>
                    </div>
                    <input type="hidden" id="text_align" value="L">
                </div>

                <div class="form-group" id="group_font">
                    <label>Font Family</label>
                    <select id="existing_font">
                        <option value="">Default Font</option>
                        <?php foreach ($ttfFiles as $fName): ?>
                            <option value="<?= htmlspecialchars($fName) ?>"><?= htmlspecialchars($fName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" id="font_upload_group">
                    <label>Upload Custom Font (.ttf)</label>
                    <input type="file" id="font_file_input" accept=".ttf">
                    <div style="font-size: 11px; color: #777; margin-top: 4px;">For <span id="lbl_current_tab">Name</span></div>
                </div>

                <div class="form-group" id="date_format_group" style="display: none;">
                    <label>Date Format</label>
                    <select id="date_format">
                        <option value="F j, Y">June 14, 2026 (F j, Y)</option>
                        <option value="Y-m-d">2026-06-14 (Y-m-d)</option>
                        <option value="d/m/Y">14/06/2026 (d/m/Y)</option>
                        <option value="m/d/Y">06/14/2026 (m/d/Y)</option>
                        <option value="j F Y">14 June 2026 (j F Y)</option>
                    </select>
                </div>

                <!-- Quick Actions Center/Align Buttons -->
                <div class="form-group" style="padding-top: 15px; border-top: 1px solid #eaedf1;">
                    <label style="font-weight: bold; margin-bottom: 8px;">Quick Actions</label>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" id="btn_center_x" class="btn btn-sm" style="flex: 1; padding: 8px; font-size: 12px; font-weight: bold; text-align: center;">Center Horizontally</button>
                        <button type="button" id="btn_center_y" class="btn btn-sm" style="flex: 1; padding: 8px; font-size: 12px; font-weight: bold; text-align: center;">Center Vertically</button>
                    </div>
                </div>

                <!-- Hidden actual file inputs for form submission -->
                <input type="file" name="font_file_name" id="real_file_name" style="display:none" accept=".ttf">
                <input type="file" name="font_file_certid" id="real_file_certid" style="display:none" accept=".ttf">
                <input type="file" name="font_file_date" id="real_file_date" style="display:none" accept=".ttf">
                <input type="file" name="font_file_qrcode" id="real_file_qrcode" style="display:none" accept=".ttf">
                <input type="file" name="font_file_custom_text" id="real_file_custom_text" style="display:none" accept=".ttf">

                <button type="submit" class="btn btn-green" style="width: 100%; margin-top: 15px;">Save All Layouts</button>
            </form>
        </div>
    </div>

    <!-- Live PDF Preview Modal -->
    <div id="pdf-preview-modal" class="modal-overlay">
        <div class="modal-box" style="width: 90%; max-width: 1000px; height: 85%;">
            <div style="background: #f8fafc; padding: 15px 20px; border-bottom: 1.5px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; user-select: none;">
                <span style="font-weight: 700; font-size: 16px; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#106b9a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    Live PDF Preview (Draft)
                </span>
                <button type="button" id="close-pdf-preview" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #64748b; line-height: 1;">&times;</button>
            </div>
            <div style="flex: 1; background: #525659; position: relative;">
                <iframe id="pdf-preview-iframe" style="width: 100%; height: 100%; border: none;"></iframe>
            </div>
        </div>
    </div>

    <!-- Help Guide Modal -->
    <div id="help-guide-modal" class="modal-overlay">
        <div class="modal-box" style="width: 90%; max-width: 600px; max-height: 80%;">
            <div style="background: #f8fafc; padding: 15px 20px; border-bottom: 1.5px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; user-select: none;">
                <span style="font-weight: 700; font-size: 16px; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#106b9a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                    Editor Help & Keyboard Shortcuts
                </span>
                <button type="button" id="close-help-guide" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #64748b; line-height: 1;">&times;</button>
            </div>
            <div style="flex: 1; overflow-y: auto; padding: 20px; font-size: 14px; line-height: 1.6; color: #334155;">
                <h4 style="margin-top: 0; color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;">Keyboard Shortcuts</h4>
                <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 25px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Undo last action</span>
                        <span><kbd style="background: #e2e8f0; padding: 3px 6px; border-radius: 4px; font-family: monospace; font-size: 12px; font-weight: bold; border-bottom: 2px solid #cbd5e1; color: #000;">Ctrl + Z</kbd></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Redo reverted action</span>
                        <span><kbd style="background: #e2e8f0; padding: 3px 6px; border-radius: 4px; font-family: monospace; font-size: 12px; font-weight: bold; border-bottom: 2px solid #cbd5e1; color: #000;">Ctrl + Y</kbd> / <kbd style="background: #e2e8f0; padding: 3px 6px; border-radius: 4px; font-family: monospace; font-size: 12px; font-weight: bold; border-bottom: 2px solid #cbd5e1; color: #000;">Ctrl + Shift + Z</kbd></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Select next element</span>
                        <span><kbd style="background: #e2e8f0; padding: 3px 6px; border-radius: 4px; font-family: monospace; font-size: 12px; font-weight: bold; border-bottom: 2px solid #cbd5e1; color: #000;">Tab</kbd></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Select previous element</span>
                        <span><kbd style="background: #e2e8f0; padding: 3px 6px; border-radius: 4px; font-family: monospace; font-size: 12px; font-weight: bold; border-bottom: 2px solid #cbd5e1; color: #000;">Shift + Tab</kbd></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Toggle lock/unlock on active element</span>
                        <span><kbd style="background: #e2e8f0; padding: 3px 6px; border-radius: 4px; font-family: monospace; font-size: 12px; font-weight: bold; border-bottom: 2px solid #cbd5e1; color: #000;">L</kbd></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Toggle visibility on active element</span>
                        <span><kbd style="background: #e2e8f0; padding: 3px 6px; border-radius: 4px; font-family: monospace; font-size: 12px; font-weight: bold; border-bottom: 2px solid #cbd5e1; color: #000;">V</kbd></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Center active element horizontally</span>
                        <span><kbd style="background: #e2e8f0; padding: 3px 6px; border-radius: 4px; font-family: monospace; font-size: 12px; font-weight: bold; border-bottom: 2px solid #cbd5e1; color: #000;">C</kbd></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Precision nudge element</span>
                        <span><kbd style="background: #e2e8f0; padding: 3px 6px; border-radius: 4px; font-family: monospace; font-size: 12px; font-weight: bold; border-bottom: 2px solid #cbd5e1; color: #000;">&larr;</kbd> <kbd style="background: #e2e8f0; padding: 3px 6px; border-radius: 4px; font-family: monospace; font-size: 12px; font-weight: bold; border-bottom: 2px solid #cbd5e1; color: #000;">&uarr;</kbd> <kbd style="background: #e2e8f0; padding: 3px 6px; border-radius: 4px; font-family: monospace; font-size: 12px; font-weight: bold; border-bottom: 2px solid #cbd5e1; color: #000;">&rarr;</kbd> <kbd style="background: #e2e8f0; padding: 3px 6px; border-radius: 4px; font-family: monospace; font-size: 12px; font-weight: bold; border-bottom: 2px solid #cbd5e1; color: #000;">&darr;</kbd></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Large nudge (without Snapping)</span>
                        <span><kbd style="background: #e2e8f0; padding: 3px 6px; border-radius: 4px; font-family: monospace; font-size: 12px; font-weight: bold; border-bottom: 2px solid #cbd5e1; color: #000;">Shift + Arrow</kbd></span>
                    </div>
                </div>

                <h4 style="color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;">Pro Design Features</h4>
                <ul style="margin: 0; padding-left: 20px;">
                    <li><strong>Interactive Layers Panel:</strong> Lock elements to prevent accidental dragging, or hide them from the generated PDF template.</li>
                    <li><strong>Dynamic Grid Overlay:</strong> Show grid lines and align your items cleanly. Selecting an interval of 0.1mm, 0.5mm, 1mm, etc. will scale the visual grid layout instantly.</li>
                    <li><strong>Manual Position Entry:</strong> Enter the exact X/Y coordinate values in millimeters to snap elements precisely.</li>
                    <li><strong>Dynamic Guidelines:</strong> Alignment guides automatically light up when elements align with the center or boundaries of other layers.</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        const pdfUrl = '../uploads/templates/<?= $role['template_file'] ?>';
        const canvas = document.getElementById('pdf-canvas');
        const ctx = canvas.getContext('2d');
        const container = document.getElementById('pdf-container');

        // State
        const settings = <?= json_encode($visualSettings) ?>;
        let activeTab = 'name';
        
        // Locked states for Canva/Figma element locking
        const lockedStates = {
            name: false,
            certid: false,
            date: false,
            qrcode: false,
            custom_text: false
        };
        
        let pdfWidthMM = 297;
        let pdfHeightMM = 210;
        let currentScale = 1.0;
        let currentRotation = parseInt(document.getElementById('rotation').value) || 0;
        let pdfDoc = null;

        // Caching performance variables during drag operations to avoid DOM layout thrashing
        let cachedTargetRects = [];
        let activeRectWidth = 0;
        let activeRectHeight = 0;

        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';
/* ===== START OF INSERTION 4A: GRID AND GUIDE FUNCTIONS ===== */
        function getSnapInterval() {
            const select = document.getElementById('snap_interval');
            if (!select) return 0;
            if (select.value === 'custom') {
                return parseFloat(document.getElementById('snap_custom_val').value) || 0;
            }
            return parseFloat(select.value) || 0;
        }

        function updateGridOverlay() {
            const gridOverlay = document.getElementById('grid-overlay');
            if (!gridOverlay || !canvas) return;
            
            let intervalMM = getSnapInterval();
            if (intervalMM <= 0) {
                intervalMM = 10; // Default grid lines to 10mm if snapping is None
            }
            
            // Calculate grid size in pixels based on the current canvas width
            const pxSize = (intervalMM / pdfWidthMM) * canvas.offsetWidth;
            gridOverlay.style.backgroundSize = `${pxSize}px ${pxSize}px`;
        }

        // Listen for the grid toggle checkbox
        document.getElementById('toggle_grid').addEventListener('change', function() {
            const displayStyle = this.checked ? 'block' : 'none';
            document.getElementById('grid-overlay').style.display = displayStyle;
            document.querySelectorAll('.guide-center-v, .guide-center-h').forEach(el => {
                el.style.display = displayStyle;
            });
            if (this.checked) {
                updateGridOverlay();
            }
        });

        // Listen for snap interval changes to update the grid and guides
        document.getElementById('snap_interval').addEventListener('change', function() {
            const isCustom = this.value === 'custom';
            document.getElementById('snap_custom_val').style.display = isCustom ? 'inline-block' : 'none';
            updateGridOverlay();
            updateElementGuides();
        });

        document.getElementById('snap_custom_val').addEventListener('input', function() {
            updateGridOverlay();
            updateElementGuides();
        });

        // Function to move the blue guidelines to the currently active element
        function updateElementGuides() {
            const el = document.getElementById('el_' + activeTab);
            const guideV = document.getElementById('guide_v');
            const guideH = document.getElementById('guide_h');
            
            // Hide guides if element doesn't exist or is hidden
            if (!el || el.classList.contains('hidden')) {
                guideV.style.display = 'none';
                guideH.style.display = 'none';
                return;
            }
            
            // Show guides and map their position to the element's position
            guideV.style.display = 'block';
            guideH.style.display = 'block';
            guideV.style.left = el.style.left;
            guideH.style.top = el.style.top;

            // Also check and update element-to-element alignment guides
            checkElementAlignment();
            
            // Update the Figma-style bounding selection frame
            updateSelectionFrame();
            
            // Update real-time margin measurement lines
            updateMeasurementLines();
        }

        // Update the Figma-style selection frame overlay
        function updateSelectionFrame() {
            const el = document.getElementById('el_' + activeTab);
            const frame = document.getElementById('selection-frame');
            const badge = document.getElementById('selection-lock-badge');
            
            if (!el || el.classList.contains('hidden')) {
                frame.style.display = 'none';
                return;
            }
            
            frame.style.display = 'block';
            frame.style.width = el.offsetWidth + 'px';
            frame.style.height = el.offsetHeight + 'px';
            
            let frameLeft = el.offsetLeft;
            let frameTop = el.offsetTop;
            
            // Compensate for CSS transforms on legacy centered/right-aligned elements (box width === 0)
            const s = settings[activeTab];
            const boxWidth = parseFloat(s.box_width) || 0;
            if (boxWidth === 0 && activeTab !== 'qrcode') {
                if (s.text_align === 'C') {
                    frameLeft = el.offsetLeft - el.offsetWidth / 2;
                } else if (s.text_align === 'R') {
                    frameLeft = el.offsetLeft - el.offsetWidth;
                }
            }
            
            frame.style.left = frameLeft + 'px';
            frame.style.top = frameTop + 'px';
            
            const isLocked = lockedStates[activeTab];
            frame.classList.toggle('locked', isLocked);
            badge.style.display = isLocked ? 'block' : 'none';
        }

        // Draw and update measurement lines from active element to canvas margins
        function updateMeasurementLines() {
            const el = document.getElementById('el_' + activeTab);
            const showLines = isDragging && !lockedStates[activeTab];
            
            const mTop = document.getElementById('measure_top');
            const mBottom = document.getElementById('measure_bottom');
            const mLeft = document.getElementById('measure_left');
            const mRight = document.getElementById('measure_right');
            
            const bTop = document.getElementById('measure_badge_top');
            const bBottom = document.getElementById('measure_badge_bottom');
            const bLeft = document.getElementById('measure_badge_left');
            const bRight = document.getElementById('measure_badge_right');
            
            if (!el || el.classList.contains('hidden') || !showLines) {
                [mTop, mBottom, mLeft, mRight, bTop, bBottom, bLeft, bRight].forEach(i => i.style.display = 'none');
                return;
            }

            const canvasWidth = canvas.offsetWidth;
            const canvasHeight = canvas.offsetHeight;
            
            // Bounding box dimensions
            let w_mm = (activeRectWidth / canvasWidth) * pdfWidthMM;
            let h_mm = (activeRectHeight / canvasHeight) * pdfHeightMM;
            let x_mm = parseFloat(settings[activeTab].pos_x) || 0;
            let y_mm = parseFloat(settings[activeTab].pos_y) || 0;
            
            // Calculate absolute visual boundaries in pixels
            let elLeftPx = el.offsetLeft;
            let elTopPx = el.offsetTop;
            const s = settings[activeTab];
            const boxWidth = parseFloat(s.box_width) || 0;
            if (boxWidth === 0 && activeTab !== 'qrcode') {
                if (s.text_align === 'C') {
                    elLeftPx = el.offsetLeft - el.offsetWidth / 2;
                } else if (s.text_align === 'R') {
                    elLeftPx = el.offsetLeft - el.offsetWidth;
                }
            }
            
            const elRightPx = elLeftPx + el.offsetWidth;
            const elBottomPx = elTopPx + el.offsetHeight;
            
            const elCenterXPx = elLeftPx + el.offsetWidth / 2;
            const elCenterYPx = elTopPx + el.offsetHeight / 2;
            
            // Calculate distances in mm
            const distLeftMM = Math.max(0, x_mm);
            const distTopMM = Math.max(0, y_mm);
            const distRightMM = Math.max(0, pdfWidthMM - (x_mm + w_mm));
            const distBottomMM = Math.max(0, pdfHeightMM - (y_mm + h_mm));
            
            // 1. TOP LINE
            mTop.style.display = 'block';
            mTop.style.left = elCenterXPx + 'px';
            mTop.style.top = '0px';
            mTop.style.width = '0px';
            mTop.style.height = elTopPx + 'px';
            
            bTop.style.display = 'block';
            bTop.style.left = elCenterXPx + 'px';
            bTop.style.top = (elTopPx / 2) + 'px';
            bTop.textContent = distTopMM.toFixed(1) + ' mm';
            
            // 2. BOTTOM LINE
            mBottom.style.display = 'block';
            mBottom.style.left = elCenterXPx + 'px';
            mBottom.style.top = elBottomPx + 'px';
            mBottom.style.width = '0px';
            mBottom.style.height = Math.max(0, canvasHeight - elBottomPx) + 'px';
            
            bBottom.style.display = 'block';
            bBottom.style.left = elCenterXPx + 'px';
            bBottom.style.top = (elBottomPx + (canvasHeight - elBottomPx) / 2) + 'px';
            bBottom.textContent = distBottomMM.toFixed(1) + ' mm';
            
            // 3. LEFT LINE
            mLeft.style.display = 'block';
            mLeft.style.left = '0px';
            mLeft.style.top = elCenterYPx + 'px';
            mLeft.style.width = elLeftPx + 'px';
            mLeft.style.height = '0px';
            
            bLeft.style.display = 'block';
            bLeft.style.left = (elLeftPx / 2) + 'px';
            bLeft.style.top = elCenterYPx + 'px';
            bLeft.textContent = distLeftMM.toFixed(1) + ' mm';
            
            // 4. RIGHT LINE
            mRight.style.display = 'block';
            mRight.style.left = elRightPx + 'px';
            mRight.style.top = elCenterYPx + 'px';
            mRight.style.width = Math.max(0, canvasWidth - elRightPx) + 'px';
            mRight.style.height = '0px';
            
            bRight.style.display = 'block';
            bRight.style.left = (elRightPx + (canvasWidth - elRightPx) / 2) + 'px';
            bRight.style.top = elCenterYPx + 'px';
            bRight.textContent = distRightMM.toFixed(1) + ' mm';
        }

        // Render interactive Layers Panel list
        function renderLayersPanel() {
            const container = document.getElementById('layers_list');
            if (!container) return;
            container.innerHTML = '';
            
            const keys = ['name', 'certid', 'date', 'qrcode', 'custom_text'];
            const names = {
                name: 'Participant Name',
                certid: 'Certificate ID',
                date: 'Issue Date',
                qrcode: 'QR Code',
                custom_text: 'Custom Text'
            };
            
            keys.forEach(key => {
                const el = document.getElementById('el_' + key);
                const isHidden = !settings[key].enabled || (el && el.classList.contains('hidden'));
                const isLocked = lockedStates[key];
                const isActive = (key === activeTab);
                
                const item = document.createElement('div');
                item.style.display = 'flex';
                item.style.alignItems = 'center';
                item.style.justifyContent = 'space-between';
                item.style.padding = '10px 12px';
                item.style.cursor = 'pointer';
                item.style.fontSize = '12px';
                item.style.borderBottom = '1px solid #f1f5f9';
                item.style.background = isActive ? '#eff6ff' : 'white';
                item.style.color = isActive ? '#1d4ed8' : '#475569';
                item.style.fontWeight = isActive ? '600' : 'normal';
                
                // Add hover effect
                item.addEventListener('mouseenter', () => {
                    if (!isActive) item.style.background = '#f8fafc';
                });
                item.addEventListener('mouseleave', () => {
                    if (!isActive) item.style.background = 'white';
                });
                
                // Row selection click handler
                item.addEventListener('click', (e) => {
                    if (e.target.closest('button')) return;
                    selectElement(key);
                });
                
                const labelSpan = document.createElement('span');
                labelSpan.textContent = names[key];
                labelSpan.style.display = 'flex';
                labelSpan.style.alignItems = 'center';
                labelSpan.style.gap = '6px';
                
                if (isActive) {
                    const dot = document.createElement('span');
                    dot.style.width = '6px';
                    dot.style.height = '6px';
                    dot.style.borderRadius = '50%';
                    dot.style.background = '#3b82f6';
                    labelSpan.prepend(dot);
                }
                
                const controlsDiv = document.createElement('div');
                controlsDiv.style.display = 'flex';
                controlsDiv.style.alignItems = 'center';
                controlsDiv.style.gap = '8px';
                
                // Visibility Eye Button
                const visBtn = document.createElement('button');
                visBtn.type = 'button';
                visBtn.style.background = 'none';
                visBtn.style.border = 'none';
                visBtn.style.cursor = 'pointer';
                visBtn.style.padding = '2px';
                visBtn.style.color = isHidden ? '#94a3b8' : '#3b82f6';
                visBtn.innerHTML = isHidden 
                    ? `<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>`
                    : `<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>`;
                visBtn.title = isHidden ? "Show Element" : "Hide Element";
                visBtn.addEventListener('click', () => {
                    settings[key].enabled = isHidden ? 1 : 0;
                    if (key === activeTab) {
                        formInputs.enabled.checked = isHidden;
                    }
                    
                    const targetEl = document.getElementById('el_' + key);
                    if (isHidden) {
                        targetEl.classList.remove('hidden');
                    } else {
                        targetEl.classList.add('hidden');
                    }
                    
                    applyStyleToElement(key);
                    updateElementGuides();
                    renderLayersPanel();
                });
                
                // Lock Button
                const lockBtn = document.createElement('button');
                lockBtn.type = 'button';
                lockBtn.style.background = 'none';
                lockBtn.style.border = 'none';
                lockBtn.style.cursor = 'pointer';
                lockBtn.style.padding = '2px';
                lockBtn.style.color = isLocked ? '#ef4444' : '#94a3b8';
                lockBtn.innerHTML = isLocked
                    ? `<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>`
                    : `<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 9.9-1"></path></svg>`;
                lockBtn.title = isLocked ? "Unlock Element" : "Lock Element";
                lockBtn.addEventListener('click', () => {
                    lockedStates[key] = !isLocked;
                    const targetEl = document.getElementById('el_' + key);
                    if (lockedStates[key]) {
                        targetEl.classList.add('locked');
                    } else {
                        targetEl.classList.remove('locked');
                    }
                    updateElementGuides();
                    renderLayersPanel();
                });
                
                controlsDiv.appendChild(visBtn);
                controlsDiv.appendChild(lockBtn);
                item.appendChild(labelSpan);
                item.appendChild(controlsDiv);
                container.appendChild(item);
            });
        }

        // Helper function to calculate an element's rectangle in millimeters (mm)
        function getElementRectMM(key) {
            const el = document.getElementById('el_' + key);
            const s = settings[key];
            if (!el || !s.enabled || el.classList.contains('hidden')) return null;
            
            let w_mm = 0;
            let h_mm = 0;
            
            if (key === 'qrcode') {
                w_mm = parseFloat(s.font_size);
                h_mm = parseFloat(s.font_size);
            } else {
                w_mm = (el.offsetWidth / canvas.offsetWidth) * pdfWidthMM;
                h_mm = (el.offsetHeight / canvas.offsetHeight) * pdfHeightMM;
            }
            
            const left_px = el.offsetLeft;
            const top_px = el.offsetTop;
            
            const x_mm = (left_px / canvas.offsetWidth) * pdfWidthMM;
            const y_mm = (top_px / canvas.offsetHeight) * pdfHeightMM;
            
            return {
                key: key,
                left: x_mm,
                top: y_mm,
                right: x_mm + w_mm,
                bottom: y_mm + h_mm,
                centerX: x_mm + w_mm / 2,
                centerY: y_mm + h_mm / 2,
                width: w_mm,
                height: h_mm
            };
        }

        // High-performance check for element-to-element alignment and guides drawing
        function checkElementAlignment() {
            const activeEl = document.getElementById('el_' + activeTab);
            const guideAlignV = document.getElementById('guide_align_v');
            const guideAlignH = document.getElementById('guide_align_h');
            
            if (!activeEl || activeEl.classList.contains('hidden')) {
                guideAlignV.style.display = 'none';
                guideAlignH.style.display = 'none';
                return;
            }

            // Get active element's rectangle parameters
            let w_mm = 0;
            let h_mm = 0;
            if (isDragging) {
                // Use cached values during drag to prevent forcing layout recalculation
                w_mm = (activeRectWidth / canvas.offsetWidth) * pdfWidthMM;
                h_mm = (activeRectHeight / canvas.offsetHeight) * pdfHeightMM;
            } else {
                w_mm = (activeEl.offsetWidth / canvas.offsetWidth) * pdfWidthMM;
                h_mm = (activeEl.offsetHeight / canvas.offsetHeight) * pdfHeightMM;
            }
            
            const x_mm = (activeEl.offsetLeft / canvas.offsetWidth) * pdfWidthMM;
            const y_mm = (activeEl.offsetTop / canvas.offsetHeight) * pdfHeightMM;
            
            const activeRect = {
                left: x_mm,
                top: y_mm,
                right: x_mm + w_mm,
                bottom: y_mm + h_mm,
                centerX: x_mm + w_mm / 2,
                centerY: y_mm + h_mm / 2,
                width: w_mm,
                height: h_mm
            };
            
            const thresholdMM = 2.0; // Snapping and guide threshold in millimeters
            
            let matchV = null;
            let matchH = null;
            
            // Loop through targets (use cached targets during dragging for performance)
            const targets = isDragging ? cachedTargetRects : [];
            if (!isDragging) {
                const keys = ['name', 'certid', 'date', 'qrcode', 'custom_text'];
                for (const key of keys) {
                    if (key === activeTab) continue;
                    const r = getElementRectMM(key);
                    if (r) targets.push(r);
                }
            }
            
            for (const target of targets) {
                // Vertical check (matching left, center, or right X coordinate)
                if (Math.abs(activeRect.left - target.left) < thresholdMM) {
                    matchV = { x_mm: target.left, type: 'left' };
                } else if (Math.abs(activeRect.centerX - target.centerX) < thresholdMM) {
                    matchV = { x_mm: target.centerX, type: 'centerX' };
                } else if (Math.abs(activeRect.right - target.right) < thresholdMM) {
                    matchV = { x_mm: target.right, type: 'right' };
                }
                
                // Horizontal check (matching top, center, or bottom Y coordinate)
                if (Math.abs(activeRect.top - target.top) < thresholdMM) {
                    matchH = { y_mm: target.top, type: 'top' };
                } else if (Math.abs(activeRect.centerY - target.centerY) < thresholdMM) {
                    matchH = { y_mm: target.centerY, type: 'centerY' };
                } else if (Math.abs(activeRect.bottom - target.bottom) < thresholdMM) {
                    matchH = { y_mm: target.bottom, type: 'bottom' };
                }
            }
            
            // Draw & Snap vertical line
            if (matchV) {
                const pxX = (matchV.x_mm / pdfWidthMM) * canvas.offsetWidth;
                guideAlignV.style.left = pxX + 'px';
                guideAlignV.style.display = 'block';
                
                if (isDragging) {
                    let snappedLeft = pxX;
                    if (matchV.type === 'centerX') {
                        snappedLeft = pxX - (activeRect.width / 2 / pdfWidthMM) * canvas.offsetWidth;
                    } else if (matchV.type === 'right') {
                        snappedLeft = pxX - (activeRect.width / pdfWidthMM) * canvas.offsetWidth;
                    }
                    activeEl.style.left = snappedLeft + 'px';
                    const snappedXMM = (snappedLeft / canvas.offsetWidth) * pdfWidthMM;
                    settings[activeTab].pos_x = parseFloat(snappedXMM.toFixed(2));
                    formInputs.pos_x.value = settings[activeTab].pos_x;
                }
            } else {
                guideAlignV.style.display = 'none';
            }
            
            // Draw & Snap horizontal line
            if (matchH) {
                const pxY = (matchH.y_mm / pdfHeightMM) * canvas.offsetHeight;
                guideAlignH.style.top = pxY + 'px';
                guideAlignH.style.display = 'block';
                
                if (isDragging) {
                    let snappedTop = pxY;
                    if (matchH.type === 'centerY') {
                        snappedTop = pxY - (activeRect.height / 2 / pdfHeightMM) * canvas.offsetHeight;
                    } else if (matchH.type === 'bottom') {
                        snappedTop = pxY - (activeRect.height / pdfHeightMM) * canvas.offsetHeight;
                    }
                    activeEl.style.top = snappedTop + 'px';
                    const snappedYMM = (snappedTop / canvas.offsetHeight) * pdfHeightMM;
                    settings[activeTab].pos_y = parseFloat(snappedYMM.toFixed(2));
                    formInputs.pos_y.value = settings[activeTab].pos_y;
                }
            } else {
                guideAlignH.style.display = 'none';
            }
        }
        /* ===== END OF INSERTION 4A ===== */
        // Init PDF
        pdfjsLib.getDocument(pdfUrl).promise.then(pdf => {
            pdfDoc = pdf;
            renderPage(currentScale, currentRotation);
        });

        function renderPage(scale, rotation) {
            if (!pdfDoc) return;
            pdfDoc.getPage(1).then(page => {
                const viewport = page.getViewport({ scale: scale, rotation: rotation });
                canvas.width = viewport.width;
                canvas.height = viewport.height;

                pdfWidthMM = (viewport.width / scale) * (25.4 / 72);
                pdfHeightMM = (viewport.height / scale) * (25.4 / 72);

                const renderContext = { canvasContext: ctx, viewport: viewport };
                page.render(renderContext).promise.then(() => {
                    updateAllElementStyles();
                });
            });
        }

        function rgbToHex(r, g, b) { return "#" + (1 << 24 | r << 16 | g << 8 | b).toString(16).slice(1).toUpperCase(); }
        function parseColorToHex(str) {
            if (str.startsWith('#')) return str.substring(0, 7);
            const parts = str.split(',');
            if (parts.length === 3) return rgbToHex(parseInt(parts[0]), parseInt(parts[1]), parseInt(parts[2]));
            return '#000000';
        }

        // Apply styles to a specific element box based on its settings
        function applyStyleToElement(key) {
            const el = document.getElementById('el_' + key);
            const s = settings[key];
            
            if (!s.enabled) {
                el.classList.add('hidden');
                return;
            } else {
                el.classList.remove('hidden');
            }

            // Position
            let pxX = (parseFloat(s.pos_x) / pdfWidthMM) * canvas.offsetWidth;
            let pxY = (parseFloat(s.pos_y) / pdfHeightMM) * canvas.offsetHeight;
            el.style.left = pxX + 'px';
            el.style.top = pxY + 'px';

            // Font or Size
            const docHeightPt = (pdfHeightMM / 25.4) * 72;
            if (key === 'qrcode') {
                const pxSize = (s.font_size / pdfHeightMM) * canvas.offsetHeight;
                el.style.width = pxSize + 'px';
                el.style.height = pxSize + 'px';
                el.style.border = '1px dashed #ccc';
                el.style.fontSize = '0px';
                el.style.color = 'transparent';
                
                let colorStr = (s.text_color || '0,0,0').trim();
                let cssColor = colorStr.startsWith('#') ? colorStr : `rgb(${colorStr})`;
                el.style.background = 'none';
                el.style.backgroundColor = cssColor;
                el.style.webkitMask = "url('https://upload.wikimedia.org/wikipedia/commons/d/d0/QR_code_for_mobile_English_Wikipedia.svg') no-repeat center";
                el.style.webkitMaskSize = '100% 100%';
            } else {
                el.style.fontSize = (s.font_size / docHeightPt * canvas.offsetHeight) + 'px';
                let colorStr = s.text_color.trim();
                el.style.color = colorStr.startsWith('#') ? colorStr : `rgb(${colorStr})`;
            }
            
            // Alignment and Box Width
            let boxWidthMM = parseFloat(s.box_width) || 0;
            if (boxWidthMM > 0 && key !== 'qrcode') {
                let pxWidth = (boxWidthMM / pdfWidthMM) * canvas.offsetWidth;
                el.style.width = pxWidth + 'px';
                el.style.whiteSpace = 'normal';
                
                // If bounding box is used, X/Y is ALWAYS the top-left corner of the box,
                // and CSS textAlign handles the text alignment natively inside it.
                el.style.textAlign = s.text_align === 'C' ? 'center' : (s.text_align === 'R' ? 'right' : 'left');
                el.style.transform = 'none';
                el.style.transformOrigin = 'top left';
            } else if (key !== 'qrcode') {
                el.style.width = 'auto';
                el.style.whiteSpace = 'nowrap';
                
                // Legacy offset-based alignment
                if (s.text_align === 'C') {
                    el.style.textAlign = 'center';
                    el.style.transform = 'translateX(-50%)';
                    el.style.transformOrigin = 'center left';
                } else if (s.text_align === 'R') {
                    el.style.textAlign = 'right';
                    el.style.transform = 'translateX(-100%)';
                    el.style.transformOrigin = 'top right';
                } else {
                    el.style.textAlign = 'left';
                    el.style.transform = 'none';
                }
            }

            // Sync dynamic font on the canvas
            const dynamicStyle = document.getElementById('preview-font-' + key);
            if (s.font_file) {
                const styleHtml = `
                    @font-face { font-family: 'PreviewFont_${key}'; src: url('../uploads/fonts/${s.font_file}') format('truetype'); }
                    #el_${key} { font-family: 'PreviewFont_${key}', sans-serif !important; }
                `;
                if (!dynamicStyle) {
                    const style = document.createElement('style');
                    style.id = 'preview-font-' + key;
                    document.head.appendChild(style);
                    style.innerHTML = styleHtml;
                } else {
                    dynamicStyle.innerHTML = styleHtml;
                }
            } else {
                if (dynamicStyle) {
                    dynamicStyle.remove();
                }
            }
        }

        function updateAllElementStyles() {
            ['name', 'certid', 'date', 'qrcode', 'custom_text'].forEach(applyStyleToElement);
        }

        const formInputs = {
            enabled: document.getElementById('field_enabled'),
            pos_x: document.getElementById('pos_x'),
            pos_y: document.getElementById('pos_y'),
            font_size: document.getElementById('font_size'),
            box_width: document.getElementById('box_width'),
            text_color: document.getElementById('text_color'),
            text_align: document.getElementById('text_align'),
            font_file: document.getElementById('existing_font'),
            color_picker: document.getElementById('color_picker'),
            file_proxy: document.getElementById('font_file_input'),
            date_format: document.getElementById('date_format'),
            sample_text: document.getElementById('sample_text_input')
        };

        function updateDatePreview() {
            const format = settings['date'].date_format || 'F j, Y';
            const d = new Date();
            const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            let txt = '';
            switch(format) {
                case 'Y-m-d': txt = d.getFullYear() + '-' + ('0'+(d.getMonth()+1)).slice(-2) + '-' + ('0'+d.getDate()).slice(-2); break;
                case 'd/m/Y': txt = ('0'+d.getDate()).slice(-2) + '/' + ('0'+(d.getMonth()+1)).slice(-2) + '/' + d.getFullYear(); break;
                case 'm/d/Y': txt = ('0'+(d.getMonth()+1)).slice(-2) + '/' + ('0'+d.getDate()).slice(-2) + '/' + d.getFullYear(); break;
                case 'j F Y': txt = d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear(); break;
                case 'F j, Y':
                default:
                    txt = months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear(); break;
            }
            document.getElementById('el_date').innerText = txt;
        }

        // Load active tab settings into form
        function loadSettingsIntoForm() {
            const s = settings[activeTab];
            formInputs.enabled.checked = s.enabled;
            formInputs.pos_x.value = s.pos_x;
            formInputs.pos_y.value = s.pos_y;
            formInputs.font_size.value = s.font_size;
            formInputs.text_color.value = s.text_color;
            formInputs.color_picker.value = parseColorToHex(s.text_color);
            formInputs.text_align.value = s.text_align;
            formInputs.font_file.value = s.font_file;
            document.getElementById('lbl_current_tab').innerText = activeTab.toUpperCase();
            
            // Sync segmented control buttons active state
            document.querySelectorAll('.segment-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.align === s.text_align);
            });
            
            if (activeTab === 'name' || activeTab === 'certid' || activeTab === 'custom_text') {
                document.getElementById('sample_text_group').style.display = 'block';
                formInputs.sample_text.value = document.getElementById('el_' + activeTab).innerText;
            } else {
                document.getElementById('sample_text_group').style.display = 'none';
            }

            if (activeTab === 'date') {
                document.getElementById('date_format_group').style.display = 'block';
                formInputs.date_format.value = s.date_format || 'F j, Y';
            } else {
                document.getElementById('date_format_group').style.display = 'none';
            }
            
            if (activeTab === 'qrcode') {
                document.getElementById('group_color').style.display = 'block';
                document.getElementById('group_align').style.display = 'none';
                document.getElementById('group_font').style.display = 'none';
                document.getElementById('font_upload_group').style.display = 'none';
                document.getElementById('group_box_width').style.display = 'none';
            } else {
                document.getElementById('group_color').style.display = 'block';
                document.getElementById('group_align').style.display = 'block';
                document.getElementById('group_font').style.display = 'block';
                document.getElementById('font_upload_group').style.display = 'block';
                document.getElementById('group_box_width').style.display = 'block';
                formInputs.box_width.value = s.box_width || 0;
            }
            
            // Clear proxy input
            formInputs.file_proxy.value = '';
            
            // Manage active element state classes
            ['name', 'certid', 'date', 'qrcode', 'custom_text'].forEach(k => {
                const elementBox = document.getElementById('el_' + k);
                elementBox.classList.toggle('active', k === activeTab);
                elementBox.classList.toggle('locked', lockedStates[k] === true);
            });
            
            // Refresh layers panel listing
            renderLayersPanel();
        }

        // Selection Switching Helper
        function selectElement(key) {
            if (activeTab === key) return;
            activeTab = key;
            loadSettingsIntoForm();
            updateElementGuides();
        }

        // Per-element default font names (used when reverting to Default Font)
        const elementDefaultFonts = {
            name: 'alexbrush',
            certid: 'helvetica',
            date: 'helvetica',
            qrcode: '',
            custom_text: 'helvetica'
        };

        // Form Event Listeners to update JSON state immediately
        const syncState = () => {
            const s = settings[activeTab];
            s.enabled = formInputs.enabled.checked;
            s.font_size = parseFloat(formInputs.font_size.value) || 12;
            s.text_color = formInputs.text_color.value;
            s.text_align = formInputs.text_align.value;
            // Update font_file and keep font_name in sync
            const selectedFontFile = formInputs.font_file.value;
            s.font_file = selectedFontFile;
            s.font_name = selectedFontFile ? 'custom' : (elementDefaultFonts[activeTab] || 'helvetica');
            if (activeTab !== 'qrcode') {
                s.box_width = parseFloat(formInputs.box_width.value) || 0;
            }
            if (activeTab === 'date') {
                s.date_format = formInputs.date_format.value;
                updateDatePreview();
            }
            applyStyleToElement(activeTab);
            
            /* ===== START OF INSERTION 4C: UPDATE GUIDES ON FORM CHANGE ===== */
            updateElementGuides();
            /* ===== END OF INSERTION 4C ===== */
            // Update layers panel after state change
            renderLayersPanel();
        };

        formInputs.enabled.addEventListener('change', syncState);
        formInputs.font_size.addEventListener('input', syncState);
        formInputs.box_width.addEventListener('input', syncState);
        formInputs.text_color.addEventListener('input', (e) => {
            formInputs.color_picker.value = parseColorToHex(e.target.value);
            syncState();
        });
        formInputs.color_picker.addEventListener('input', (e) => {
            const hex = e.target.value;
            const r = parseInt(hex.substr(1, 2), 16);
            const g = parseInt(hex.substr(3, 2), 16);
            const b = parseInt(hex.substr(5, 2), 16);
            formInputs.text_color.value = `${r},${g},${b}`;
            syncState();
        });
        formInputs.text_align.addEventListener('change', syncState);
        formInputs.font_file.addEventListener('change', syncState);
        formInputs.date_format.addEventListener('change', syncState);
        
        // Segmented text alignment buttons events
        document.querySelectorAll('.segment-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.segment-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                formInputs.text_align.value = btn.dataset.align;
                syncState();
                pushState();
            });
        });

        // Preset Color Swatches click events
        document.querySelectorAll('.swatch').forEach(swatch => {
            swatch.addEventListener('click', () => {
                const hex = swatch.dataset.color;
                formInputs.color_picker.value = hex;
                const r = parseInt(hex.substr(1, 2), 16);
                const g = parseInt(hex.substr(3, 2), 16);
                const b = parseInt(hex.substr(5, 2), 16);
                formInputs.text_color.value = `${r},${g},${b}`;
                syncState();
                pushState();
            });
        });

        // Proxy file input to real file inputs
        formInputs.file_proxy.addEventListener('change', (e) => {
            const realInput = document.getElementById('real_file_' + activeTab);
            if(e.target.files.length > 0) {
                const newClone = e.target.cloneNode();
                newClone.name = 'font_file_' + activeTab;
                newClone.id = 'real_file_' + activeTab;
                newClone.style.display = 'none';
                realInput.parentNode.replaceChild(newClone, realInput);
                pushState();
            }
        });

        // Dragging Logic
        let isDragging = false;
        let dragTarget = null;
        let startX, startY, initialLeft, initialTop;

        function startDrag(e, el) {
            const targetId = el.dataset.id;
            // Prevent drag interaction if the layer is locked
            if (lockedStates[targetId] === true) {
                return;
            }
            
            // If not active tab, switch to it
            if (activeTab !== targetId) {
                selectElement(targetId);
            }
            
            isDragging = true;
            dragTarget = el;
            startX = e.clientX !== undefined ? e.clientX : e.touches[0].clientX;
            startY = e.clientY !== undefined ? e.clientY : e.touches[0].clientY;
            initialLeft = el.offsetLeft;
            initialTop = el.offsetTop;
            el.style.cursor = 'grabbing';

            // Cache dimensions and other elements' positions for high-performance dragging (no DOM thrashing)
            activeRectWidth = el.offsetWidth;
            activeRectHeight = el.offsetHeight;
            
            cachedTargetRects = [];
            const keys = ['name', 'certid', 'date', 'qrcode', 'custom_text'];
            for (const key of keys) {
                if (key === activeTab) continue;
                const targetRect = getElementRectMM(key);
                if (targetRect) {
                    cachedTargetRects.push(targetRect);
                }
            }

            // Prevent default behavior if it's a touch to prevent scrolling while dragging
            if (e.type === 'touchstart') e.preventDefault();
        }

        document.querySelectorAll('.element-box').forEach(el => {
            el.addEventListener('mousedown', (e) => startDrag(e, el));
            el.addEventListener('touchstart', (e) => startDrag(e, el), { passive: false });
        });

        function performDrag(e) {
            if (!isDragging || !dragTarget) return;

            const currentX = e.clientX !== undefined ? e.clientX : (e.touches ? e.touches[0].clientX : undefined);
            const currentY = e.clientY !== undefined ? e.clientY : (e.touches ? e.touches[0].clientY : undefined);

            if (currentX === undefined || currentY === undefined) return;
            
            // Prevent scrolling on touch devices while dragging
            if (e.type === 'touchmove') e.preventDefault();

            let dx = currentX - startX;
            let dy = currentY - startY;

            let newLeft = initialLeft + dx;
            let newTop = initialTop + dy;

            let x_mm = (newLeft / canvas.offsetWidth) * pdfWidthMM;
            let y_mm = (newTop / canvas.offsetHeight) * pdfHeightMM;

            // Grid snapping logic
            const snapInterval = getSnapInterval();
            if (snapInterval > 0) {
                x_mm = Math.round(x_mm / snapInterval) * snapInterval;
                y_mm = Math.round(y_mm / snapInterval) * snapInterval;
                
                // Adjust visually mapped left/top positions
                newLeft = (x_mm / pdfWidthMM) * canvas.offsetWidth;
                newTop = (y_mm / pdfHeightMM) * canvas.offsetHeight;
            }

            dragTarget.style.left = newLeft + 'px';
            dragTarget.style.top = newTop + 'px';

            formInputs.pos_x.value = x_mm.toFixed(2);
            formInputs.pos_y.value = y_mm.toFixed(2);
            
            settings[activeTab].pos_x = parseFloat(x_mm.toFixed(2));
            settings[activeTab].pos_y = parseFloat(y_mm.toFixed(2));
            
            // Update floating coordinate tooltip
            const tooltip = document.getElementById('drag-tooltip');
            tooltip.textContent = `X: ${x_mm.toFixed(1)}mm  Y: ${y_mm.toFixed(1)}mm`;
            tooltip.style.left = (newLeft + activeRectWidth / 2) + 'px';
            tooltip.style.top = (newTop - 28) + 'px';
            tooltip.style.display = 'block';

            /* ===== START OF INSERTION 4D: UPDATE GUIDES WHILE DRAGGING ===== */
            updateElementGuides();
            /* ===== END OF INSERTION 4D ===== */
        }

        document.addEventListener('mousemove', performDrag);
        document.addEventListener('touchmove', performDrag, { passive: false });

        function endDrag() {
            if (isDragging && dragTarget) {
                dragTarget.style.cursor = 'move';
                isDragging = false;
                dragTarget = null;
                
                // Hide floating coordinate tooltip
                document.getElementById('drag-tooltip').style.display = 'none';
                
                // Clear alignment guides on drag end
                document.getElementById('guide_align_v').style.display = 'none';
                document.getElementById('guide_align_h').style.display = 'none';
                
                // Refresh to hide red margin guides
                updateElementGuides();
                pushState();
            }
        }

        document.addEventListener('mouseup', endDrag);
        document.addEventListener('touchend', endDrag);
        document.addEventListener('touchcancel', endDrag);

        // Zoom & Rotate & Fit
        document.getElementById('tool_zoom_in').addEventListener('click', () => {
            currentScale += 0.25;
            document.getElementById('tool_zoom_val').innerText = Math.round(currentScale * 100) + '%';
            renderPage(currentScale, currentRotation);
        });
        document.getElementById('tool_zoom_out').addEventListener('click', () => {
            if (currentScale > 0.5) {
                currentScale -= 0.25;
                document.getElementById('tool_zoom_val').innerText = Math.round(currentScale * 100) + '%';
                renderPage(currentScale, currentRotation);
            }
        });
        document.getElementById('tool_zoom_fit').addEventListener('click', () => {
            const editorContainer = document.querySelector('.editor-container');
            if (!editorContainer || !pdfDoc) return;
            
            const padding = 40;
            const containerWidth = editorContainer.clientWidth - padding;
            
            // Calculate scale to fit width: 72 points per inch, 25.4 mm per inch
            const pdfWidthPx = pdfWidthMM * (72 / 25.4);
            const scale = containerWidth / pdfWidthPx;
            
            currentScale = parseFloat(scale.toFixed(2));
            document.getElementById('tool_zoom_val').innerText = Math.round(currentScale * 100) + '%';
            renderPage(currentScale, currentRotation);
        });

        // Form submission
        document.getElementById('settings-form').addEventListener('submit', () => {
            document.getElementById('visual_settings_payload').value = JSON.stringify(settings);
        });

        // Initial Load
        updateDatePreview();
        loadSettingsIntoForm();
        renderLayersPanel();
        
        /* ===== START OF INSERTION 4E: INITIAL GUIDE DRAW ===== */
        updateElementGuides();
        updateGridOverlay();
        /* ===== END OF INSERTION 4E ===== */
        
        // Hook render page hooks to refresh layers, grid and guidelines
        const originalRenderPage = renderPage;
        renderPage = function(scale, rotation) {
            if (!pdfDoc) return;
            pdfDoc.getPage(1).then(page => {
                const viewport = page.getViewport({ scale: scale, rotation: rotation });
                canvas.width = viewport.width;
                canvas.height = viewport.height;

                pdfWidthMM = (viewport.width / scale) * (25.4 / 72);
                pdfHeightMM = (viewport.height / scale) * (25.4 / 72);

                const renderContext = { canvasContext: ctx, viewport: viewport };
                page.render(renderContext).promise.then(() => {
                    updateAllElementStyles();
                    renderLayersPanel();
                    updateElementGuides();
                    updateGridOverlay();
                });
            });
        };

        formInputs.sample_text.addEventListener('input', (e) => {
            if (activeTab === 'name' || activeTab === 'certid' || activeTab === 'custom_text') {
                const el = document.getElementById('el_' + activeTab);
                el.innerText = e.target.value || (activeTab === 'name' ? 'Participant Name' : (activeTab === 'certid' ? 'CERT-1A2B3C4D' : 'Participant\'s Custom Text'));
                applyStyleToElement(activeTab);
                updateElementGuides();
            }
        });

        // Manual Position Input Fields event listeners
        const handleManualPositionInput = () => {
            const el = document.getElementById('el_' + activeTab);
            if (!el || el.classList.contains('hidden') || lockedStates[activeTab] === true) return;

            let x_mm = parseFloat(formInputs.pos_x.value);
            let y_mm = parseFloat(formInputs.pos_y.value);

            if (isNaN(x_mm)) x_mm = settings[activeTab].pos_x;
            if (isNaN(y_mm)) y_mm = settings[activeTab].pos_y;

            // Clamp within PDF canvas limits
            x_mm = Math.max(0, Math.min(pdfWidthMM, x_mm));
            y_mm = Math.max(0, Math.min(pdfHeightMM, y_mm));

            settings[activeTab].pos_x = parseFloat(x_mm.toFixed(2));
            settings[activeTab].pos_y = parseFloat(y_mm.toFixed(2));

            applyStyleToElement(activeTab);
            updateElementGuides();
        };

        formInputs.pos_x.addEventListener('input', handleManualPositionInput);
        formInputs.pos_y.addEventListener('input', handleManualPositionInput);

        formInputs.pos_x.addEventListener('blur', () => {
            formInputs.pos_x.value = settings[activeTab].pos_x.toFixed(2);
        });
        formInputs.pos_y.addEventListener('blur', () => {
            formInputs.pos_y.value = settings[activeTab].pos_y.toFixed(2);
        });

        // Keyboard Nudging (Millimeter-based snapping/precision)
        document.addEventListener('keydown', (e) => {
            if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key) && e.target.tagName !== 'INPUT' && e.target.tagName !== 'SELECT') {
                e.preventDefault();
                const el = document.getElementById('el_' + activeTab);
                if (!el || el.classList.contains('hidden')) return;
                
                // Do not nudge locked layers
                if (lockedStates[activeTab] === true) return;

                let x_mm = parseFloat(settings[activeTab].pos_x) || 0;
                let y_mm = parseFloat(settings[activeTab].pos_y) || 0;
                
                const snapInterval = getSnapInterval();
                let nudgeMM = 1;
                
                if (snapInterval > 0) {
                    nudgeMM = snapInterval;
                } else {
                    nudgeMM = e.shiftKey ? 5.0 : 0.5;
                }

                if (e.key === 'ArrowUp') y_mm -= nudgeMM;
                if (e.key === 'ArrowDown') y_mm += nudgeMM;
                if (e.key === 'ArrowLeft') x_mm -= nudgeMM;
                if (e.key === 'ArrowRight') x_mm += nudgeMM;

                if (snapInterval > 0) {
                    x_mm = Math.round(x_mm / snapInterval) * snapInterval;
                    y_mm = Math.round(y_mm / snapInterval) * snapInterval;
                }

                // Clamp within bounds
                x_mm = Math.max(0, Math.min(pdfWidthMM, x_mm));
                y_mm = Math.max(0, Math.min(pdfHeightMM, y_mm));

                x_mm = parseFloat(x_mm.toFixed(2));
                y_mm = parseFloat(y_mm.toFixed(2));

                settings[activeTab].pos_x = x_mm;
                settings[activeTab].pos_y = y_mm;
                formInputs.pos_x.value = x_mm;
                formInputs.pos_y.value = y_mm;

                applyStyleToElement(activeTab);
                /* ===== START OF INSERTION 4F: UPDATE GUIDES ON KEYBOARD NUDGE ===== */
                updateElementGuides();
                /* ===== END OF INSERTION 4F ===== */
            }
        });

        // Quick Actions Event Listeners (with locked checks)
        document.getElementById('btn_center_x').addEventListener('click', () => {
            const el = document.getElementById('el_' + activeTab);
            if (!el || el.classList.contains('hidden') || lockedStates[activeTab] === true) return;
            
            const s = settings[activeTab];
            let newX = 0;
            
            if (activeTab === 'qrcode') {
                const qrSize = parseFloat(s.font_size) || 30;
                newX = (pdfWidthMM - qrSize) / 2;
            } else {
                const boxWidth = parseFloat(s.box_width) || 0;
                if (boxWidth > 0) {
                    newX = (pdfWidthMM - boxWidth) / 2;
                } else {
                    if (s.text_align === 'C') {
                        newX = pdfWidthMM / 2;
                    } else {
                        const w_mm = (el.offsetWidth / canvas.offsetWidth) * pdfWidthMM;
                        newX = (pdfWidthMM - w_mm) / 2;
                    }
                }
            }
            
            newX = parseFloat(newX.toFixed(2));
            s.pos_x = newX;
            formInputs.pos_x.value = newX;
            
            applyStyleToElement(activeTab);
            updateElementGuides();
            pushState();
        });

        document.getElementById('btn_center_y').addEventListener('click', () => {
            const el = document.getElementById('el_' + activeTab);
            if (!el || el.classList.contains('hidden') || lockedStates[activeTab] === true) return;
            
            const s = settings[activeTab];
            let newY = 0;
            
            if (activeTab === 'qrcode') {
                const qrSize = parseFloat(s.font_size) || 30;
                newY = (pdfHeightMM - qrSize) / 2;
            } else {
                const h_mm = (el.offsetHeight / canvas.offsetHeight) * pdfHeightMM;
                newY = (pdfHeightMM - h_mm) / 2;
            }
            
            newY = parseFloat(newY.toFixed(2));
            s.pos_y = newY;
            formInputs.pos_y.value = newY;
            
            applyStyleToElement(activeTab);
            updateElementGuides();
            pushState();
        });

        // ===== UNDO / REDO HISTORY SYSTEM =====
        const undoStack = [];
        const redoStack = [];

        function getAppStateClone() {
            return {
                settings: JSON.parse(JSON.stringify(settings)),
                lockedStates: JSON.parse(JSON.stringify(lockedStates)),
                activeTab: activeTab
            };
        }

        function pushState() {
            const clone = getAppStateClone();
            if (undoStack.length > 0) {
                const prev = undoStack[undoStack.length - 1];
                if (JSON.stringify(prev.settings) === JSON.stringify(clone.settings) &&
                    JSON.stringify(prev.lockedStates) === JSON.stringify(clone.lockedStates) &&
                    prev.activeTab === clone.activeTab) {
                    return;
                }
            }
            undoStack.push(clone);
            if (undoStack.length > 50) {
                undoStack.shift();
            }
            redoStack.length = 0;
            updateUndoRedoButtons();
        }

        function undo() {
            if (undoStack.length <= 1) return;
            const current = undoStack.pop();
            redoStack.push(current);
            const prevState = undoStack[undoStack.length - 1];
            restoreAppState(prevState);
        }

        function redo() {
            if (redoStack.length === 0) return;
            const nextState = redoStack.pop();
            undoStack.push(nextState);
            restoreAppState(nextState);
        }

        function restoreAppState(state) {
            for (const key in state.settings) {
                settings[key] = JSON.parse(JSON.stringify(state.settings[key]));
            }
            for (const key in state.lockedStates) {
                lockedStates[key] = state.lockedStates[key];
            }
            activeTab = state.activeTab;
            loadSettingsIntoForm();
            updateAllElementStyles();
            updateElementGuides();
            updateUndoRedoButtons();
        }

        function updateUndoRedoButtons() {
            const btnUndo = document.getElementById('tool_undo');
            const btnRedo = document.getElementById('tool_redo');
            if (btnUndo) {
                btnUndo.disabled = undoStack.length <= 1;
                btnUndo.style.opacity = (undoStack.length <= 1) ? '0.4' : '1';
            }
            if (btnRedo) {
                btnRedo.disabled = redoStack.length === 0;
                btnRedo.style.opacity = (redoStack.length === 0) ? '0.4' : '1';
            }
        }

        // Initialize first state
        setTimeout(() => {
            pushState();
        }, 100);

        // Bind Undo/Redo buttons
        document.getElementById('tool_undo').addEventListener('click', undo);
        document.getElementById('tool_redo').addEventListener('click', redo);

        // Bind change events to push state
        formInputs.enabled.addEventListener('change', pushState);
        formInputs.font_size.addEventListener('change', pushState);
        formInputs.box_width.addEventListener('change', pushState);
        formInputs.text_color.addEventListener('change', pushState);
        formInputs.color_picker.addEventListener('change', pushState);
        formInputs.text_align.addEventListener('change', pushState);
        formInputs.font_file.addEventListener('change', pushState);
        formInputs.date_format.addEventListener('change', pushState);
        formInputs.pos_x.addEventListener('change', pushState);
        formInputs.pos_y.addEventListener('change', pushState);

        // ===== KEYBOARD SHORTCUTS INTEGRATION =====
        const elementKeys = ['name', 'certid', 'date', 'qrcode', 'custom_text'];
        
        document.addEventListener('keydown', (e) => {
            const isTyping = e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA';
            
            // 1. Undo/Redo Shortcuts (Active even when typing)
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {
                e.preventDefault();
                undo();
                return;
            }
            if ((e.ctrlKey || e.metaKey) && (e.key.toLowerCase() === 'y' || (e.shiftKey && e.key.toLowerCase() === 'z'))) {
                e.preventDefault();
                redo();
                return;
            }

            // Other shortcuts only activate when NOT editing inputs
            if (isTyping) return;

            // 2. Element Selection Cycling (Tab / Shift+Tab)
            if (e.key === 'Tab') {
                e.preventDefault();
                const currentIndex = elementKeys.indexOf(activeTab);
                let nextIndex = 0;
                if (e.shiftKey) {
                    nextIndex = (currentIndex - 1 + elementKeys.length) % elementKeys.length;
                } else {
                    nextIndex = (currentIndex + 1) % elementKeys.length;
                }
                selectElement(elementKeys[nextIndex]);
                return;
            }

            // 3. Lock Active Element (L)
            if (e.key.toLowerCase() === 'l') {
                e.preventDefault();
                lockedStates[activeTab] = !lockedStates[activeTab];
                const targetEl = document.getElementById('el_' + activeTab);
                if (lockedStates[activeTab]) {
                    targetEl.classList.add('locked');
                } else {
                    targetEl.classList.remove('locked');
                }
                updateElementGuides();
                renderLayersPanel();
                pushState();
                return;
            }

            // 4. Toggle Active Element Visibility (V)
            if (e.key.toLowerCase() === 'v') {
                e.preventDefault();
                const isHidden = !settings[activeTab].enabled || document.getElementById('el_' + activeTab).classList.contains('hidden');
                settings[activeTab].enabled = isHidden ? 1 : 0;
                formInputs.enabled.checked = isHidden;
                
                const targetEl = document.getElementById('el_' + activeTab);
                if (isHidden) {
                    targetEl.classList.remove('hidden');
                } else {
                    targetEl.classList.add('hidden');
                }
                
                applyStyleToElement(activeTab);
                updateElementGuides();
                renderLayersPanel();
                pushState();
                return;
            }

            // 5. Center Horizontally (C)
            if (e.key.toLowerCase() === 'c') {
                e.preventDefault();
                document.getElementById('btn_center_x').click();
                return;
            }
        });

        // ===== MODALS CONTROLLERS =====
        const helpModal = document.getElementById('help-guide-modal');
        const pdfModal = document.getElementById('pdf-preview-modal');
        const pdfIframe = document.getElementById('pdf-preview-iframe');

        // Help modal trigger
        document.getElementById('tool_help').addEventListener('click', () => {
            helpModal.style.display = 'flex';
        });
        document.getElementById('close-help-guide').addEventListener('click', () => {
            helpModal.style.display = 'none';
        });

        // Close help modal on backdrop click
        helpModal.addEventListener('click', (e) => {
            if (e.target === helpModal) helpModal.style.display = 'none';
        });

        // Live PDF preview trigger
        document.getElementById('tool_pdf_preview').addEventListener('click', () => {
            const roleId = <?= json_encode($role['id']) ?>;
            const rotation = document.getElementById('rotation').value;
            const settingsPayload = encodeURIComponent(JSON.stringify(settings));
            
            // Gather current sample texts from visual elements
            const customTexts = {
                name: document.getElementById('el_name').innerText,
                certid: document.getElementById('el_certid').innerText,
                date: document.getElementById('el_date').innerText,
                custom_text: document.getElementById('el_custom_text').innerText
            };
            const textsPayload = encodeURIComponent(JSON.stringify(customTexts));
            
            pdfIframe.src = `preview_pdf.php?role_id=${roleId}&rotation=${rotation}&settings=${settingsPayload}&custom_texts=${textsPayload}`;
            pdfModal.style.display = 'flex';
        });
        document.getElementById('close-pdf-preview').addEventListener('click', () => {
            pdfModal.style.display = 'none';
            pdfIframe.src = 'about:blank';
        });

        // Close PDF preview modal on backdrop click
        pdfModal.addEventListener('click', (e) => {
            if (e.target === pdfModal) {
                pdfModal.style.display = 'none';
                pdfIframe.src = 'about:blank';
            }
        });

    </script>
</body>
</html>
