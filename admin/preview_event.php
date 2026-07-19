<?php
session_start();
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
        .tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; border-bottom: 2px solid #eaedf1; padding-bottom: 10px; }
        .tab { flex: 1; min-width: 65px; text-align: center; padding: 8px 10px; background: #f4f5f7; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 600; color: #555; white-space: nowrap; }
        .tab.active { background: var(--primary-color); color: white; }
        .element-box { position: absolute; white-space: nowrap; cursor: move; border: 1px dashed transparent; padding: 2px 5px; user-select: none; line-height: 1; }
        .element-box.active { border-color: #007bff; background: rgba(0, 123, 255, 0.1); z-index: 10; }
        .element-box.hidden { display: none; }
        /* ===== START OF INSERTION 1: GRID & GUIDE CSS ===== */
        #grid-overlay { 
            position: absolute; top: 0; left: 0; width: 100%; height: 100%; 
            pointer-events: none; z-index: 5; display: none;
            background-image: 
                linear-gradient(rgba(200, 200, 200, 0.4) 1px, transparent 1px), 
                linear-gradient(90deg, rgba(200, 200, 200, 0.4) 1px, transparent 1px);
            background-size: 20px 20px; 
        }
        .guide-line { position: absolute; pointer-events: none; z-index: 9; }
        .guide-center-v { left: 50%; top: 0; bottom: 0; border-left: 1.5px dashed #9933ff; opacity: 0.7; display: none; }
        .guide-center-h { top: 50%; left: 0; right: 0; border-top: 1.5px dashed #9933ff; opacity: 0.7; display: none; }
        .guide-element-v { top: 0; bottom: 0; border-left: 1.5px dashed #00beff; opacity: 0.8; display: none; }
        .guide-element-h { left: 0; right: 0; border-top: 1.5px dashed #00beff; opacity: 0.8; display: none; }
        .guide-align-v { top: 0; bottom: 0; border-left: 1.5px dashed #ff5722; opacity: 0.8; display: none; }
        .guide-align-h { left: 0; right: 0; border-top: 1.5px dashed #ff5722; opacity: 0.8; display: none; }
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
                <button type="button" id="tool_zoom_out" title="Zoom Out"><svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M19 13H5v-2h14v2z" /></svg></button>
                <button type="button" id="tool_zoom_in" title="Zoom In"><svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" /></svg></button>
                <span class="divider">|</span>
                <span class="zoom-val" id="tool_zoom_val">100%</span>
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
                    <select id="snap_interval" style="width: auto; padding: 4px; font-weight: bold; border-radius: 4px; background: white; border: 1.5px solid #cbd5e1; height: auto;">
                        <option value="0">None</option>
                        <option value="1">1mm</option>
                        <option value="2">2mm</option>
                        <option value="5" selected>5mm</option>
                        <option value="10">10mm</option>
                    </select>
                </div>
            </div>
            <!-- ===== END OF INSERTION 3 ===== -->
            <div class="tabs">
                <div class="tab active" data-target="name">Name</div>
                <div class="tab" data-target="certid">Cert ID</div>
                <div class="tab" data-target="date">Issue Date</div>
                <div class="tab" data-target="qrcode">QR Code</div>
                <div class="tab" data-target="custom_text">Custom Text</div>
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

                <div class="form-group">
                    <label>X Position (mm)</label>
                    <input type="text" id="pos_x" readonly style="background:#eee;">
                </div>
                <div class="form-group">
                    <label>Y Position (mm)</label>
                    <input type="text" id="pos_y" readonly style="background:#eee;">
                </div>

                <div class="form-group">
                    <label>Size (pt for text, mm for QR)</label>
                    <input type="number" id="font_size">
                </div>

                <div class="form-group" id="group_box_width">
                    <label>Max Width (mm) <span style="font-size: 11px; font-weight: normal; color: #777;">(0 = unlimited)</span></label>
                    <input type="number" id="box_width" step="1">
                </div>

                <div class="form-group" id="group_color">
                    <label>Text Color (HEX/RGB)</label>
                    <div style="display: flex; gap: 5px;">
                        <input type="color" id="color_picker" style="width: 50px; padding: 2px; cursor: pointer; height: 35px;">
                        <input type="text" id="text_color" placeholder="e.g. 0,0,0">
                    </div>
                </div>

                <div class="form-group" id="group_align">
                    <label>Text Alignment</label>
                    <select id="text_align">
                        <option value="L">Left</option>
                        <option value="C">Center</option>
                        <option value="R">Right</option>
                    </select>
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

    <script>
        const pdfUrl = '../uploads/templates/<?= $role['template_file'] ?>';
        const canvas = document.getElementById('pdf-canvas');
        const ctx = canvas.getContext('2d');
        const container = document.getElementById('pdf-container');

        // State
        const settings = <?= json_encode($visualSettings) ?>;
        let activeTab = 'name';
        
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
        // Listen for the grid toggle checkbox
        document.getElementById('toggle_grid').addEventListener('change', function() {
            const displayStyle = this.checked ? 'block' : 'none';
            document.getElementById('grid-overlay').style.display = displayStyle;
            document.querySelectorAll('.guide-center-v, .guide-center-h').forEach(el => {
                el.style.display = displayStyle;
            });
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
            
            // Manage classes
            ['name', 'certid', 'date', 'qrcode', 'custom_text'].forEach(k => {
                document.getElementById('el_' + k).classList.toggle('active', k === activeTab);
            });
        }

        // Tab Switching
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                activeTab = tab.dataset.target;
                loadSettingsIntoForm();
                /* ===== START OF INSERTION 4B: UPDATE GUIDES ON TAB SWITCH ===== */
                updateElementGuides();
                /* ===== END OF INSERTION 4B ===== */
            });
        });

        // Form Event Listeners to update JSON state immediately
        const syncState = () => {
            const s = settings[activeTab];
            s.enabled = formInputs.enabled.checked;
            s.font_size = parseFloat(formInputs.font_size.value) || 12;
            s.text_color = formInputs.text_color.value;
            s.text_align = formInputs.text_align.value;
            s.font_file = formInputs.font_file.value;
            if (activeTab !== 'qrcode') {
                s.box_width = parseFloat(formInputs.box_width.value) || 0;
            }
            if (activeTab === 'date') {
                s.date_format = formInputs.date_format.value;
                updateDatePreview();
            }
            applyStyleToElement(activeTab);
            
            // Dynamic Font Preview updating
            if(s.font_file) {
                let dynamicStyle = document.getElementById('preview-font-' + activeTab);
                if (!dynamicStyle) {
                    dynamicStyle = document.createElement('style');
                    dynamicStyle.id = 'preview-font-' + activeTab;
                    document.head.appendChild(dynamicStyle);
                }
                dynamicStyle.innerHTML = `
                    @font-face { font-family: 'PreviewFont_${activeTab}'; src: url('../uploads/fonts/${s.font_file}') format('truetype'); }
                    #el_${activeTab} { font-family: 'PreviewFont_${activeTab}', sans-serif !important; }
                `;
            }
            /* ===== START OF INSERTION 4C: UPDATE GUIDES ON FORM CHANGE ===== */
            updateElementGuides();
            /* ===== END OF INSERTION 4C ===== */
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
        
        // Proxy file input to real file inputs
        formInputs.file_proxy.addEventListener('change', (e) => {
            const realInput = document.getElementById('real_file_' + activeTab);
            if(e.target.files.length > 0) {
                // A bit of a hack: HTML5 doesn't easily let you transfer FileList objects between inputs due to security.
                // We'll just append a hidden input for form submission
                const newClone = e.target.cloneNode();
                newClone.name = 'font_file_' + activeTab;
                newClone.id = 'real_file_' + activeTab;
                newClone.style.display = 'none';
                realInput.parentNode.replaceChild(newClone, realInput);
            }
        });
        // Dragging Logic
        let isDragging = false;
        let dragTarget = null;
        let startX, startY, initialLeft, initialTop;

        function startDrag(e, el) {
            // If not active tab, switch to it
            if (activeTab !== el.dataset.id) {
                document.querySelector(`.tab[data-target="${el.dataset.id}"]`).click();
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
            const snapInterval = parseFloat(document.getElementById('snap_interval').value) || 0;
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
                
                // Clear alignment guides on drag end
                document.getElementById('guide_align_v').style.display = 'none';
                document.getElementById('guide_align_h').style.display = 'none';
            }
        }

        document.addEventListener('mouseup', endDrag);
        document.addEventListener('touchend', endDrag);
        document.addEventListener('touchcancel', endDrag);

        // Zoom & Rotate
        document.getElementById('tool_zoom_in').addEventListener('click', () => {
            currentScale += 0.25;
            document.getElementById('tool_zoom_val').innerText = (currentScale * 100) + '%';
            renderPage(currentScale, currentRotation);
        });
        document.getElementById('tool_zoom_out').addEventListener('click', () => {
            if (currentScale > 0.5) {
                currentScale -= 0.25;
                document.getElementById('tool_zoom_val').innerText = (currentScale * 100) + '%';
                renderPage(currentScale, currentRotation);
            }
        });

        // Form submission
        document.getElementById('settings-form').addEventListener('submit', () => {
            document.getElementById('visual_settings_payload').value = JSON.stringify(settings);
        });

        // Initial Load
        updateDatePreview();
        loadSettingsIntoForm();
/* ===== START OF INSERTION 4E: INITIAL GUIDE DRAW ===== */
        updateElementGuides();
        /* ===== END OF INSERTION 4E ===== */
        formInputs.sample_text.addEventListener('input', (e) => {
            if (activeTab === 'name' || activeTab === 'certid' || activeTab === 'custom_text') {
                const el = document.getElementById('el_' + activeTab);
                el.innerText = e.target.value || (activeTab === 'name' ? 'Participant Name' : (activeTab === 'certid' ? 'CERT-1A2B3C4D' : 'Participant\'s Custom Text'));
                applyStyleToElement(activeTab);
            }
        });

        // Keyboard Nudging (Millimeter-based snapping/precision)
        document.addEventListener('keydown', (e) => {
            if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key) && e.target.tagName !== 'INPUT' && e.target.tagName !== 'SELECT') {
                e.preventDefault();
                const el = document.getElementById('el_' + activeTab);
                if (!el || el.classList.contains('hidden')) return;

                let x_mm = parseFloat(settings[activeTab].pos_x) || 0;
                let y_mm = parseFloat(settings[activeTab].pos_y) || 0;
                
                const snapInterval = parseFloat(document.getElementById('snap_interval').value) || 0;
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

        // Quick Actions Event Listeners
        document.getElementById('btn_center_x').addEventListener('click', () => {
            const el = document.getElementById('el_' + activeTab);
            if (!el || el.classList.contains('hidden')) return;
            
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
        });

        document.getElementById('btn_center_y').addEventListener('click', () => {
            const el = document.getElementById('el_' + activeTab);
            if (!el || el.classList.contains('hidden')) return;
            
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
        });

    </script>
</body>
</html>
