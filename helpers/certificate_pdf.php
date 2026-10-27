<?php
/**
 * Certificate PDF rendering, shared by download.php (single certificate) and
 * admin/download_certificates_zip.php (bulk export for organizers).
 */

if (!defined('K_PATH_FONTS')) {
    define('K_PATH_FONTS', dirname(__DIR__) . '/vendor/tecnickcom/tcpdf/fonts/');
}

if (!function_exists('certificate_fetch_data')) {
    /**
     * Loads everything needed to render one certificate, or null if the ID is unknown.
     *
     * @param PDO    $pdo
     * @param string $certId
     * @return array|null
     */
    function certificate_fetch_data(PDO $pdo, string $certId): ?array {
        $stmt = $pdo->prepare("
            SELECT ep.*, p.full_name, p.email, e.name as event_name, e.certificate_issue_date, er.template_file, er.visual_settings, er.rotation
            FROM event_participants ep
            JOIN participants p ON ep.participant_id = p.id
            JOIN events e ON ep.event_id = e.id
            JOIN event_roles er ON ep.role_id = er.id
            WHERE ep.certificate_id = ?
        ");
        $stmt->execute([$certId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

if (!function_exists('certificate_pdf_filename')) {
    /**
     * Download filename for a single certificate.
     * sanitizeForFilename() lives in helpers.php so download.php and send-email.php stay consistent.
     */
    function certificate_pdf_filename(array $certData): string {
        $safeFullName = sanitizeForFilename($certData['full_name']);
        $safeEventName = sanitizeForFilename($certData['event_name']);
        return "{$safeFullName} - {$safeEventName} - Certificate.pdf";
    }
}

if (!function_exists('certificate_build_pdf')) {
    /**
     * Renders a certificate onto its role template.
     *
     * @param array  $certData Row from certificate_fetch_data()
     * @param string $certId
     * @return \setasign\Fpdi\Tcpdf\Fpdi
     * @throws RuntimeException with message 'no-template' if the template PDF is missing
     */
    function certificate_build_pdf(array $certData, string $certId): \setasign\Fpdi\Tcpdf\Fpdi {
        $fullName = $certData['full_name'];
        $visualSettingsStr = !empty($certData['visual_settings']) ? $certData['visual_settings'] : '{}';
        $visualSettings = json_decode($visualSettingsStr, true);
        $rotation = (int)($certData['rotation'] ?? 0);

        $dateFormat = 'F j, Y';
        if (isset($visualSettings['date']['date_format'])) {
            $dateFormat = $visualSettings['date']['date_format'];
        }
        // Date priority: participant-specific issue_date > event-level certificate_issue_date > created_at fallback
        if (!empty($certData['issue_date'])) {
            $issueSource = $certData['issue_date'];
        } elseif (!empty($certData['certificate_issue_date'])) {
            $issueSource = $certData['certificate_issue_date'];
        } else {
            $issueSource = $certData['created_at'];
        }
        $issueDate = date($dateFormat, strtotime($issueSource));

        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->setCellPaddings(0, 0, 0, 0);

        // Load Template to determine size
        $templatePath = dirname(__DIR__) . '/uploads/templates/' . $certData['template_file'];
        if (!file_exists($templatePath)) {
            throw new RuntimeException('no-template');
        }
        $pdf->setSourceFile($templatePath);
        $tplIdx = $pdf->importPage(1);
        $size = $pdf->getTemplateSize($tplIdx);

        $w = $size['width'];
        $h = $size['height'];

        if ($rotation == 90 || $rotation == 270) {
            $w = $size['height'];
            $h = $size['width'];
        }

        $orientation = ($w > $h) ? 'L' : 'P';
        $pdf->AddPage($orientation, [$w, $h]);

        // Apply rotation to the template if needed
        if ($rotation != 0) {
            $pdf->StartTransform();
            $pdf->Rotate(-$rotation, $w / 2, $h / 2);
            $pdf->useTemplate($tplIdx, ($w / 2) - ($size['width'] / 2), ($h / 2) - ($size['height'] / 2), $size['width'], $size['height']);
            $pdf->StopTransform();
        } else {
            $pdf->useTemplate($tplIdx, 0, 0, $w, $h);
        }

        // Calculate verification URL early for hyperlinks
        $verifyUrl = adminPortalBaseUrl() . '/verify/' . $certId;

        // Render the elements and QR code
        if (is_array($visualSettings)) {
            if (isset($visualSettings['name'])) {
                certificate_render_element($pdf, $visualSettings['name'], $fullName);
            }
            if (isset($visualSettings['certid'])) {
                certificate_render_element($pdf, $visualSettings['certid'], $certId, $verifyUrl);
            }
            if (isset($visualSettings['date'])) {
                certificate_render_element($pdf, $visualSettings['date'], $issueDate);
            }
            if (isset($visualSettings['custom_text']) && !empty($certData['custom_certificate_text'])) {
                certificate_render_element($pdf, $visualSettings['custom_text'], $certData['custom_certificate_text']);
            }
            if (isset($visualSettings['qrcode']) && !empty($visualSettings['qrcode']['enabled'])) {

                $qr = $visualSettings['qrcode'];
                $qx = (float)$qr['pos_x'];
                $qy = (float)$qr['pos_y'];
                $qsize = (float)($qr['font_size'] ?? 30);

                $qcolorStr = $qr['text_color'] ?? '0,0,0';
                $qcolorArr = explode(',', $qcolorStr);
                if (count($qcolorArr) === 3) {
                    $fgColor = array((int)$qcolorArr[0], (int)$qcolorArr[1], (int)$qcolorArr[2]);
                } else {
                    $fgColor = array(0,0,0);
                }

                $style = array(
                    'border' => 0,
                    'padding' => 0,
                    'fgcolor' => $fgColor,
                    'bgcolor' => false, //transparent
                );
                $pdf->write2DBarcode($verifyUrl, 'QRCODE,L', $qx, $qy, $qsize, $qsize, $style, 'N');
                $pdf->Link($qx, $qy, $qsize, $qsize, $verifyUrl);
            }
        }

        return $pdf;
    }
}

if (!function_exists('certificate_render_element')) {
    // Function to render text element
    function certificate_render_element($pdf, $settings, $text, $linkUrl = '') {
        if (!isset($settings['enabled']) || !$settings['enabled']) return;

        $fontName = $settings['font_name'] ?? 'helvetica';
        // 'custom' means a user-uploaded TTF file should be used exclusively via font_file
        if ($fontName === 'custom') {
            $fontName = 'helvetica'; // safe fallback if font_file fails to load below
        }
        $coreFonts = ['helvetica', 'helveticab', 'helveticai', 'helveticabi',
                      'times', 'timesb', 'timesi', 'timesbi',
                      'courier', 'courierb', 'courieri', 'courierbi',
                      'symbol', 'zapfdingbats'];
        $fontLoaded = false;

        if (!empty($settings['font_file'])) {
            $fontPath = dirname(__DIR__) . '/uploads/fonts/' . $settings['font_file'];
            if (file_exists($fontPath)) {
                $compiledFont = TCPDF_FONTS::addTTFfont($fontPath, 'TrueTypeUnicode', '', 96);
                if ($compiledFont !== false) {
                    $fontName = $compiledFont;
                    $fontLoaded = true;
                }
            }
        }

        $fontFileExists = false;
        if (defined('K_PATH_FONTS')) {
            $fontFileExists = file_exists(K_PATH_FONTS . strtolower($fontName) . '.php');
        }
        if (!$fontLoaded && !in_array(strtolower($fontName), $coreFonts) && !$fontFileExists) {
            $fontName = 'helvetica';
        }

        $fontSize = $settings['font_size'] ?? 12;
        $pdf->SetFont($fontName, '', $fontSize);

        $colorStr = $settings['text_color'] ?? '0,0,0';
        if (strpos($colorStr, '#') === 0) {
            $hex = ltrim($colorStr, '#');
            if (strlen($hex) == 3) {
                $r = hexdec(str_repeat(substr($hex, 0, 1), 2));
                $g = hexdec(str_repeat(substr($hex, 1, 1), 2));
                $b = hexdec(str_repeat(substr($hex, 2, 1), 2));
            } else {
                $r = hexdec(substr($hex, 0, 2));
                $g = hexdec(substr($hex, 2, 2));
                $b = hexdec(substr($hex, 4, 2));
            }
        } else {
            $parts = explode(',', $colorStr);
            $r = (int)($parts[0] ?? 0);
            $g = (int)($parts[1] ?? 0);
            $b = (int)($parts[2] ?? 0);
        }
        $pdf->SetTextColor($r, $g, $b);

        $posX = (float)$settings['pos_x'];
        $posY = (float)$settings['pos_y'];
        $align = $settings['text_align'] ?? 'L';
        $boxWidth = isset($settings['box_width']) ? (float)$settings['box_width'] : 0;
        $wrapMode = $settings['wrap_mode'] ?? 'wrap_words';

        if ($boxWidth > 0) {
            // Only scale font size down if explicitly set to 'shrink' mode
            if ($wrapMode === 'shrink') {
                $strWidth = $pdf->GetStringWidth($text);
                if ($strWidth > $boxWidth) {
                    $minFontSize = max(7.5, (float)($settings['min_font_size'] ?? 8));
                    $scaledFontSize = floor(($fontSize * ($boxWidth / $strWidth) * 0.97) * 10) / 10;
                    $pdf->SetFont($fontName, '', max($minFontSize, $scaledFontSize));
                }
            }

            $pdf->SetXY($posX, $posY);
            $pdf->MultiCell($boxWidth, 0, $text, 0, $align, false, 1);
            if ($linkUrl !== '') {
                $pdf->Link($posX, $posY, $boxWidth, max(5, $pdf->GetY() - $posY), $linkUrl);
            }
        } else {
            $strWidth = $pdf->GetStringWidth($text);
            if ($align === 'C') {
                $pdf->SetXY($posX - ($strWidth / 2), $posY);
            } elseif ($align === 'R') {
                $pdf->SetXY($posX - $strWidth, $posY);
            } else {
                $pdf->SetXY($posX, $posY);
            }
            $pdf->Cell($strWidth, 0, $text, 0, 0, 'L', false, $linkUrl);
        }
    }
}
